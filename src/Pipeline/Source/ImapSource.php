<?php

namespace Laika\Mailman\Pipeline\Source;

use Laika\Mailman\Interfaces\MessageSourceInterface;
use Laika\Mailman\Pipeline\Pipeline;
use Laika\Mailman\Reader\ImapReader;
use Laika\Mailman\Message;

/**
 * Polls a mailbox over IMAP. The default source for a cron-driven pipeline.
 *
 * IMAP is the only source that can honour every post-action, because it is
 * the only one with server-side flags and folders.
 */
class ImapSource implements MessageSourceInterface
{
    protected ImapReader $reader;
    protected array $criteria;
    protected bool $connected = false;

    /**
     * Defaults to unseen mail, which pairs with the pipeline's default
     * MARK_SEEN post-action to give idempotency with no bookkeeping at all —
     * the mailbox itself is the state.
     */
    public function __construct(ImapReader $reader, array $criteria = ['unseen' => true])
    {
        $this->reader = $reader;
        $this->criteria = $criteria;
    }

    public function messages(): iterable
    {
        $this->ensureConnected();

        // Search first, then fetch one at a time. Fetching the whole result
        // set up front would hold an entire support inbox in memory.
        foreach ($this->reader->search($this->criteria) as $uid) {
            $message = $this->reader->fetch($uid);

            if ($message !== null) {
                yield $message;
            }
        }
    }

    public function complete(Message $message, string $action, string $folder = ''): void
    {
        $uid = $message->uid;

        if ($uid === null) {
            return;
        }

        match ($action) {
            // Mark seen before moving: if someone later drags the message
            // back to the inbox, it stays out of an unseen-only search
            // instead of being reprocessed.
            Pipeline::MOVE => $this->markThenMove($uid, $folder),
            Pipeline::MARK_SEEN => $this->reader->markSeen($uid),
            Pipeline::DELETE => $this->reader->delete($uid),
            default => false,
        };
    }

    public function finish(): void
    {
        // \Deleted only becomes a real deletion at EXPUNGE. Doing it once at
        // the end of the run rather than per message keeps the round trips
        // down and makes the whole run cancellable up to this point.
        $this->reader->expunge();
    }

    public function describe(): string
    {
        return 'IMAP ' . ($this->reader->mailbox()?->name ?? 'INBOX');
    }

    protected function markThenMove(int|string $uid, string $folder): bool
    {
        $this->reader->markSeen($uid);

        return $this->reader->move($uid, $folder);
    }

    protected function ensureConnected(): void
    {
        if (!$this->connected) {
            $this->reader->connect();
            $this->connected = true;
        }
    }
}
