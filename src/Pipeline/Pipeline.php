<?php

namespace Laika\Mailman\Pipeline;

use Laika\Mailman\Interfaces\MessageSourceInterface;
use Laika\Mailman\Exceptions\MailmanException;
use Laika\Mailman\Message;

/**
 * Scans incoming mail for identifiers and routes it to your handlers.
 *
 * The job this exists for: a support inbox where a reply carrying
 * "[TICKET-1234]" must append to ticket 1234 rather than open a new one, or a
 * billing inbox where "INV-2024-0087" must attach to that invoice. Extraction
 * rules and handlers are registered once; the source decides whether messages
 * arrive by polling IMAP or from an MTA pipe on STDIN.
 *
 * Retry behaviour is the reason the post-action is applied *after* handlers
 * rather than on sight: a message whose handler threw is left untouched in
 * the mailbox, so the next run picks it up again. Marking mail seen the
 * moment it is read would silently drop anything that failed.
 */
class Pipeline
{
    /** Mark \Seen (IMAP only). The default — cheap, reversible, no storage. */
    public const MARK_SEEN = 'seen';

    /** Move to another folder (IMAP only). Also marks \Seen first. */
    public const MOVE = 'move';

    /** Mark deleted. Committed by the source's finish(). */
    public const DELETE = 'delete';

    /** Do nothing — you are tracking processed mail yourself. */
    public const LEAVE = 'leave';

    /** @var Extractor[] keyed by name, so a preset can be replaced by re-registering it */
    protected array $extractors = [];

    /** @var array<int,array{condition:callable,handler:callable}> */
    protected array $handlers = [];

    /** @var callable[] */
    protected array $always = [];

    protected mixed $fallback = null;
    protected mixed $errorHandler = null;

    protected string $action = self::MARK_SEEN;
    protected string $actionFolder = '';
    protected bool $scanQuoted = false;
    protected int $limit = 0;
    protected bool $usePresets = true;

    /**
     * Presets are registered lazily on first use rather than in the
     * constructor, so withoutPresets() and a same-named extract() can both
     * override them regardless of call order.
     */
    public function __construct(array $extractors = [])
    {
        foreach ($extractors as $extractor) {
            $this->addExtractor($extractor);
        }
    }

    /**
     * Register a rule. Re-using a name replaces that rule, which is how a
     * preset gets overridden:
     *
     *   $pipeline->extract('ticket', '/\bSUP-(\d+)/i', [Extractor::SUBJECT]);
     */
    public function extract(string $name, string $pattern, array $scope = [], int $group = 1): static
    {
        return $this->addExtractor(new Extractor($name, $pattern, $scope, $group));
    }

    public function addExtractor(Extractor $extractor): static
    {
        $this->extractors[$extractor->name()] = $extractor;

        return $this;
    }

    /** Drop the built-in ticket/invoice/order/reference rules. */
    public function withoutPresets(): static
    {
        $this->usePresets = false;

        return $this;
    }

    /**
     * Scan quoted reply history too. Off by default — see stripQuoted() for
     * why that default is what it is.
     */
    public function scanQuotedText(bool $scan = true): static
    {
        $this->scanQuoted = $scan;

        return $this;
    }

    /**
     * $condition receives the ScanResult and returns bool; $handler receives
     * the same ScanResult. Every matching handler runs, not just the first.
     */
    public function on(callable $condition, callable $handler): static
    {
        $this->handlers[] = ['condition' => $condition, 'handler' => $handler];

        return $this;
    }

    /** Convenience for the common "has this identifier" condition. */
    public function onIdentifier(string $name, callable $handler): static
    {
        return $this->on(static fn (ScanResult $r): bool => $r->has($name), $handler);
    }

    /** Runs for every message, matched or not. Good for logging and archiving. */
    public function always(callable $handler): static
    {
        $this->always[] = $handler;

        return $this;
    }

    /** Runs only when no on() condition matched — "this is a new enquiry". */
    public function otherwise(callable $handler): static
    {
        $this->fallback = $handler;

        return $this;
    }

    /** handler(\Throwable $e, ?Message $message). */
    public function onError(callable $handler): static
    {
        $this->errorHandler = $handler;

        return $this;
    }

    public function afterProcessing(string $action, string $folder = ''): static
    {
        if (!in_array($action, [self::MARK_SEEN, self::MOVE, self::DELETE, self::LEAVE], true)) {
            throw new MailmanException("Unknown post-processing action: {$action}");
        }

        if ($action === self::MOVE && trim($folder) === '') {
            throw new MailmanException('Pipeline::MOVE needs a destination folder.');
        }

        $this->action = $action;
        $this->actionFolder = $folder;

        return $this;
    }

