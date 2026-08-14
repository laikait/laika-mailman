<?php

namespace Laika\Mailman;

/**
 * An IMAP folder and its counts. POP3 has no concept of folders at all, so
 * this only ever comes back from ImapReader.
 */
class Mailbox
{
    public function __construct(
        public readonly string $name,
        public readonly string $delimiter = '/',
        public readonly array $flags = [],
        public readonly int $messageCount = 0,
        public readonly int $recentCount = 0,
        public readonly int $unseenCount = 0,
        public readonly int $uidValidity = 0,
        public readonly int $uidNext = 0
    ) {
    }

    /**
     * \Noselect folders exist purely as parents in the hierarchy (Gmail's
     * "[Gmail]" is the usual example) — SELECT on them fails, so skip them
     * when walking mailboxes().
     */
    public function isSelectable(): bool
    {
        foreach ($this->flags as $flag) {
            if (strcasecmp($flag, '\Noselect') === 0) {
                return false;
            }
        }

        return true;
    }
}
