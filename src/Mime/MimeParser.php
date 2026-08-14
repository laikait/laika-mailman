<?php

namespace Laika\Mailman\Mime;

use Laika\Mailman\Attachment;
use Laika\Mailman\Message;

/**
 * Turns a raw RFC 2822 message into a Message. Stateless — everything is
 * static, because parsing a message needs no configuration and no connection.
 *
 * Shared by ImapReader and Pop3Reader: both hand over exactly the same thing
 * (the complete message source), they just fetch it differently.
 *
 * Two traps this deals with that are easy to get wrong:
 *
 *   1. Header *names* are case-insensitive, but parameter *values* are not.
 *      A boundary of "AbC" will not match "abc", so boundaries are compared
 *      byte-for-byte while header lookups are lowercased.
 *   2. A Content-Type with no charset means US-ASCII, not UTF-8. Assuming
 *      UTF-8 there silently mangles latin-1 mail, which is still common from
 *      older systems, so the default is spelled out rather than inferred.
 */
class MimeParser
{
    /**
     * $uid, $flags and $size come from the transport (IMAP knows them, POP3
     * mostly doesn't) — they aren't in the message source itself.
     */
    public static function parseMessage(string $raw, int|string|null $uid = null, array $flags = [], int $size = 0): Message
    {
        [$headerBlock, $body] = self::splitHeadersAndBody($raw);
        $headers = self::parseHeaders($headerBlock);

        $collected = ['text' => '', 'html' => '', 'attachments' => []];
        self::walk($headers, $body, $collected);

        return new Message(
            uid: $uid,
            messageId: trim(self::firstHeader($headers, 'message-id'), '<> '),
            subject: self::decodeHeader(self::firstHeader($headers, 'subject')),
            from: self::parseAddressList(self::firstHeader($headers, 'from'))[0] ?? null,
            to: self::parseAddressList(self::firstHeader($headers, 'to')),
            cc: self::parseAddressList(self::firstHeader($headers, 'cc')),
            bcc: self::parseAddressList(self::firstHeader($headers, 'bcc')),
            replyTo: self::parseAddressList(self::firstHeader($headers, 'reply-to')),
            date: self::parseDate(self::firstHeader($headers, 'date')),
            textBody: $collected['text'],
            htmlBody: $collected['html'],
            attachments: $collected['attachments'],
            flags: $flags,
            size: $size > 0 ? $size : strlen($raw),
            headers: $headers,
            raw: $raw
        );
    }

    /**
     * The header block ends at the first blank line. Tolerates bare-LF
     * messages (no CR) because plenty of servers and mbox exports produce
     * them, even though RFC 2822 requires CRLF.
     */
    public static function splitHeadersAndBody(string $raw): array
    {
        $split = preg_split('/\r?\n\r?\n/', $raw, 2);

        return [$split[0] ?? '', $split[1] ?? ''];
    }

    /**
     * Lowercased keys. A header appearing more than once (Received, and
     * legitimately Reply-To) becomes an array rather than clobbering the
     * earlier value — losing all but the last Received breaks any kind of
     * delivery-path inspection.
     */
    public static function parseHeaders(string $block): array
    {
        // Unfold first: RFC 2822 continuation lines begin with SP or HTAB and
        // are part of the header above them.
        $block = preg_replace('/\r?\n[ \t]+/', ' ', $block) ?? '';
        $headers = [];

        foreach (preg_split('/\r?\n/', $block) ?: [] as $line) {
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $name = strtolower(trim($name));
            $value = trim($value);

            if (!isset($headers[$name])) {
                $headers[$name] = $value;
                continue;
            }

            if (!is_array($headers[$name])) {
                $headers[$name] = [$headers[$name]];
            }

            $headers[$name][] = $value;
        }

        return $headers;
    }

    /**
     * RFC 2047 encoded words: =?charset?B?base64?= and =?charset?Q?qp?=.
     *
     * Whitespace *between* two encoded words is a separator, not content, and
     * must be dropped — otherwise a subject split across two words gains a
     * stray space in the middle. Whitespace between an encoded word and plain
     * text is real and is kept.
     */
    public static function decodeHeader(string $value): string
    {
        if ($value === '') {
            return '';
        }

        // Collapse the separator between adjacent encoded words up front, so
        // the callback below never has to reason about it.
        $value = preg_replace('/\?=[ \t]+=\?/', '?==?', $value) ?? $value;

        $decoded = preg_replace_callback(
            '/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/',
            static function (array $m): string {
                $charset = $m[1];
                $text = strtoupper($m[2]) === 'B'
                    ? (base64_decode($m[3], false) ?: '')
                    // In a Q-encoded *header*, "_" means space — a difference
                    // from body quoted-printable that quoted_printable_decode()
                    // does not know about.
                    : quoted_printable_decode(str_replace('_', ' ', $m[3]));

                return self::toUtf8($text, $charset);
            },
            $value
        );

        return $decoded ?? $value;
    }

