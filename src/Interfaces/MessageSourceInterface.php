<?php

namespace Laika\Mailman\Interfaces;

use Laika\Mailman\Message;

/**
 * Where a Pipeline gets its messages from.
 *
 * The two delivery models in the wild — polling a mailbox on a schedule, and
 * having the MTA pipe a message to a script on STDIN — differ only in how the
 * bytes arrive. Putting that behind an interface means the same extraction
 * rules and handlers run either way, so you can develop against IMAP and
 * deploy behind a real mail pipe without rewriting anything.
 */
interface MessageSourceInterface
{
    /**
     * @return iterable<Message>
     *
     * Deliberately iterable rather than array: ImapSource yields one message
     * per UID so a 5,000-message support inbox is never held in memory at
     * once.
     */
    public function messages(): iterable;

    /**
     * Applied only after every handler for a message succeeded — that is what
     * makes a failed run retryable, since an untouched message is picked up
     * again next time.
     *
     * This lives on the source because only the source knows how: IMAP has
     * flags and folders, POP3 has DELE and nothing else, STDIN has no mailbox
     * to act on at all. A source that cannot honour an action no-ops rather
     * than throwing mid-run.
     */
    public function complete(Message $message, string $action, string $folder = ''): void;

    /**
     * Called once when a run finishes, for anything that has to be committed
     * in a batch — POP3 in particular only applies deletions on QUIT.
     */
    public function finish(): void;

    /** Short description for log lines, e.g. "IMAP INBOX at imap.gmail.com". */
    public function describe(): string;
}
