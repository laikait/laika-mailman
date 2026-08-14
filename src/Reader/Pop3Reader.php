<?php

namespace Laika\Mailman\Reader;

use Laika\Mailman\Interfaces\MailReaderInterface;
use Laika\Mailman\Exceptions\AuthenticationException;
use Laika\Mailman\Exceptions\ProtocolException;
use Laika\Mailman\Exceptions\TransportException;
use Laika\Mailman\Mime\MimeParser;
use Laika\Mailman\Transport\Socket;
use Laika\Mailman\Message;

/**
 * A POP3 (RFC 1939) client that actually retrieves mail.
 *
 * This is NOT an extension of PHPMailer's POP3 class, and should not be
 * merged into it later. That class is a POP-before-SMTP *authentication*
 * helper by design — it sends only USER/PASS/QUIT, its authorise() disconnects
 * the moment login succeeds, and its response reader takes a single 128-byte
 * line. Multi-line responses are exactly what RETR, LIST and UIDL produce, so
 * that class is structurally unable to read a message and there is nothing to
 * reuse from it.
 *
 * POP3 is a much smaller protocol than IMAP: no folders, no server-side
 * search, no flags. Where IMAP would answer a question server-side, POP3
 * requires downloading and filtering locally — so prefer ImapReader unless
 * the mailbox genuinely only speaks POP3.
 */
class Pop3Reader implements MailReaderInterface
{
    protected Socket $socket;
    protected array $config;
    protected bool $authenticated = false;

    /** @var array<int,int>|null message number => size, cached from LIST */
    protected ?array $listing = null;

    /**
     * Config keys: host, port (995), encryption ('ssl'|'tls'|''), username,
     * password, timeout (30), validate_cert (true).
     *
     * Note 'tls' means STLS on port 110, not port 995 — 995 is implicit TLS
     * and wants 'ssl'.
     */
    public function __construct(array $config)
    {
        $this->config = array_merge([
            'host' => 'localhost',
            'port' => 995,
            'encryption' => 'ssl',
            'username' => '',
            'password' => '',
            'timeout' => 30,
            'validate_cert' => true,
        ], $config);

        $streamOptions = [];

        if (!$this->config['validate_cert']) {
            $streamOptions = ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true];
        }

