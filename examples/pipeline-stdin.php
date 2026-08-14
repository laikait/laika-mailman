#!/usr/bin/env php
<?php

/**
 * The same scanning rules, driven by an MTA pipe instead of polling.
 *
 * The mail server executes this script at delivery and writes the complete
 * message to its standard input, so there is no polling interval and no
 * mailbox to clean up.
 *
 * Postfix — in /etc/aliases (then run newaliases):
 *   support: "|/usr/bin/php /path/to/examples/pipeline-stdin.php"
 *
 * cPanel — Email > Forwarders > "Pipe to a Program":
 *   examples/pipeline-stdin.php     (path relative to the home directory)
 *
 * Test it without a mail server at all:
 *   cat message.eml | php examples/pipeline-stdin.php
 *
 * THE EXIT CODE MATTERS. The MTA reads it: a non-zero exit means "delivery
 * failed", and the message is bounced back to whoever sent it. A crashed
 * handler would therefore turn into a confusing bounce for a real customer,
 * so this script logs failures and still exits 0. Exit non-zero only when you
 * genuinely want the mail rejected.
 */

require __DIR__ . '/../vendor/autoload.php';

use Laika\Mailman\Pipeline\Pipeline;
use Laika\Mailman\Pipeline\ScanResult;
use Laika\Mailman\Pipeline\Source\StdinSource;

// Piped scripts run with almost no environment and their output goes nowhere
// useful, so send diagnostics somewhere you can actually read them.
$log = static function (string $line): void {
    $target = getenv('PIPELINE_LOG') ?: (sys_get_temp_dir() . '/laika-pipeline.log');
    file_put_contents($target, date('c') . ' ' . $line . "\n", FILE_APPEND);
};

$pipeline = new Pipeline();

$pipeline
    ->onIdentifier('ticket', function (ScanResult $r) use ($log): void {
        $log("ticket {$r->first('ticket')} ({$r->confidenceOf('ticket')}) from {$r->message->fromAddress()}");
        echo "ticket={$r->first('ticket')}\n";
        // $tickets->appendReply($r->first('ticket'), $r->message);
    })
    ->onIdentifier('invoice', function (ScanResult $r) use ($log): void {
        $log("invoice {$r->first('invoice')} from {$r->message->fromAddress()}");
        echo "invoice={$r->first('invoice')}\n";
    })
    ->otherwise(function (ScanResult $r) use ($log): void {
        $log("unmatched: {$r->message->subject}");
        echo "unmatched\n";
        // $tickets->open($r->message);
    })
    ->onError(function (Throwable $e, $message) use ($log): void {
        $log('ERROR ' . ($message?->subject ?? 'unparseable') . ': ' . $e->getMessage());
    })
    // Nothing to mark or move — the MTA already delivered this message, and
    // StdinSource ignores the post-action entirely. Stated explicitly so the
    // intent is obvious rather than accidental.
    ->afterProcessing(Pipeline::LEAVE);

try {
    $stats = $pipeline->run(new StdinSource());
    $log('processed ' . json_encode($stats));
} catch (\Throwable $e) {
    // Even a completely unparseable message must not bounce.
    $log('FATAL: ' . $e->getMessage());
}

exit(0);