    public static function decodeBody(string $body, string $encoding): string
    {
        return match (strtolower(trim($encoding))) {
            'base64' => base64_decode($body, false) ?: '',
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }

    /**
     * Splits "text/plain; charset=utf-8; name=x.txt" into a lowercased type
     * and a parameter map. Handles RFC 2231 both ways: split parameters
     * (name*0, name*1) and extended ones (name*=utf-8''%41).
     */
    public static function parseContentType(string $value): array
    {
        $parts = self::splitParameters($value);
        $type = strtolower(trim(array_shift($parts) ?? 'text/plain'));

        return [$type === '' ? 'text/plain' : $type, self::parseParameters($parts)];
    }

    public static function parseAddressList(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }

        $addresses = [];

        foreach (self::splitAddressList($value) as $chunk) {
            $chunk = trim($chunk);

            if ($chunk === '') {
                continue;
            }

            // "Display Name" <a@b.com> — the angle brackets are authoritative
            // when present, since a display name may itself contain an @.
            if (preg_match('/^(.*)<([^>]*)>\s*$/', $chunk, $m)) {
                $name = trim(trim($m[1]), '"\' ');
                $email = trim($m[2]);
            } else {
                $name = '';
                $email = $chunk;
            }

            $addresses[] = [
                'email' => $email,
                'name' => self::decodeHeader($name),
            ];
        }

        return $addresses;
    }