    /** Stop after $max messages in a run. 0 means no limit. */
    public function limit(int $max): static
    {
        $this->limit = max(0, $max);

        return $this;
    }

    /**
     * Extraction only — no handlers, no side effects, no source needed.
     *
     * Public and pure on purpose: it makes extraction rules testable against
     * a fixture message without a mailbox, which is where most of the value
     * of testing this lives.
     */
    public function scan(Message $message): ScanResult
    {
        $parts = $this->messageParts($message);
        $tags = $this->plusAddressTags($message);

        $identifiers = [];
        $sources = [];

        foreach ($this->activeExtractors() as $name => $extractor) {
            // Collected per tier so precedence can be applied afterwards,
            // rather than depending on the order the tiers happen to be
            // scanned in.
            $byTier = [
                ScanResult::HEADER => $this->headerIdentifier($message, $name, $extractor),
                ScanResult::RECIPIENT => $extractor->appliesTo(Extractor::RECIPIENTS) ? $extractor->match(implode("\n", $tags)) : [],
                ScanResult::SUBJECT => $extractor->appliesTo(Extractor::SUBJECT) ? $extractor->match($parts['subject']) : [],
                ScanResult::BODY => $extractor->appliesTo(Extractor::BODY) ? $extractor->match($parts['body']) : [],
            ];

            $ordered = [];

            foreach (ScanResult::PRECEDENCE as $tier) {
                foreach ($byTier[$tier] as $value) {
                    if (!in_array($value, $ordered, true)) {
                        $ordered[] = $value;

                        // The tier of the *first* accepted value is the one
                        // reported, since that is what first() returns.
                        $sources[$name] ??= $tier;
                    }
                }
            }

            if ($ordered !== []) {
                $identifiers[$name] = $ordered;
            }
        }

        return new ScanResult(
            message: $message,
            identifiers: $identifiers,
            sources: $sources,
            tags: $tags,
            threadIds: $this->threadIds($message)
        );
    }

    /**
     * Drain a source, scanning and dispatching each message.
     *
     * @return array{scanned:int,handled:int,unmatched:int,failed:int}
     */
    public function run(MessageSourceInterface $source): array
    {
        $stats = ['scanned' => 0, 'handled' => 0, 'unmatched' => 0, 'failed' => 0];

        foreach ($source->messages() as $message) {
            if ($this->limit > 0 && $stats['scanned'] >= $this->limit) {
                break;
            }

            $stats['scanned']++;

            try {
                $result = $this->scan($message);
                $matched = $this->dispatch($result);

                $matched ? $stats['handled']++ : $stats['unmatched']++;

                // Only now, and only because nothing threw. A failed message
                // stays exactly as it was so the next run retries it.
                $source->complete($message, $this->action, $this->actionFolder);
            } catch (\Throwable $e) {
                $stats['failed']++;
                $this->reportError($e, $message);
            }
        }

        try {
            $source->finish();
        } catch (\Throwable $e) {
            $this->reportError($e, null);
        }

        return $stats;
    }

    /** Scan and dispatch one message you already hold. Returns whether anything matched. */
    public function process(Message $message): ScanResult
    {
        $result = $this->scan($message);
        $this->dispatch($result);

        return $result;
    }

    /** @return bool whether any on() condition matched */
    protected function dispatch(ScanResult $result): bool
    {
        $matched = false;

        foreach ($this->handlers as $entry) {
            if (($entry['condition'])($result)) {
                $matched = true;
                ($entry['handler'])($result);
            }
        }

        if (!$matched && $this->fallback !== null) {
            ($this->fallback)($result);
        }

        foreach ($this->always as $handler) {
            $handler($result);
        }

        return $matched;
    }

    /** @return array<string,Extractor> */
    protected function activeExtractors(): array
    {
        if (!$this->usePresets) {
            return $this->extractors;
        }

        $presets = [];

        foreach (Presets::all() as $preset) {
            $presets[$preset->name()] = $preset;
        }

        // Explicit registrations win over same-named presets.
        return array_merge($presets, $this->extractors);
    }

    /**
     * Conventional headers are read verbatim rather than regex-matched.
     *
     * X-Ticket-ID: 5150 carries the bare identifier, with none of the
     * surrounding "TICKET-" text a subject-line pattern keys off — so
     * applying the pattern here would find nothing. If you set the header,
     * you meant the value, and that is exactly why this tier outranks the
     * others.
     */
    protected function headerIdentifier(Message $message, string $name, Extractor $extractor): array
    {
        if (!$extractor->appliesTo(Extractor::HEADERS)) {
            return [];
        }

        foreach (["x-{$name}-id", "x-{$name}", "x-{$name}-number", "x-{$name}-ref"] as $header) {
            $value = trim((string) $message->header($header));

            if ($value !== '') {
                return [$value];
            }
        }

        return [];
    }

