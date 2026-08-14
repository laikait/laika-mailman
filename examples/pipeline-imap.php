<?php

/**
 * Scan a support inbox over IMAP and route each message to the right record.
 *
 *   IMAP_HOST=imap.gmail.com IMAP_USERNAME=support@example.com \
 *   IMAP_PASSWORD=app-password php examples/pipeline-imap.php
 *
 * Run it from cron. It processes unseen mail, marks each message \Seen only
 * after its handler succeeded, and moves it to a Processed folder — so a
 * handler that throws leaves the message untouched and the next run retries
 * it. That is the whole retry story; there is no queue and no state file.
 */

require __DIR__ . '/../vendor/autoload.php';

use Laika\Mailman\Pipeline\Pipeline;
use Laika\Mailman\Pipeline\ScanResult;
use Laika\Mailman\Pipeline\Source\ImapSource;
use Laika\Mailman\Reader\ImapReader;
use Laika\Mailman\Exceptions\MailmanException;

$reader = new ImapReader([
    'host' => getenv('IMAP_HOST') ?: 'localhost',
    'port' => (int) (getenv('IMAP_PORT') ?: 993),
    'encryption' => getenv('IMAP_ENCRYPTION') ?: 'ssl',
    'username' => getenv('IMAP_USERNAME') ?: '',
    'password' => getenv('IMAP_PASSWORD') ?: '',
    'folder' => getenv('IMAP_FOLDER') ?: 'INBOX',
]);

$pipeline = new Pipeline();

$pipeline
    // The built-in ticket/invoice/order/reference rules are already active.
    // Add your own, or replace a preset by re-using its name:
    ->extract('customer', '/\bCUST-(\d{4,})/i')

    ->onIdentifier('ticket', function (ScanResult $r): void {
        $id = $r->first('ticket');

        // isTrusted() is true only when the id came from a header you set or
        // a plus-addressed recipient — i.e. machine-generated, not typed.
        // A subject or body match is worth treating with more suspicion.
        $confidence = $r->confidenceOf('ticket');

        echo "  ticket {$id} ({$confidence}) <- {$r->message->fromAddress()}\n";

        if (!$r->isTrusted('ticket')) {
            echo "    (unverified source — consider confirming before auto-filing)\n";
        }

        // $tickets->appendReply($id, $r->message);
    })

    ->onIdentifier('invoice', function (ScanResult $r): void {
        echo "  invoice {$r->first('invoice')} <- {$r->message->fromAddress()}\n";
        // $billing->attach($r->first('invoice'), $r->message);
    })

    // Nothing matched. A reply still carries In-Reply-To even when the user
    // stripped the subject tag, so try the thread before opening anything new.
    ->otherwise(function (ScanResult $r): void {
        if ($r->isReply()) {
            echo "  reply with no id, thread: " . implode(', ', $r->threadIds) . "\n";
            // $tickets->findByMessageIds($r->threadIds)?->appendReply($r->message);
            return;
        }

        echo "  new enquiry: {$r->message->subject}\n";
        // $tickets->open($r->message);
    })

    // Runs for every message, matched or not.
    ->always(function (ScanResult $r): void {
        foreach ($r->message->files() as $attachment) {
            // safeFilename() strips traversal and NUL bytes — the sender
            // controls this string.
            $attachment->saveTo('/var/mail-attachments');
        }
    })

    ->onError(function (Throwable $e, $message): void {
        error_log('[pipeline] ' . ($message?->subject ?? '?') . ': ' . $e->getMessage());
    })

    ->afterProcessing(Pipeline::MOVE, 'Processed')
    ->limit(200);

try {
    $stats = $pipeline->run(new ImapSource($reader, ['unseen' => true]));

    printf(
        "scanned %d, handled %d, unmatched %d, failed %d\n",
        $stats['scanned'],
        $stats['handled'],
        $stats['unmatched'],
        $stats['failed']
    );

    // Anything that failed is still unseen in the mailbox and will be retried.
    exit($stats['failed'] > 0 ? 1 : 0);
} catch (MailmanException $e) {
    fwrite(STDERR, "Pipeline could not run: {$e->getMessage()}\n");
    exit(1);
}