    public static function parseDate(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        // Real-world Date headers routinely carry a trailing "(GMT)" style
        // comment that DateTimeImmutable refuses to parse. Strip it.
        $value = trim(preg_replace('/\s*\([^)]*\)\s*$/', '', $value) ?? $value);

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Recursive descent over the MIME tree, accumulating into $collected.
     *
     * By reference rather than by return value because a message is a tree
     * but the result is flat — one text body, one HTML body, and a single
     * list of attachments no matter how deeply nested the multiparts are.
     */
    protected static function walk(array $headers, string $body, array &$collected, int $depth = 0): void
    {
        // Malformed or hostile mail can nest multiparts arbitrarily deep;
        // stop rather than exhaust the stack.
        if ($depth > 20) {
            return;
        }

        [$type, $params] = self::parseContentType(self::firstHeader($headers, 'content-type', 'text/plain'));
        $disposition = self::firstHeader($headers, 'content-disposition');
        [$dispositionType, $dispositionParams] = self::parseContentType($disposition !== '' ? $disposition : 'inline');

        if (str_starts_with($type, 'multipart/') && isset($params['boundary'])) {
            foreach (self::splitMultipart($body, $params['boundary']) as $part) {
                [$partHeaders, $partBody] = self::splitHeadersAndBody($part);
                self::walk(self::parseHeaders($partHeaders), $partBody, $collected, $depth + 1);
            }

            return;
        }

        $encoding = self::firstHeader($headers, 'content-transfer-encoding', '7bit');
        $content = self::decodeBody($body, $encoding);
        $filename = $dispositionParams['filename'] ?? $params['name'] ?? null;

        // An attachment is anything explicitly dispositioned as one, anything
        // carrying a filename, or an embedded message. Everything else that
        // is text becomes a body.
        $isAttachment = strtolower($dispositionType) === 'attachment'
            || $filename !== null
            || str_starts_with($type, 'message/');

        if (!$isAttachment && ($type === 'text/plain' || $type === 'text/html')) {
            // Default charset is US-ASCII per RFC 2045, NOT UTF-8. Getting
            // this wrong mangles legacy latin-1 mail.
            $text = self::toUtf8($content, $params['charset'] ?? 'us-ascii');
            $key = $type === 'text/html' ? 'html' : 'text';

            // Concatenate rather than overwrite: a multipart/mixed can carry
            // several text parts (body, then a plaintext footer) and dropping
            // all but the last loses content.
            $collected[$key] .= ($collected[$key] === '' ? '' : "\n") . $text;

            return;
        }

        if ($content === '' && $filename === null) {
            return;
        }

        $contentId = self::firstHeader($headers, 'content-id');

        $collected['attachments'][] = new Attachment(
            filename: self::decodeHeader($filename ?? 'attachment.bin'),
            mimeType: $type,
            size: strlen($content),
            content: $content,
            contentId: $contentId !== '' ? trim($contentId, '<> ') : null,
            disposition: strtolower($dispositionType) === 'attachment' ? 'attachment' : 'inline'
        );
    }

    /**
     * Splits a multipart body on its boundary. The delimiter is "--boundary"
     * at the start of a line and the terminator is "--boundary--"; anything
     * before the first delimiter is the preamble (usually the "this is a
     * MIME message" note for ancient clients) and is discarded.
     */
    protected static function splitMultipart(string $body, string $boundary): array
    {
        $delimiter = '--' . $boundary;
        $parts = [];
        $lines = preg_split('/\r?\n/', $body) ?: [];
        $current = null;

        foreach ($lines as $line) {
            $trimmed = rtrim($line);

            if ($trimmed === $delimiter) {
                if ($current !== null) {
                    $parts[] = implode("\r\n", $current);
                }

                $current = [];
                continue;
            }

            if ($trimmed === $delimiter . '--') {
                if ($current !== null) {
                    $parts[] = implode("\r\n", $current);
                }

                $current = null;
                break;
            }

            if ($current !== null) {
                $current[] = $line;
            }
        }

        // No closing delimiter (truncated message) — keep what we have rather
        // than throwing the whole part away.
        if ($current !== null) {
            $parts[] = implode("\r\n", $current);
        }

        return $parts;
    }

    /**
     * Semicolon split that ignores semicolons inside double quotes, so
     * name="a;b.txt" survives intact.
     */
    protected static function splitParameters(string $value): array
    {
        $parts = [];
        $buffer = '';
        $inQuotes = false;
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];

            if ($char === '"' && ($i === 0 || $value[$i - 1] !== '\\')) {
                $inQuotes = !$inQuotes;
                continue;
            }

            if ($char === ';' && !$inQuotes) {
                $parts[] = $buffer;
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $parts[] = $buffer;

        return $parts;
    }

    protected static function parseParameters(array $parts): array
    {
        $params = [];
        $continued = [];

        foreach ($parts as $part) {
            if (!str_contains($part, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $part, 2);
            $key = strtolower(trim($key));
            $value = trim(trim($value), '"');

            // RFC 2231 extended: filename*=utf-8''%E2%82%AC.txt — charset and
            // language prefix the percent-encoded value.
            if (str_ends_with($key, '*') && !preg_match('/\*\d+\*?$/', $key)) {
                $key = rtrim($key, '*');
                $params[$key] = self::decodeExtendedParameter($value);
                continue;
            }

            // RFC 2231 continuation: filename*0="long"; filename*1="er.txt"
            if (preg_match('/^(.+)\*(\d+)\*?$/', $key, $m)) {
                $continued[$m[1]][(int) $m[2]] = str_starts_with($part, $m[1] . '*' . $m[2] . '*')
                    ? rawurldecode($value)
                    : $value;
                continue;
            }

            $params[$key] = $value;
        }

        foreach ($continued as $key => $segments) {
            ksort($segments);
            $joined = implode('', $segments);
            // A continued value may still carry the charset''  prefix on its
            // first segment.
            $params[$key] = str_contains($joined, "''") ? self::decodeExtendedParameter($joined) : $joined;
        }

        return $params;
    }

    protected static function decodeExtendedParameter(string $value): string
    {
        if (substr_count($value, "'") >= 2) {
            [$charset, , $encoded] = array_pad(explode("'", $value, 3), 3, '');

            return self::toUtf8(rawurldecode($encoded), $charset ?: 'utf-8');
        }

        return rawurldecode($value);
    }

    /**
     * Comma split that respects quotes and angle brackets, so
     * "Doe, John" <j@d.com>, a@b.com yields two addresses rather than three.
     */
    protected static function splitAddressList(string $value): array
    {
        $addresses = [];
        $buffer = '';
        $inQuotes = false;
        $inAngles = false;
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];

            if ($char === '"' && ($i === 0 || $value[$i - 1] !== '\\')) {
                $inQuotes = !$inQuotes;
            } elseif ($char === '<' && !$inQuotes) {
                $inAngles = true;
            } elseif ($char === '>' && !$inQuotes) {
                $inAngles = false;
            } elseif ($char === ',' && !$inQuotes && !$inAngles) {
                $addresses[] = $buffer;
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $addresses[] = $buffer;

        return $addresses;
    }

    protected static function firstHeader(array $headers, string $name, string $default = ''): string
    {
        $value = $headers[$name] ?? $default;

        if (is_array($value)) {
            return (string) ($value[0] ?? $default);
        }

        return (string) $value;
    }

    /**
     * Charset conversion that never throws and never returns empty. A message
     * declaring a charset PHP has never heard of is still worth showing as
     * best-effort text — dropping it entirely is the worse failure.
     */
    protected static function toUtf8(string $text, string $charset): string
    {
        $charset = trim($charset, "\"' \t");

        if ($charset === '' || preg_match('/^utf-?8$/i', $charset)) {
            return $text;
        }

        try {
            $converted = @mb_convert_encoding($text, 'UTF-8', $charset);
        } catch (\Throwable $e) {
            $converted = false;
        }

        if (is_string($converted) && $converted !== '') {
            return $converted;
        }

        // Unknown charset: assume latin-1, which at least never produces
        // invalid UTF-8 (every byte 0x00-0xFF maps to a codepoint).
        return mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
    }
}
