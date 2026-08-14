<?php

/**
 * HTML body, an embedded image, a generated attachment, and a batch send over
 * a single SMTP connection.
 *
 *   MAIL_HOST=... MAIL_USERNAME=... MAIL_PASSWORD=... MAIL_FROM=... \
 *   MAIL_TO=... php examples/send-html-attachment.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Laika\Mailman\Mailer;
use Laika\Mailman\Exceptions\MailmanException;

$mailer = Mailer::fromDsn(sprintf(
    'smtp://%s:%s@%s:%d?encryption=%s',
    rawurlencode(getenv('MAIL_USERNAME') ?: ''),
    rawurlencode(getenv('MAIL_PASSWORD') ?: ''),
    getenv('MAIL_HOST') ?: 'localhost',
    (int) (getenv('MAIL_PORT') ?: 587),
    getenv('MAIL_ENCRYPTION') ?: 'tls'
), [
    'from' => getenv('MAIL_FROM') ?: 'no-reply@example.com',
    'from_name' => 'Laika Mailman',
]);

$recipient = getenv('MAIL_TO') ?: 'someone@example.com';

$html = <<<HTML
<h1>Monthly report</h1>
<p>Your report is attached. Unicode works: café, 日本語, 🚀</p>
HTML;

try {
    $mailer
        ->to($recipient)
        ->subject('Monthly report — café edition')
        // Passing a real plaintext alternative rather than letting it be
        // auto-generated scores better with spam filters.
        ->body($html, "Monthly report\n\nYour report is attached.")
        ->attachData("month,revenue\n2025-08,12345\n", 'report.csv', 'text/csv')
        ->send();

    echo "Sent report to {$recipient}.\n";

    // Several distinct mails over one connection. Reconnecting per message is
    // what makes bulk sending slow, and some providers rate-limit connections
    // rather than messages.
    $results = $mailer->sendMany([
        static fn (Mailer $m) => $m->to($recipient)->subject('Batch 1')->text('First.'),
        static fn (Mailer $m) => $m->to($recipient)->subject('Batch 2')->text('Second.'),
    ]);

    echo 'Batch results: ' . json_encode($results) . "\n";
} catch (MailmanException $e) {
    fwrite(STDERR, "Send failed: {$e->getMessage()}\n");
    exit(1);
}
