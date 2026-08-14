<?php

/**
 * Read a mailbox over IMAP: list folders, search unseen, print a summary,
 * save attachments.
 *
 *   IMAP_HOST=imap.gmail.com IMAP_PORT=993 IMAP_USERNAME=you@gmail.com \
 *   IMAP_PASSWORD=app-password php examples/read-imap.php
 *
 * Nothing here marks anything as read — fetch() uses BODY.PEEK[]. Call
 * markSeen() explicitly if that is what you want.
 */

require __DIR__ . '/../vendor/autoload.php';

use Laika\Mailman\Reader\ImapReader;
use Laika\Mailman\Exceptions\AuthenticationException;
use Laika\Mailman\Exceptions\MailmanException;
use Laika\Mailman\Exceptions\TransportException;

$reader = new ImapReader([
    'host' => getenv('IMAP_HOST') ?: 'localhost',
    'port' => (int) (getenv('IMAP_PORT') ?: 993),
    'encryption' => getenv('IMAP_ENCRYPTION') ?: 'ssl',
    'username' => getenv('IMAP_USERNAME') ?: '',
    'password' => getenv('IMAP_PASSWORD') ?: '',
    'folder' => getenv('IMAP_FOLDER') ?: 'INBOX',
]);

try {
    $reader->connect();

    echo "Folders:\n";

    foreach ($reader->mailboxes() as $mailbox) {
        // \Noselect folders are hierarchy placeholders (Gmail's "[Gmail]" is
        // the usual one) — SELECT on them fails.
        $note = $mailbox->isSelectable() ? '' : '  (not selectable)';
        echo "  - {$mailbox->name}{$note}\n";
    }

    $inbox = $reader->mailbox();
    echo "\n{$inbox->name}: {$inbox->messageCount} messages, {$inbox->unseenCount} unseen\n\n";

    // Server-side search — the mailbox does the filtering, not us.
    $uids = $reader->search([
        'unseen' => true,
        'since' => new DateTimeImmutable('-30 days'),
    ]);

    if ($uids === []) {
        echo "Nothing unseen in the last 30 days.\n";
        $reader->disconnect();
        exit(0);
    }

    echo count($uids) . " unseen message(s):\n\n";

    foreach (array_slice($uids, -10) as $uid) {
        $message = $reader->fetch($uid);

        if ($message === null) {
            continue;
        }

        $date = $message->date?->format('Y-m-d H:i') ?? 'unknown date';

        echo "  [{$uid}] {$date}  {$message->fromAddress()}\n";
        echo "        {$message->subject}\n";

        $preview = trim(preg_replace('/\s+/', ' ', $message->textBody) ?? '');
        echo '        ' . mb_substr($preview, 0, 100) . "\n";

        // files() skips inline cid: images, which are usually signature logos
        // and tracking pixels rather than anything the user wants saved.
        foreach ($message->files() as $attachment) {
            $target = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $attachment->safeFilename();
            $attachment->saveTo($target);
            echo "        saved {$attachment->filename} ({$attachment->size} bytes) -> {$target}\n";
        }

        echo "\n";
    }

    $reader->disconnect();
} catch (AuthenticationException $e) {
    fwrite(STDERR, "Credentials rejected: {$e->getMessage()}\n");
    fwrite(STDERR, "Gmail and Outlook require an app-specific password here.\n");
    exit(1);
} catch (TransportException $e) {
    fwrite(STDERR, "Could not reach the IMAP server: {$e->getMessage()}\n");
    exit(1);
} catch (MailmanException $e) {
    fwrite(STDERR, "IMAP error: {$e->getMessage()}\n");
    exit(1);
}