        $this->socket = new Socket(
            (string) $this->config['host'],
            (int) $this->config['port'],
            (string) $this->config['encryption'],
            (int) $this->config['timeout'],
            $streamOptions
        );
    }

    /** pop3://user:pass@host:995?encryption=ssl — same shape as ImapReader::fromDsn(). */
    public static function fromDsn(string $dsn): self
    {
        $parts = parse_url($dsn);

        if ($parts === false || !isset($parts['host'])) {
            throw new ProtocolException("Malformed POP3 DSN: {$dsn}");
        }

        $query = [];
        parse_str($parts['query'] ?? '', $query);

        $scheme = strtolower($parts['scheme'] ?? 'pop3');
        $encryption = $query['encryption'] ?? (in_array($scheme, ['pop3s', 'pops'], true) ? 'ssl' : '');

        return new self([
            'host' => $parts['host'],
            'port' => (int) ($parts['port'] ?? ($encryption === 'ssl' ? 995 : 110)),
            'encryption' => $encryption,
            'username' => rawurldecode($parts['user'] ?? ''),
            'password' => rawurldecode($parts['pass'] ?? ''),
            'timeout' => (int) ($query['timeout'] ?? 30),
            'validate_cert' => !isset($query['validate_cert']) || filter_var($query['validate_cert'], FILTER_VALIDATE_BOOLEAN),
        ]);
    }

    public function connect(): void
    {
        if ($this->authenticated) {
            return;
        }

        $this->socket->connect();

        $greeting = $this->socket->readLine();

        if (!str_starts_with($greeting, '+OK')) {
            throw new TransportException("POP3 server refused the connection: {$greeting}");
        }

        if ($this->config['encryption'] === 'tls') {
            $this->command('STLS');
            $this->socket->enableCrypto();
        }

        $this->login();
    }

    public function disconnect(): void
    {
        // Deliberately not QUIT: QUIT enters the UPDATE state and permanently
        // removes anything marked DELE. Closing the socket instead means an
        // accidental delete() is never committed by simply going away — see
        // expunge(), which is the explicit way to commit.
        $this->socket->close();
        $this->authenticated = false;
        $this->listing = null;
    }

    public function count(): int
    {
        $this->ensureConnected();

        $response = $this->command('STAT');

        // "+OK 3 3210" — message count, then total octets.
        if (preg_match('/^\+OK\s+(\d+)/', $response, $m)) {
            return (int) $m[1];
        }

        return 0;
    }

    /** Total size of the mailbox in octets, the second field of STAT. */
    public function size(): int
    {
        $this->ensureConnected();

        if (preg_match('/^\+OK\s+\d+\s+(\d+)/', $this->command('STAT'), $m)) {
            return (int) $m[1];
        }

        return 0;
    }

    /**
     * One message by its session message number.
     *
     * Message numbers are only stable within a single session — they are
     * reassigned after any expunge. Use uidl() when an id has to survive a
     * reconnect.
     */
    public function fetch(int|string $id): ?Message
    {
        $this->ensureConnected();

        try {
            $raw = $this->multiline('RETR ' . (int) $id);
        } catch (ProtocolException $e) {
            // -ERR here means no such message (or already marked deleted),
            // which the interface expresses as null rather than an exception.
            return null;
        }

        return MimeParser::parseMessage($raw, (int) $id, [], strlen($raw));
    }

    /**
     * Newest first. POP3 has no server-side paging, so the slice happens on
     * the message-number list *before* any RETR — otherwise a 5-message page
     * of a 4000-message mailbox would download all 4000.
     *
     * @return Message[]
     */
    public function all(int $limit = 50, int $offset = 0): array
    {
        $this->ensureConnected();

        $numbers = array_keys($this->listing());
        rsort($numbers);

        $messages = [];

        foreach (array_slice($numbers, $offset, $limit) as $number) {
            $message = $this->fetch($number);

            if ($message !== null) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * Just the headers, via TOP n 0. Far cheaper than RETR for building an
     * inbox listing, since it skips the body and any attachments entirely.
     * TOP is optional in RFC 1939, though every server in practice has it.
     */
    public function headers(int|string $id): ?Message
    {
        $this->ensureConnected();

        try {
            $raw = $this->multiline('TOP ' . (int) $id . ' 0');
        } catch (ProtocolException $e) {
            return null;
        }

        return MimeParser::parseMessage($raw, (int) $id);
    }

    /** @return array<int,int> message number => size in octets */
    public function listing(bool $refresh = false): array
    {
        $this->ensureConnected();

        if ($this->listing !== null && !$refresh) {
            return $this->listing;
        }

        $this->listing = [];

        foreach (explode("\r\n", $this->multiline('LIST')) as $line) {
            if (preg_match('/^(\d+)\s+(\d+)/', trim($line), $m)) {
                $this->listing[(int) $m[1]] = (int) $m[2];
            }
        }

        return $this->listing;
    }

    /**
     * @return array<int,string> message number => server-assigned unique id
     *
     * Unlike message numbers, a UIDL is stable across sessions — this is what
     * to persist if you need to remember which mail you have already seen.
     */
    public function uidl(): array
    {
        $this->ensureConnected();

        $uids = [];

        foreach (explode("\r\n", $this->multiline('UIDL')) as $line) {
            if (preg_match('/^(\d+)\s+(\S+)/', trim($line), $m)) {
                $uids[(int) $m[1]] = $m[2];
            }
        }

        return $uids;
    }

    /**
     * Marks for deletion only. Nothing is removed until expunge() — and in
     * POP3 a RSET or simply closing the socket undoes every pending DELE.
     */
    public function delete(int|string $id): bool
    {
        $this->ensureConnected();

        try {
            $this->command('DELE ' . (int) $id);
        } catch (ProtocolException $e) {
            return false;
        }

        $this->listing = null;

        return true;
    }

    /**
     * Commits pending deletions.
     *
     * POP3 has no EXPUNGE command: deletions are applied when the session
     * enters the UPDATE state, which only happens on QUIT. So this ends the
     * session — a real semantic difference from IMAP, where expunge() leaves
     * you connected. The next call reconnects automatically.
     */
    public function expunge(): void
    {
        if (!$this->authenticated) {
            return;
        }

        try {
            $this->command('QUIT');
        } catch (\Throwable $e) {
            // Servers that hang up on QUIT rather than answering are common.
        }

        $this->socket->close();
        $this->authenticated = false;
        $this->listing = null;
    }

    /** Drops every pending DELE without ending the session. */
    public function reset(): void
    {
        $this->ensureConnected();
        $this->command('RSET');
        $this->listing = null;
    }

    protected function login(): void
    {
        // POP3 has no quoting at all — a CRLF in a credential would inject a
        // command, so strip control characters rather than escape them.
        $username = $this->sanitize((string) $this->config['username']);
        $password = $this->sanitize((string) $this->config['password']);

        try {
            $this->command('USER ' . $username);
            $this->command('PASS ' . $password);
        } catch (ProtocolException $e) {
            throw new AuthenticationException(
                "POP3 login failed for {$this->config['username']}@{$this->config['host']}: {$e->getMessage()}"
            );
        }

        $this->authenticated = true;
    }

    protected function ensureConnected(): void
    {
        if (!$this->authenticated || !$this->socket->isConnected()) {
            $this->authenticated = false;
            $this->connect();
        }
    }

    /** A single-line command. Throws on -ERR; returns the raw +OK line. */
    protected function command(string $command): string
    {
        $this->socket->write($command);
        $response = $this->socket->readLine();

        if (!str_starts_with($response, '+OK')) {
            throw new ProtocolException("POP3 error for '{$this->redact($command)}': " . trim(substr($response, 4)));
        }

        return $response;
    }

    /**
     * A multi-line response: the +OK status line, then data, terminated by a
     * line containing only ".".
     *
     * Byte-stuffing has to be undone here — RFC 1939 requires the sender to
     * prefix any data line already starting with "." with a second ".", so
     * that the terminator is unambiguous. Skipping that step corrupts every
     * message whose body happens to contain a line beginning with a period,
     * which is common in quoted text and in base64 output.
     */
    protected function multiline(string $command): string
    {
        $this->socket->write($command);
        $status = $this->socket->readLine();

        if (!str_starts_with($status, '+OK')) {
            throw new ProtocolException("POP3 error for '{$command}': " . trim(substr($status, 4)));
        }

        $lines = [];

        while (true) {
            $line = $this->socket->readLine();

            if ($line === '.') {
                break;
            }

            if (str_starts_with($line, '..')) {
                $line = substr($line, 1);
            }

            $lines[] = $line;
        }

        return implode("\r\n", $lines);
    }

    protected function sanitize(string $value): string
    {
        return str_replace(["\r", "\n", "\0"], '', $value);
    }

    /** Keeps the password out of exception messages and stack traces. */
    protected function redact(string $command): string
    {
        return preg_replace('/^(PASS\s+).*$/i', '$1***', $command) ?? $command;
    }

    public function __destruct()
    {
        $this->socket->close();
    }
}
