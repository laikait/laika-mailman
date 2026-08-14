<?php

namespace Laika\Mailman\Pipeline;

/**
 * The built-in extraction rules.
 *
 * These are a starting point, not a standard. Every one can be replaced by
 * registering an extractor of the same name, and the whole set can be dropped
 * with Pipeline::withoutPresets().
 *
 * Each pattern uses a single capture group and a non-capturing alternation
 * for the prefix, so one rule covers every spelling of the same thing:
 * "[#1234]", "[TICKET-1234]", "TKT-1234" and "Ticket #1234" all yield "1234".
 *
 * The separator class [\s#:_-]{0,3} is what does most of the work — real
 * subject lines separate the label from the number with almost any
 * punctuation, or nothing at all.
 */
class Presets
{
    /** @return Extractor[] */
    public static function all(): array
    {
        return [
            self::ticket(),
            self::invoice(),
            self::order(),
            self::reference(),
        ];
    }

    /**
     * [#1234] · [TICKET-1234] · TKT-1234 · Ticket #1234 · CASE 1234
     *
     * \d+ rather than \d{2,}: ticket #7 is a perfectly ordinary id, and a
     * rule that silently skips the first nine tickets a system ever issues
     * is worse than the occasional false positive. The explicit TICKET/TKT/
     * CASE prefix is what keeps this from firing on stray digits.
     */
    public static function ticket(): Extractor
    {
        return new Extractor(
            'ticket',
            '/(?:\[\s*#\s*|\b(?:TICKET|TKT|CASE)[\s#:_-]{0,3})(\d+)/i',
            [Extractor::SUBJECT, Extractor::BODY, Extractor::HEADERS, Extractor::RECIPIENTS]
        );
    }

    /**
     * INV-2024-0087 · Invoice #1234 · INV#1234
     *
     * The (?:-\d+)* tail is what keeps segmented numbers whole — without it
     * "INV-2024-0087" extracts as "2024" and silently files against the wrong
     * record.
     */
    public static function invoice(): Extractor
    {
        return new Extractor(
            'invoice',
            '/\bINV(?:OICE)?[\s#:_-]{0,3}(\d+(?:-\d+)*)/i',
            [Extractor::SUBJECT, Extractor::BODY, Extractor::HEADERS, Extractor::RECIPIENTS]
        );
    }

    /** Order #1234 · ORD-1234 · PO-1234 */
    public static function order(): Extractor
    {
        return new Extractor(
            'order',
            '/\b(?:ORD(?:ER)?|PO)[\s#:_-]{0,3}(\d+(?:-\d+)*)/i',
            [Extractor::SUBJECT, Extractor::BODY, Extractor::HEADERS, Extractor::RECIPIENTS]
        );
    }

    /**
     * Ref: ABC-123 · Reference: 12345
     *
     * The lookahead requiring at least one digit is deliberate: without it
     * "Ref: the attached document" extracts "the", and a reference rule that
     * fires on ordinary prose is worse than no rule at all.
     */
    public static function reference(): Extractor
    {
        return new Extractor(
            'reference',
            '/\bREF(?:ERENCE)?[\s#:_-]{0,3}((?=[A-Za-z0-9-]*\d)[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*)/i',
            [Extractor::SUBJECT, Extractor::HEADERS, Extractor::RECIPIENTS]
        );
    }
}
