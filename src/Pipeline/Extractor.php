<?php

namespace Laika\Mailman\Pipeline;

use Laika\Mailman\Exceptions\MailmanException;
use Laika\Mailman\Message;

/**
 * One named extraction rule: a regex, plus the parts of a message it is
 * allowed to match in.
 *
 * The scope is the important half. An invoice pattern loose enough to catch
 * "INV-2024-0087" will also happily match a reference number in a signature
 * block or a phone extension in a footer, so restricting a rule to the
 * subject line — where identifiers are conventionally placed — is usually
 * more accurate than tightening the pattern.
 */
class Extractor
{
    /** The Subject header, decoded. */
    public const SUBJECT = 'subject';

    /** Text and HTML bodies, quoted history stripped unless told otherwise. */
    public const BODY = 'body';

    /** Custom X-* headers, e.g. X-Ticket-ID. */
    public const HEADERS = 'headers';

    /** Recipient addresses, for plus-addressing (support+ticket-99@…). */
    public const RECIPIENTS = 'recipients';

    protected string $name;
    protected string $pattern;
    protected array $scope;
    protected int $group;

    public function __construct(string $name, string $pattern, array $scope = [self::SUBJECT, self::BODY], int $group = 1)
    {
        // Validate now rather than at match time. A malformed pattern that
        // simply never matches is far worse than a loud failure — the
        // pipeline would run clean and silently extract nothing, and you'd
        // have no reason to suspect the rule.
        if (@preg_match($pattern, '') === false) {
            throw new MailmanException(
                "Extractor '{$name}' has an invalid pattern: {$pattern} — " . (preg_last_error_msg() ?: 'check the delimiters')
            );
        }

        $this->name = $name;
        $this->pattern = $pattern;
        $this->scope = $scope === [] ? [self::SUBJECT, self::BODY] : $scope;
        $this->group = $group;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function pattern(): string
    {
        return $this->pattern;
    }

    public function appliesTo(string $scope): bool
    {
        return in_array($scope, $this->scope, true);
    }

    public function scope(): array
    {
        return $this->scope;
    }

    /**
     * Every distinct capture in $text, in the order found.
     *
     * Deduplicated because the same ticket id routinely appears in both the
     * subject and the first line of the body, and a caller asking for all()
     * wants distinct identifiers rather than a count of mentions.
     */
    public function match(string $text): array
    {
        if (trim($text) === '' || preg_match_all($this->pattern, $text, $matches) === false) {
            return [];
        }

        $captures = $matches[$this->group] ?? [];

        $found = [];

        foreach ($captures as $capture) {
            $capture = trim((string) $capture);

            if ($capture !== '' && !in_array($capture, $found, true)) {
                $found[] = $capture;
            }
        }

        return $found;
    }

    /**
     * Convenience for scanning a whole Message against this rule in one call,
     * used by Pipeline::scan(). Body text is passed in already-stripped, so
     * this takes the pre-resolved parts rather than re-deriving them.
     *
     * @param  array<string,string> $parts scope => text
     * @return array<string,array>  scope => captures
     */
    public function matchParts(array $parts): array
    {
        $results = [];

        foreach ($parts as $scope => $text) {
            if (!$this->appliesTo($scope)) {
                continue;
            }

            $captures = $this->match($text);

            if ($captures !== []) {
                $results[$scope] = $captures;
            }
        }

        return $results;
    }
}
