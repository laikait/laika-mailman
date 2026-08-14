<?php

/**
 * Read a mailbox over POP3.
 *
 *   POP3_HOST=pop.gmail.com POP3_PORT=995 POP3_USERNAME=you@gmail.com \
 *   POP3_PASSWORD=app-password php examples/read-pop3.php
 *
 * Prefer ImapReader unless the mailbox genuinely only speaks POP3 — POP3 has
 * no folders, no flags, and no server-side search, so any filtering here
 * happens after downloading.
 */

require __DIR__ . '/../vendor/autoload.php';

use Laika\Mailman\Reader\Pop3Reader;
use Laika\Mailman\Exceptions\AuthenticationException;
use Laika\Mailman\Exceptions\MailmanException;
use Laika\Mailman\Exceptions\TransportException;

$reader = new Pop3Reader([
    'host' => getenv('POP3_HOST') ?: 'localhost',
    'port' => (int) (getenv('POP3_PORT') ?: 995),
    'encryption' => getenv('POP3_ENCRYPTION') ?: 'ssl',
    'username' => getenv('POP3_USERNAME') ?: '',
    'password' => getenv('POP3_PASSWORD') ?: '',
]);

try {
    $reader->connect();

    $count = $reader->count();
    echo "{$count} message(s), " . $reader->size() . " octets total\n\n";

    if ($count === 0) {
        $reader->disconnect();
        exit(0);
    }

    // UIDLs are stable across sessions; message numbers are not, so this is
    // what to persist if you need to remember what you have already handled.
    $uidls = $reader->uidl();

    // TOP fetches headers only — much cheaper than RETR for a listing, since
    // it skips bodies and attachments entirely.
    foreach (array_slice(array_keys($reader->listing()), -10) as $number) {
        $headers = $reader->headers($number);

        if ($headers === null) {
            continue;
        }

        $date = $headers->date?->format('Y-m-d H:i') ?? 'unknown date';
        echo "  [{$number}] {$date}  {$headers->fromAddress()}\n";
        echo "        {$headers->subject}\n";
        echo "        uidl: " . ($uidls[$number] ?? 'n/a') . "\n\n";
    }

    // Full download of the newest message, bodies and attachments included.
    $newest = max(array_keys($reader->listing()));
    $message = $reader->fetch($newest);

    if ($message !== null) {
        echo "Newest message body:\n";
        echo mb_substr(trim($message->textBody !== '' ? $message->textBody : $message->body()), 0, 300) . "\n";
        echo count($message->files()) . " attachment(s)\n";
    }

    // disconnect() closes without QUIT, so any delete() marked this session is
    // NOT committed. expunge() is the explicit commit — and it ends the
    // session, because POP3 only applies deletions on QUIT.
    $reader->disconnect();
} catch (AuthenticationException $e) {
    fwrite(STDERR, "Credentials rejected: {$e->getMessage()}\n");
    exit(1);
} catch (TransportException $e) {
    fwrite(STDERR, "Could not reach the POP3 server: {$e->getMessage()}\n");
    exit(1);
} catch (MailmanException $e) {
    fwrite(STDERR, "POP3 error: {$e->getMessage()}\n");
    exit(1);
}
