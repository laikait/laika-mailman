# Laika Mailman

Mail package for the [Laika PHP MVC Framework](https://github.com/laikait). Sending is a fluent wrapper over [PHPMailer](https://github.com/PHPMailer/PHPMailer) (SMTP, sendmail, qmail, `mail()`); reading is a pair of IMAP and POP3 clients written for this package, because PHPMailer cannot read mail at all.

Standalone: it depends on PHPMailer and nothing else. No framework core, no ORM, no `ext-imap`.

## Install

```bash
composer require laikait/laika-mailman
```

## Sending

```php
use Laika\Mailman\Mailer;

$mailer = new Mailer([
    'driver'     => 'smtp',
    'host'       => 'smtp.gmail.com',
    'port'       => 587,
    'username'   => 'you@gmail.com',
    'password'   => 'app-password',
    'encryption' => 'tls',
    'from'       => 'you@gmail.com',
    'from_name'  => 'Your App',
]);

$mailer->to('someone@example.com', 'Someone')
       ->subject('Monthly report')
       ->body('<h1>Report</h1><p>Attached.</p>', 'Report — attached.')
       ->attach('/path/to/report.pdf')
       ->send();
```

Every build method returns `$this`. `body()` takes the HTML first and a plaintext alternative second — supplying a real plaintext version rather than letting it be auto-generated from the HTML scores measurably better with spam filters.

| Method | Notes |
|---|---|
| `from()` `to()` `cc()` `bcc()` `replyTo()` | Second argument is the display name |
| `subject()` | |
| `body($html, $plain = '')` / `html()` | `AltBody` is auto-generated when `$plain` is omitted |
| `text()` | Plaintext-only message |
| `attach($path, $name = '')` | From disk |
| `attachData($bytes, $filename, $mime = '')` | Bytes you already hold, no temp file |
| `embed($path, $cid)` | Reference it as `<img src="cid:$cid">` |
| `priority()` `header()` | |
| `xmailer()` | Set, replace or remove `X-Mailer` — see [below](#the-x-mailer-header) |
| `send()` | Returns `bool`, throws on failure |
| `reset()` | Clears recipients/attachments/body so the instance can send another |
| `lastError()` | PHPMailer's `ErrorInfo` |
| `phpMailer()` | Escape hatch to the underlying `PHPMailer` instance |

### Bulk sending

`sendMany()` reuses one authenticated SMTP connection across messages, which is the difference that matters at volume — reconnecting per message is slow, and several providers rate-limit connections rather than messages.

```php
$results = $mailer->sendMany([
    fn (Mailer $m) => $m->to('a@example.com')->subject('Hi')->text('One.'),
    fn (Mailer $m) => $m->to('b@example.com')->subject('Hi')->text('Two.'),
]);
// [0 => true, 1 => true] — one failure doesn't abort the rest of the batch
```

### DSN

```php
$mailer = Mailer::fromDsn('smtp://user:pass@smtp.example.com:587?encryption=tls');
```

This builds the `PHPMailer` itself rather than using PHPMailer's bundled `DSNConfigurator`, which has a genuine bug: for the `smtps://` scheme it sets `SMTPSecure` to STARTTLS (`'tls'`) while defaulting the port to 465 (`DSNConfigurator.php:140-144`). 465 is the implicit-TLS port and needs `'ssl'` — that pairing fails against most servers. `Mailer::fromDsn()` maps `smtps://` to `'ssl'` and port 465, as intended.

Percent-encode credentials containing `@`, `:` or `/`.

### The X-Mailer header

Outbound mail carries `X-Mailer: Laika Mailman` by default — the sending software, with no version number. Left to PHPMailer, every message would instead advertise `X-Mailer: PHPMailer 7.1.1 (https://github.com/PHPMailer/PHPMailer)`, telling each recipient exactly which release to look up in a vulnerability list. That is disclosure with no upside, so it is off unless you ask for it.

```php
$mailer->xmailer('My App 2.0');                // X-Mailer: My App 2.0
$mailer->xmailer(Mailer::XMAILER_NONE);        // no X-Mailer header at all
$mailer->xmailer(Mailer::XMAILER_PHPMAILER);   // PHPMailer's default, version and all
```

Also settable as the `xmailer` config key, or as a DSN query parameter: `smtp://…?xmailer=My+App`.

**Use the constants rather than a bare `null` or `''`.** PHPMailer's underlying property is three-way and the states are the opposite of the obvious reading — `''` means *"use the default"*, and `null` or whitespace means *"no header"* (`PHPMailer.php:2918-2927`). Setting `XMailer = ''` to suppress the header gets you the loudest possible value instead. `Mailer::normalizeXMailer()` wraps that so call sites say what they mean; CR/LF are stripped there too, so a value carrying a newline cannot inject a second header.

### UTF-8

`CharSet` is set to UTF-8 explicitly. PHPMailer 7 still defaults to ISO-8859-1 (`PHPMailer.php:78`), so without this every non-ASCII subject and body arrives as mojibake. Override with the `charset` config key if you actually want something else.

## Reading

**PHPMailer contributes nothing to this half.** It is a send-only library: its `POP3.php` is a POP-before-SMTP *authentication* helper that sends only `USER`/`PASS`/`QUIT`, disconnects the instant login succeeds, and reads responses one 128-byte line at a time — so it cannot handle the multi-line responses that `RETR`, `LIST` and `UIDL` produce, and there is no IMAP client anywhere in the package. `ImapReader` and `Pop3Reader` are therefore real protocol clients written here, over raw sockets.

They also avoid `ext-imap` deliberately: it was deprecated in PHP 8.4 and moved to PECL, and it is absent on a lot of shared hosting.

### IMAP

```php
use Laika\Mailman\Reader\ImapReader;

$reader = new ImapReader([
    'host'       => 'imap.gmail.com',
    'port'       => 993,
    'encryption' => 'ssl',
    'username'   => 'you@gmail.com',
    'password'   => 'app-password',
    'folder'     => 'INBOX',
]);

$reader->connect();

foreach ($reader->mailboxes() as $mailbox) {
    echo $mailbox->name, "\n";
}

$uids = $reader->search([
    'unseen'  => true,
    'since'   => new DateTimeImmutable('-7 days'),
    'from'    => 'billing@vendor.com',
]);

foreach ($uids as $uid) {
    $message = $reader->fetch($uid);

    echo $message->subject, ' — ', $message->fromAddress(), "\n";

    foreach ($message->files() as $attachment) {
        $attachment->saveTo('/var/mail-attachments');
    }
}

$reader->disconnect();
```

`fetch()` uses `BODY.PEEK[]`, not `BODY[]` — the latter sets `\Seen` as a side effect, and marking mail read merely by looking at it surprises callers. Use `markSeen()` when you mean it.

Search criteria: `unseen` `seen` `flagged` `unflagged` `answered` `deleted` `draft` `recent` `all` (bool); `from` `to` `cc` `bcc` `subject` `body` `text` `keyword` (string); `since` `before` `on` `sentsince` `sentbefore` `senton` (`DateTimeInterface` or anything `strtotime()` accepts). Searching happens on the server.

IMAP-only methods beyond the shared interface: `select()`, `mailboxes()`, `search()`, `markSeen()`, `markUnseen()`, `flag()`, `unflag()`, `move()`, `copy()`, `capabilities()`, `hasCapability()`, `mailbox()`.

`move()` uses `UID MOVE` where the server advertises the capability and falls back to `UID COPY` + `\Deleted` where it doesn't.

### POP3

```php
use Laika\Mailman\Reader\Pop3Reader;

$reader = new Pop3Reader([
    'host'       => 'pop.gmail.com',
    'port'       => 995,
    'encryption' => 'ssl',
    'username'   => 'you@gmail.com',
    'password'   => 'app-password',
]);

$reader->connect();

echo $reader->count(), " messages\n";

// TOP n 0 — headers only, far cheaper than RETR for building a listing
foreach (array_keys($reader->listing()) as $number) {
    echo $reader->headers($number)->subject, "\n";
}

$message = $reader->fetch(1);   // full RETR
$reader->disconnect();
```

POP3-only methods: `listing()`, `uidl()`, `headers()`, `size()`, `reset()`.

Message numbers are only stable within a session — they are reassigned after any expunge. Persist `uidl()` values instead if an id has to survive a reconnect.

### Choosing a reader

| | `ImapReader` | `Pop3Reader` |
|---|---|---|
| Folders | Yes | No — one implicit mailbox |
| Server-side search | Yes | No |
| Flags (`\Seen`, `\Flagged`) | Yes | No |
| Headers without the body | Via `BODY.PEEK[HEADER]` | Via `TOP n 0` |
| Stable ids across sessions | UIDs | `uidl()` only |
| Delete semantics | `\Deleted` then `expunge()`, stays connected | `DELE` then `expunge()`, which ends the session |

Prefer IMAP unless the mailbox genuinely only speaks POP3. `MailReaderInterface` covers only what both can honestly do — `connect()`, `disconnect()`, `count()`, `fetch()`, `all()`, `delete()`, `expunge()`. Folders, search and flags are concrete methods on `ImapReader` rather than interface members, because a POP3 "search" that downloads the whole mailbox and filters locally would make the two look interchangeable when they are not.

### Deleting

Both protocols are two-phase, but they differ in a way worth knowing:

- **IMAP** — `delete($uid)` sets `\Deleted`; `expunge()` commits and you stay connected. `unflag($uid, '\Deleted')` undoes it.
- **POP3** — `delete($n)` sends `DELE`; deletions are only applied when the session enters the UPDATE state, which happens on `QUIT`. So `expunge()` commits *and ends the session* (the next call reconnects). `disconnect()` deliberately closes without `QUIT`, which discards pending deletions, and `reset()` sends `RSET` to drop them without disconnecting.

### Messages

`fetch()` returns a `Message` with everything already decoded to UTF-8 — RFC 2047 headers unwrapped, bodies base64/quoted-printable decoded and charset-converted.

```php
$message->subject;          // string, decoded
$message->from;             // ['email' => ..., 'name' => ...]
$message->to;               // list of the same
$message->date;             // ?DateTimeImmutable
$message->textBody;         // text/plain part
$message->htmlBody;         // text/html part
$message->body();           // htmlBody if present, else textBody
$message->attachments;      // Attachment[], including inline cid: parts
$message->files();          // Attachment[], excluding inline parts
$message->flags;            // IMAP only
$message->header('x-spam-score');
```

`$message->body()` is **not** sanitized. Inbound HTML is attacker-controlled — run it through your own sanitizer before putting it in a page.

`Attachment::saveTo()` accepts a file path or a directory. Given a directory it appends `safeFilename()`, which strips path traversal and NUL bytes from the sender-supplied name — that name is untrusted input.

## Scanning incoming mail

`Pipeline` scans inbound mail for identifiers — ticket numbers, invoice numbers, order references — and routes each message to your handlers. The job it exists for: a support inbox where a reply carrying `[TICKET-1234]` must append to ticket 1234 rather than open a new one.

```php
use Laika\Mailman\Pipeline\Pipeline;
use Laika\Mailman\Pipeline\ScanResult;
use Laika\Mailman\Pipeline\Source\ImapSource;

$pipeline = (new Pipeline())
    ->onIdentifier('ticket', fn (ScanResult $r) => $tickets->appendReply($r->first('ticket'), $r->message))
    ->onIdentifier('invoice', fn (ScanResult $r) => $billing->attach($r->first('invoice'), $r->message))
    ->otherwise(fn (ScanResult $r) => $tickets->open($r->message))
    ->afterProcessing(Pipeline::MOVE, 'Processed');

$stats = $pipeline->run(new ImapSource($reader, ['unseen' => true]));
// ['scanned' => 12, 'handled' => 10, 'unmatched' => 2, 'failed' => 0]
```

### Where messages come from

| Source | Use |
|---|---|
| `ImapSource` | Poll a mailbox from cron. Supports every post-action. |
| `Pop3Source` | Same, for servers that only speak POP3. `DELETE`/`LEAVE` only. |
| `StdinSource` | The MTA pipes the raw message to your script at delivery. |
| `RawSource` | Raw strings or `.eml` files — the testing seam. |

Polling needs no server access; piping is lower latency but needs an MTA you control. Both feed identical rules and handlers, so you can develop against IMAP and deploy behind a pipe without changing anything but the source.

For a mail pipe, register `examples/pipeline-stdin.php` in `/etc/aliases` (`support: "|/usr/bin/php /path/to/pipeline-stdin.php"`) or cPanel's "Pipe to a Program". **Your script must exit 0 even when a handler fails** — the MTA reads the exit code, and a non-zero exit bounces the mail back to the sender.

### What gets extracted

Built-in rules, all overridable, all active by default:

| Name | Matches |
|---|---|
| `ticket` | `[#1234]`, `[TICKET-1234]`, `TKT-1234`, `Ticket #1234`, `CASE 1234` |
| `invoice` | `INV-2024-0087`, `Invoice #1234`, `INV#1234` |
| `order` | `Order #1234`, `ORD-1234`, `PO-9001` |
| `reference` | `Ref: ABC-123`, `Reference: 12345` |

Register your own, or replace a preset by re-using its name. `withoutPresets()` drops them entirely.

```php
$pipeline->extract('customer', '/\bCUST-(\d{4,})/i')
         ->extract('ticket', '/\bSUP-(\d+)/i', [Extractor::SUBJECT]);  // replaces the preset
```

The third argument restricts *where* a rule may match — `Extractor::SUBJECT`, `BODY`, `HEADERS`, `RECIPIENTS`. Scoping is usually more effective than tightening the pattern: an invoice regex loose enough to catch real invoice numbers will also match a reference in a signature block, and confining it to the subject line fixes that without making the pattern brittle. An invalid regex throws at registration rather than silently never matching.

### Confidence

The same identifier can appear in several places, and they are not equally trustworthy. Results are ordered by tier, so `first()` returns the best one:

| Tier | Source | Why |
|---|---|---|
| `header` | `X-Ticket-ID: 5150` | You set it on the way out. Taken **verbatim**, not regex-matched. |
| `recipient` | `support+ticket-1234@example.com` | Generated by your own Reply-To; a client cannot strip it without breaking delivery. |
| `subject` | `[TICKET-1234] …` | Conventional, but a human can edit it. |
| `body` | anywhere in the text | Least reliable — prose and signatures live here. |

```php
$r->first('ticket');            // '5150' — best available
$r->all('ticket');              // ['5150', '991', '1234'] — every hit, best first
$r->confidenceOf('ticket');     // 'header'
$r->isTrusted('ticket');        // true for header/recipient only
```

`isTrusted()` is the hook for treating a body-only match differently — queue it for review rather than auto-filing it.

### Replies without an identifier

A user who strips the subject tag still sends `In-Reply-To`. `$r->threadIds` collects the `Message-ID`, `In-Reply-To` and `References` values so the thread can be recovered when no regex matched:

```php
->otherwise(function (ScanResult $r) use ($tickets) {
    if ($r->isReply() && $t = $tickets->findByMessageIds($r->threadIds)) {
        return $t->appendReply($r->message);
    }
    $tickets->open($r->message);
})
```

### Quoted history is not scanned

Body extraction ignores quoted reply history by default — `>` lines, `On … wrote:` attributions, `-----Original Message-----`, and the `-- ` signature delimiter. Without this, every reply re-matches every identifier ever mentioned in the thread: a conversation that started as ticket 1111 and now mentions ticket 2222 would yield both, with no principled way to choose, and would file against the wrong one roughly half the time.

`scanQuotedText(true)` opts back in. `stripQuoted()` is `protected` and overridable for mail systems with their own quoting conventions.

### Retries and idempotency

The post-action is applied **after** handlers succeed, never on sight:

| Action | Effect |
|---|---|
| `Pipeline::MARK_SEEN` | Mark `\Seen` (default). Pairs with an `['unseen' => true]` search so the mailbox itself is the state — no storage. |
| `Pipeline::MOVE` | Mark `\Seen`, then move to a folder. |
| `Pipeline::DELETE` | Mark deleted; committed when the run finishes. |
| `Pipeline::LEAVE` | Nothing — you are tracking processed mail yourself. |

A handler that throws leaves its message **untouched**, so the next run picks it up again. `onError()` receives the exception and the message, the run continues to the next message, and `failed` is incremented. That is the entire retry story — no queue, no state file.

`Pop3Source` rejects `MARK_SEEN` and `MOVE` rather than accepting them and doing nothing: POP3 has no flags or folders, and silently ignoring the action would reprocess the same mail forever. Use `DELETE`, or `LEAVE` with your own bookkeeping keyed on `Pop3Reader::uidl()`.

### Testing your rules

`scan()` is public and side-effect free — no source, no mailbox, no handlers:

```php
$result = $pipeline->scan(MimeParser::parseMessage($rawEmail));
assert($result->first('ticket') === '1234');
```

`RawSource` runs the whole pipeline over in-memory messages and records what `complete()` would have done, so post-actions can be asserted without a live server.

## Configuration

Both readers accept a config array or a DSN.

| Key | `Mailer` | `ImapReader` | `Pop3Reader` |
|---|---|---|---|
| `host` | `localhost` | `localhost` | `localhost` |
| `port` | `587` | `993` | `995` |
| `encryption` | `tls` | `ssl` | `ssl` |
| `username` / `password` | `''` | `''` | `''` |
| `timeout` | `30` | `30` | `30` |
| `validate_cert` | `true` | `true` | `true` |
| `driver` | `smtp` | — | — |
| `from` / `from_name` | `''` | — | — |
| `charset` | `UTF-8` | — | — |
| `xmailer` | `Laika Mailman` | — | — |
| `debug` / `keepalive` / `auto_tls` | `0` / `false` / `true` | — | — |
| `folder` | — | `INBOX` | — |

`encryption` values:

- `'ssl'` — implicit TLS from the first byte (SMTP 465, IMAP 993, POP3 995)
- `'tls'` — connect in the clear, then upgrade (SMTP STARTTLS, IMAP STARTTLS, POP3 STLS; ports 587, 143, 110)
- `''` — no encryption

DSN forms: `smtp://`, `smtps://`, `imap://`, `imaps://`, `pop3://`, `pop3s://`, each accepting `?encryption=`, `?timeout=` and `?validate_cert=`.

Setting `validate_cert` to `false` accepts any certificate, including an attacker's. Only do it against a dev server you control.

## OAuth (XOAUTH2)

```php
$mailer->oauth($yourTokenProvider);
```

`$yourTokenProvider` implements `PHPMailer\PHPMailer\OAuthTokenProvider`, which is a **single** method — `getOauth64()`, returning `base64("user=<email>\1auth=Bearer <token>\1\1")`. PHPMailer's own bundled `OAuth` class needs `league/oauth2-client`, but you do not have to use it: implementing the one-method interface yourself keeps the dependency out entirely.

## Exceptions

Everything throws a `Laika\Mailman\Exceptions\MailmanException` or one of three subclasses, so there is one hierarchy to catch — PHPMailer's own exception is translated rather than leaking through.

| Exception | Means |
|---|---|
| `TransportException` | Connect refused, TLS handshake failed, read timeout, peer hung up |
| `AuthenticationException` | Credentials rejected (IMAP `NO` on LOGIN, POP3 `-ERR` on PASS, SMTP AUTH refused) |
| `ProtocolException` | Connection fine, server refused the command (IMAP `NO`/`BAD`, POP3 `-ERR`) — carries the server's own text |
| `MailmanException` | Base; anything else |

Credentials are redacted from exception messages, so a `LOGIN`/`PASS` failure will not put a password into a log or a stack trace.

## Security notes

- Everything interpolated into an IMAP command is quoted, and CR/LF/NUL are stripped first. A folder name or search term carrying a newline would otherwise terminate the command and inject a second one — this is command injection, not a formatting concern. POP3 has no quoting at all, so credentials are stripped the same way.
- TLS peer verification is on by default in both the readers and the mailer.
- `STARTTLS`/`STLS` upgrades happen before any credential is sent, and IMAP capabilities are re-read after the upgrade because pre-upgrade capabilities can be tampered with.
- Attachment filenames from the sender are untrusted; use `safeFilename()` (which `saveTo()` does for you when given a directory) rather than the raw `filename`.

## Known Gaps

- The IMAP client is not a complete RFC 3501 implementation. No `IDLE` (so no push; poll instead), no server-side `SORT`/`THREAD`, no `COMPRESS`, no `NTLM`/`GSSAPI` authentication. `LOGIN` and `XOAUTH2` are what's supported.
- MIME parsing covers what real mail actually contains, but nested `message/rfc822` parts are surfaced as raw attachments rather than recursively parsed into their own `Message`.
- POP3 `all()` and `listing()` fetch per message; there is no server-side paging in the protocol, so a large mailbox is genuinely slow. The `$limit`/`$offset` slice is applied to the message-number list before any `RETR`, so at least it doesn't download more than you asked for.
- No connection pooling and no automatic retry. A dropped connection is reopened on the next call, but an in-flight command fails.
- Pipeline extraction is heuristic. The presets match the conventional formats, but a `body`-tier hit is a guess about prose — check `isTrusted()` before auto-filing anything consequential. Custom headers and plus-addressing are the signals worth relying on, because your own system generates them; a subject tag is a convention a user can break, and a body match is pattern-matching on English.
- `Pipeline` processes messages one at a time in a single process. There is no concurrency and no locking, so two pipeline runs against the same mailbox can both pick up the same message before either marks it. Run one at a time per mailbox, or make your handlers idempotent.
- There is no test suite in the repository. The parser and both protocol clients were verified against fixture messages and scripted fake servers during development; that harness is not committed.

## Requirements

- PHP 8.1+
- [`phpmailer/phpmailer`](https://github.com/PHPMailer/PHPMailer) ^7.1 — sending only
- `ext-openssl` — TLS for SMTP, IMAP and POP3
- `ext-mbstring` — charset conversion in the MIME parser

`ext-imap` is deliberately **not** required.

## License

MIT
