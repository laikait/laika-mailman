<?php

/**
 * Minimal SMTP send.
 *
 * Credentials come from the environment so nothing secret ends up in git:
 *
 *   MAIL_HOST=smtp.gmail.com MAIL_PORT=587 MAIL_USERNAME=you@gmail.com \
 *   MAIL_PASSWORD=app-password MAIL_FROM=you@gmail.com \
 *   MAIL_TO=someone@example.com php examples/send-smtp.php
 *
 * Gmail needs an App Password, not your account password.
 */

require __DIR__ . '/../vendor/autoload.php';

use Laika\Mailman\Mailer;
use Laika\Mailman\Exceptions\AuthenticationException;
use Laika\Mailman\Exceptions\MailmanException;
use Laika\Mailman\Exceptions\TransportException;

$mailer = new Mailer([
    'driver' => 'smtp',
    'host' => getenv('MAIL_HOST') ?: 'localhost',
    'port' => (int) (getenv('MAIL_PORT') ?: 587),
    'username' => getenv('MAIL_USERNAME') ?: '',
    'password' => getenv('MAIL_PASSWORD') ?: '',
    'encryption' => getenv('MAIL_ENCRYPTION') ?: 'tls',
    'from' => getenv('MAIL_FROM') ?: 'no-reply@example.com',
    'from_name' => getenv('MAIL_FROM_NAME') ?: 'Laika Mailman',
]);

try {
    $mailer
        ->to(getenv('MAIL_TO') ?: 'someone@example.com')
        ->subject('Hello from Laika Mailman')
        ->text('Plain text, sent through PHPMailer.')
        ->send();

    echo "Sent.\n";
} catch (AuthenticationException $e) {
    // Split out on purpose: this is the one failure worth re-prompting for
    // rather than retrying.
    fwrite(STDERR, "Credentials rejected: {$e->getMessage()}\n");
    exit(1);
} catch (TransportException $e) {
    fwrite(STDERR, "Could not reach the mail server: {$e->getMessage()}\n");
    exit(1);
} catch (MailmanException $e) {
    fwrite(STDERR, "Send failed: {$e->getMessage()}\n");
    exit(1);
}
