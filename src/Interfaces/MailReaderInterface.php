<?php

namespace Laika\Mailman\Interfaces;

use Laika\Mailman\Message;

/**
 * The intersection of what IMAP and POP3 can both honestly do.
 *
 * Folders, server-side search, and flags are deliberately absent: POP3 has no
 * such concepts, and faking them (a "search" that downloads every message and
 * filters client-side, a "markSeen" that silently does nothing) would make
 * the two readers look interchangeable when they are not. Those capabilities
 * live on ImapReader as concrete methods instead — depend on the concrete
 * type when you need them.
 */
interface MailReaderInterface
{
    public function connect(): void;

    public function disconnect(): void;

    /** Messages in the current mailbox. */
    public function count(): int;

    /** Null when the id doesn't exist (or was already deleted this session). */
    public function fetch(int|string $id): ?Message;

    /** @return Message[] newest first */
    public function all(int $limit = 50, int $offset = 0): array;

    /**
     * Marks for deletion. Neither protocol removes anything until expunge()
     * — see each reader for what "expunge" actually costs there.
     */
    public function delete(int|string $id): bool;

    public function expunge(): void;
}
