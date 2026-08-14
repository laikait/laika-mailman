<?php

namespace Laika\Mailman;

/**
 * A parsed inbound message. Everything here is already decoded to UTF-8 by
 * MimeParser — headers are RFC 2047 unwrapped, bodies are base64 /
 * quoted-printable decoded and charset-converted.
 *
 * Address fields are arrays of ['email' => ..., 'name' => ...]. $from is a
 * single such array (or null), because a message has exactly one From in
 * every practical case.
 */
class Message
{
    public function __construct(
        public readonly int|string|null $uid = null,
        public readonly string $messageId = '',
        public readonly string $subject = '',
        public readonly ?array $from = null,
        public readonly array $to = [],
        public readonly array $cc = [],
        public readonly array $bcc = [],
        public readonly array $replyTo = [],
        public readonly ?\DateTimeImmutable $date = null,
        public readonly string $textBody = '',
        public readonly string $htmlBody = '',
        public readonly array $attachments = [],
        public readonly array $flags = [],
        public readonly int $size = 0,
        public readonly array $headers = [],
        public readonly string $raw = ''
    ) {
    }

    public function hasAttachments(): bool
    {
        return $this->attachments !== [];
    }

    public function isSeen(): bool
    {
        return $this->hasFlag('\Seen');
    }

    public function hasFlag(string $flag): bool
    {
        foreach ($this->flags as $set) {
            if (strcasecmp($set, $flag) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The body you'd actually display: HTML when the sender provided it,
     * otherwise the plaintext alternative. Note this is deliberately *not*
     * sanitized — inbound HTML is attacker-controlled, so run it through your
     * own sanitizer before putting it in a page.
     */
    public function body(): string
    {
        return $this->htmlBody !== '' ? $this->htmlBody : $this->textBody;
    }

    /** Case-insensitive header lookup; returns the first value if repeated. */
    public function header(string $name): ?string
    {
        $value = $this->headers[strtolower($name)] ?? null;

        if (is_array($value)) {
            return $value[0] ?? null;
        }

        return $value;
    }

    /** Only the non-inline attachments — i.e. real files, not cid: images. */
    public function files(): array
    {
        return array_values(array_filter(
            $this->attachments,
            static fn (Attachment $a): bool => !$a->isInline()
        ));
    }

    public function fromAddress(): string
    {
        return $this->from['email'] ?? '';
    }
}