    /**
     * Plus-addressing (RFC 5233 sub-addressing): support+ticket-1234@example.com
     * yields the tag "ticket-1234".
     *
     * This is the most reliable signal available short of a custom header,
     * because it is generated by your own outbound Reply-To rather than typed
     * by a human — and unlike a subject tag, a mail client cannot strip it
     * without breaking delivery.
     */
    protected function plusAddressTags(Message $message): array
    {
        $addresses = [];

        foreach ([...$message->to, ...$message->cc, ...$message->bcc] as $entry) {
            $addresses[] = $entry['email'] ?? '';
        }

        // Delivered-To / X-Original-To survive forwarding, where To: often
        // still shows the alias the sender used rather than the real box.
        foreach (['delivered-to', 'x-original-to', 'x-forwarded-to'] as $header) {
            $value = $message->header($header);

            if ($value !== null) {
                $addresses[] = trim($value, '<> ');
            }
        }

        $tags = [];

        foreach ($addresses as $address) {
            if (!str_contains($address, '+') || !str_contains($address, '@')) {
                continue;
            }

            $local = substr($address, 0, strpos($address, '@'));
            $tag = substr($local, strpos($local, '+') + 1);

            if ($tag !== '' && !in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    /** Message-ID plus everything in In-Reply-To and References, angle brackets stripped. */
    protected function threadIds(Message $message): array
    {
        $ids = $message->messageId !== '' ? [$message->messageId] : [];

        foreach (['in-reply-to', 'references'] as $header) {
            $value = $message->header($header);

            if ($value === null) {
                continue;
            }

            // References is a space-separated list; In-Reply-To is normally
            // one id but is permitted to carry several.
            foreach (preg_split('/\s+/', trim($value)) ?: [] as $id) {
                $id = trim($id, '<> ');

                if ($id !== '' && !in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }

    /** @return array{subject:string,body:string} */
    protected function messageParts(Message $message): array
    {
        $body = $message->textBody !== '' ? $message->textBody : strip_tags($message->htmlBody);

        return [
            'subject' => $message->subject,
            'body' => $this->scanQuoted ? $body : $this->stripQuoted($body),
        ];
    }

    /**
     * Remove quoted reply history before body extraction.
     *
     * Without this, every reply re-matches every identifier ever mentioned in
     * the thread. A conversation that started as ticket 1111 and now mentions
     * ticket 2222 would yield both, and the pipeline would have no principled
     * way to choose — so it would file against the wrong one roughly half the
     * time. Cutting the history means the body tier only ever sees what this
     * message actually says.
     *
     * Overridable: the attribution formats below cover the mainstream clients,
     * but corporate mail systems invent their own.
     */
    protected function stripQuoted(string $text): string
    {
        $cutPatterns = [
            // "On Tue, 12 Aug 2025 at 10:30, Alice <a@b.com> wrote:"
            '/^\s*On\s.{0,200}?\bwrote:\s*$/mi',
            '/^\s*-{2,}\s*Original Message\s*-{2,}\s*$/mi',
            '/^\s*-{2,}\s*Forwarded message\s*-{2,}\s*$/mi',
            '/^\s*_{10,}\s*$/m',
            // Outlook's localised block header
            '/^\s*From:\s.+?\r?\nSent:\s/mi',
            // The conventional signature delimiter, RFC 3676 §4.3
            '/^-- $/m',
        ];

        $cut = strlen($text);

        foreach ($cutPatterns as $pattern) {
            if (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE) === 1) {
                $cut = min($cut, $m[0][1]);
            }
        }

        $text = substr($text, 0, $cut);

        // Whatever survived the cut may still contain interleaved quoting.
        $lines = array_filter(
            preg_split('/\r?\n/', $text) ?: [],
            static fn (string $line): bool => !str_starts_with(ltrim($line), '>')
        );

        return implode("\n", $lines);
    }

    protected function reportError(\Throwable $e, ?Message $message): void
    {
        if ($this->errorHandler !== null) {
            // A throwing error handler would take down the whole run, which
            // defeats the point of containing errors in the first place.
            try {
                ($this->errorHandler)($e, $message);

                return;
            } catch (\Throwable $inner) {
                $e = $inner;
            }
        }

        $subject = $message?->subject ?? 'unknown message';
        fwrite(STDERR, "[laika-mailman] pipeline error on '{$subject}': {$e->getMessage()}\n");
    }
}
