<?php

namespace Laika\Mailman\Reader;

use Laika\Mailman\Interfaces\MailReaderInterface;
use Laika\Mailman\Exceptions\AuthenticationException;
use Laika\Mailman\Exceptions\ProtocolException;
use Laika\Mailman\Exceptions\TransportException;
use Laika\Mailman\Mime\MimeParser;
use Laika\Mailman\Transport\Socket;
use Laika\Mailman\Mailbox;
use Laika\Mailman\Message;

/**
 * An IMAP4rev1 (RFC 3501) client written against a raw socket.
 *
 * PHPMailer contributes nothing here — it is a send-only library, and its
 * POP3.php is a POP-before-SMTP authentication helper, not a mail reader. So
 * this is a real client rather than a wrapper. It also deliberately avoids
 * ext-imap, which was deprecated in PHP 8.4 and moved to PECL, and is absent
 * on a lot of shared hosting.
 *
 * Scope: connect, list folders, select, search, fetch, flag, move, delete.
 * Not implemented: IDLE, server-side SORT/THREAD, COMPRESS, NTLM/GSSAPI.
 *
 * The two things most hand-rolled IMAP clients get wrong, both handled here:
 * literals ({1234} means "exactly 1234 octets follow, and they may contain
 * anything including CRLF"), and quoting user input into commands.
 */
class ImapReader implements MailReaderInterface
{
    protected Socket $socket;
    protected array $config;
    protected int $sequence = 0;
    protected bool $authenticated = false;
    protected ?Mailbox $mailbox = null;
    protected array $capabilities = [];

    /**
     * Config keys: host, port (993), encryption ('ssl'|'tls'|''), username,
     * password, timeout (30), folder ('INBOX'), validate_cert (true).
     *
     * Nothing connects here — the socket opens on connect(), or lazily on the
     * first command — so constructing a reader is always cheap and safe.
     */
    public function __construct(array $config)
    {
        $this->config = array_merge([
            'host' => 'localhost',
            'port' => 993,
            'encryption' => 'ssl',
            'username' => '',
            'password' => '',
            'timeout' => 30,
            'folder' => 'INBOX',
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

    /**
     * imap://user:pass@host:993/INBOX?encryption=ssl&timeout=30
     *
     * Credentials go through rawurldecode(), so a password containing @ or :
     * works as long as it was percent-encoded when the DSN was built.
     */
    public static function fromDsn(string $dsn): self
    {
        $parts = parse_url($dsn);

        if ($parts === false || !isset($parts['host'])) {
            throw new ProtocolException("Malformed IMAP DSN: {$dsn}");
        }

        $query = [];
        parse_str($parts['query'] ?? '', $query);

        $scheme = strtolower($parts['scheme'] ?? 'imap');
        // imaps:// is shorthand for implicit TLS on 993; plain imap:// with no
        // explicit ?encryption stays plaintext rather than silently upgrading,
        // because a silent upgrade that fails looks like a network fault.
        $encryption = $query['encryption'] ?? ($scheme === 'imaps' ? 'ssl' : '');

        return new self([
            'host' => $parts['host'],
            'port' => (int) ($parts['port'] ?? ($encryption === 'ssl' ? 993 : 143)),
            'encryption' => $encryption,
            'username' => rawurldecode($parts['user'] ?? ''),
            'password' => rawurldecode($parts['pass'] ?? ''),
            'folder' => trim($parts['path'] ?? '/INBOX', '/') ?: 'INBOX',
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

        // The greeting is untagged and arrives unprompted. "* PREAUTH" means
        // the server has already authenticated us out-of-band (local socket,
        // client certificate) and LOGIN would be an error.
        $greeting = $this->readLine($literals);

        if (str_starts_with($greeting, '* PREAUTH')) {
            $this->authenticated = true;
        } elseif (!str_starts_with($greeting, '* OK')) {
            throw new TransportException("IMAP server refused the connection: {$greeting}");
        }

        $this->loadCapabilities();

        if ($this->config['encryption'] === 'tls') {
            $this->startTls();
        }

        if (!$this->authenticated) {
            $this->login();
        }

        if ($this->config['folder'] !== '') {
            $this->select((string) $this->config['folder']);
        }
    }

    public function disconnect(): void
    {
        if ($this->authenticated && $this->socket->isConnected()) {
            try {
                $this->command('LOGOUT');
            } catch (\Throwable $e) {
                // A server that drops the connection on LOGOUT instead of
                // answering is common enough that failing here would be noise.
            }
        }

        $this->socket->close();
        $this->authenticated = false;
        $this->mailbox = null;
    }

    /** Opens a folder and returns its state. Every folder-scoped call needs this first. */
    public function select(string $folder): Mailbox
    {
        $this->ensureConnected();

        $response = $this->command('SELECT ' . $this->quote($folder));

        $exists = 0;
        $recent = 0;
        $uidValidity = 0;
        $uidNext = 0;
        $flags = [];

        foreach ($response['lines'] as $line) {
            if (preg_match('/^\*\s+(\d+)\s+EXISTS/i', $line, $m)) {
                $exists = (int) $m[1];
            } elseif (preg_match('/^\*\s+(\d+)\s+RECENT/i', $line, $m)) {
                $recent = (int) $m[1];
            } elseif (preg_match('/\[UIDVALIDITY\s+(\d+)\]/i', $line, $m)) {
                $uidValidity = (int) $m[1];
            } elseif (preg_match('/\[UIDNEXT\s+(\d+)\]/i', $line, $m)) {
                $uidNext = (int) $m[1];
            } elseif (preg_match('/^\*\s+FLAGS\s+\((.*)\)/i', $line, $m)) {
                $flags = preg_split('/\s+/', trim($m[1])) ?: [];
            }
        }

        // SELECT reports [UNSEEN n] as the *sequence number of the first*
        // unseen message, not how many there are — a genuinely confusing part
        // of RFC 3501. A SEARCH is the only way to get the actual count.
        $unseen = count($this->search(['unseen' => true]));

        $this->mailbox = new Mailbox(
            name: $folder,
            flags: $flags,
            messageCount: $exists,
            recentCount: $recent,
            unseenCount: $unseen,
            uidValidity: $uidValidity,
            uidNext: $uidNext
        );

        return $this->mailbox;
    }

    /** @return Mailbox[] every folder on the server */
    public function mailboxes(string $pattern = '*'): array
    {
        $this->ensureConnected();

        $response = $this->command('LIST "" ' . $this->quote($pattern));
        $mailboxes = [];

        foreach ($response['lines'] as $line) {
            if (!preg_match('/^\*\s+LIST\s+\((.*?)\)\s+(?:"([^"]*)"|NIL)\s+(.+)$/i', $line, $m)) {
                continue;
            }

            $name = trim($m[3]);

            // The name is a quoted string unless it needed a literal, in which
            // case readLine() already inlined the bytes for us.
            if (str_starts_with($name, '"') && str_ends_with($name, '"')) {
                $name = stripslashes(substr($name, 1, -1));
            }

            $mailboxes[] = new Mailbox(
                name: $name,
                delimiter: $m[2] !== '' ? $m[2] : '/',
                flags: $m[1] !== '' ? (preg_split('/\s+/', trim($m[1])) ?: []) : []
            );
        }

        return $mailboxes;
    }

    /**
     * Server-side SEARCH, returning UIDs (not sequence numbers — sequence
     * numbers shift under you the moment anything is expunged).
     *
     * Criteria: unseen, seen, flagged, unflagged, answered, deleted, all
     * (bool); from, to, subject, body, text, keyword (string); since, before,
     * on (DateTimeInterface or a strtotime-able string).
     */
    public function search(array $criteria = []): array
    {
        $this->ensureConnected();

        $terms = [];

        foreach ($criteria as $key => $value) {
            $key = strtolower((string) $key);

            if (in_array($key, ['unseen', 'seen', 'flagged', 'unflagged', 'answered', 'deleted', 'draft', 'recent', 'new', 'old', 'all'], true)) {
                if ($value) {
                    $terms[] = strtoupper($key);
                }
                continue;
            }

            if (in_array($key, ['from', 'to', 'cc', 'bcc', 'subject', 'body', 'text', 'keyword'], true)) {
                $terms[] = strtoupper($key) . ' ' . $this->quote((string) $value);
                continue;
            }

            if (in_array($key, ['since', 'before', 'on', 'sentsince', 'sentbefore', 'senton'], true)) {
                $terms[] = strtoupper($key) . ' ' . $this->imapDate($value);
                continue;
            }

            throw new ProtocolException("Unknown IMAP search criterion: {$key}");
        }

        if ($terms === []) {
            $terms[] = 'ALL';
        }

        $response = $this->command('UID SEARCH ' . implode(' ', $terms));
        $uids = [];

        foreach ($response['lines'] as $line) {
            if (preg_match('/^\*\s+SEARCH\s*(.*)$/i', $line, $m) && trim($m[1]) !== '') {
                foreach (preg_split('/\s+/', trim($m[1])) ?: [] as $uid) {
                    if (ctype_digit($uid)) {
                        $uids[] = (int) $uid;
                    }
                }
            }
        }

        return $uids;
    }

    /**
     * One message by UID, fully parsed.
     *
     * BODY.PEEK[] rather than BODY[] — the latter is not a read-only fetch,
     * it sets \Seen as a side effect. Marking mail read just by looking at it
     * surprises callers, so peeking is the default and markSeen() is explicit.
     */
    public function fetch(int|string $id): ?Message
    {
        $this->ensureConnected();

        $response = $this->command('UID FETCH ' . (int) $id . ' (UID FLAGS RFC822.SIZE BODY.PEEK[])');

        // BODY[] is the only literal in this response — FLAGS and RFC822.SIZE
        // are plain atoms — so the first literal is the message source.
        if ($response['literals'] === []) {
            return null;
        }

        $flags = [];
        $size = 0;

        foreach ($response['lines'] as $line) {
            if (preg_match('/FLAGS\s+\(([^)]*)\)/i', $line, $m)) {
                $flags = $m[1] !== '' ? (preg_split('/\s+/', trim($m[1])) ?: []) : [];
            }
            if (preg_match('/RFC822\.SIZE\s+(\d+)/i', $line, $m)) {
                $size = (int) $m[1];
            }
        }

        return MimeParser::parseMessage($response['literals'][0], (int) $id, $flags, $size);
    }

    /**
     * Newest first, which is what a mail UI wants by default. UIDs ascend
     * with arrival, so sorting descending is enough — no server-side SORT
     * capability needed.
     *
     * @return Message[]
     */
    public function all(int $limit = 50, int $offset = 0): array
    {
        $uids = $this->search(['all' => true]);
        rsort($uids);

        $messages = [];

        foreach (array_slice($uids, $offset, $limit) as $uid) {
            $message = $this->fetch($uid);

            if ($message !== null) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    public function count(): int
    {
        $this->ensureConnected();

        // STATUS re-reads the count without disturbing the selected folder.
        // Some servers dislike STATUS on the mailbox that is currently
        // selected, so fall back to whatever SELECT last reported.
        try {
            $folder = $this->mailbox?->name ?? (string) $this->config['folder'];
            $response = $this->command('STATUS ' . $this->quote($folder) . ' (MESSAGES)');

            foreach ($response['lines'] as $line) {
                if (preg_match('/MESSAGES\s+(\d+)/i', $line, $m)) {
                    return (int) $m[1];
                }
            }
        } catch (\Throwable $e) {
            // fall through to the cached value
        }

        return $this->mailbox?->messageCount ?? 0;
    }

    public function markSeen(int|string $id): bool
    {
        return $this->store($id, '+FLAGS', '\Seen');
    }

    public function markUnseen(int|string $id): bool
    {
        return $this->store($id, '-FLAGS', '\Seen');
    }

    public function flag(int|string $id, string $flag = '\Flagged'): bool
    {
        return $this->store($id, '+FLAGS', $flag);
    }

    public function unflag(int|string $id, string $flag = '\Flagged'): bool
    {
        return $this->store($id, '-FLAGS', $flag);
    }

    /**
     * Marks \Deleted. Nothing is actually removed until expunge() — IMAP
     * deletion is two-phase by design, so this is reversible with unflag().
     */
    public function delete(int|string $id): bool
    {
        return $this->store($id, '+FLAGS', '\Deleted');
    }

    public function expunge(): void
    {
        $this->ensureConnected();
        $this->command('EXPUNGE');
    }

    /**
     * UID MOVE where the server advertises it, otherwise COPY + \Deleted,
     * which is what MOVE was standardised to replace (RFC 6851). The fallback
     * leaves the source marked deleted but not expunged — same two-phase rule
     * as delete().
     */
    public function move(int|string $id, string $folder): bool
    {
        $this->ensureConnected();

        if ($this->hasCapability('MOVE')) {
            $this->command('UID MOVE ' . (int) $id . ' ' . $this->quote($folder));

            return true;
        }

        $this->command('UID COPY ' . (int) $id . ' ' . $this->quote($folder));

        return $this->delete($id);
    }

    public function copy(int|string $id, string $folder): bool
    {
        $this->ensureConnected();
        $this->command('UID COPY ' . (int) $id . ' ' . $this->quote($folder));

        return true;
    }

    public function capabilities(): array
    {
        return $this->capabilities;
    }

    public function hasCapability(string $name): bool
    {
        return in_array(strtoupper($name), $this->capabilities, true);
    }

    public function mailbox(): ?Mailbox
    {
        return $this->mailbox;
    }

    protected function store(int|string $id, string $operation, string $flag): bool
    {
        $this->ensureConnected();
        $this->command('UID STORE ' . (int) $id . ' ' . $operation . ' (' . $flag . ')');

        return true;
    }

    protected function startTls(): void
    {
        if (!$this->hasCapability('STARTTLS')) {
            throw new TransportException(
                "Server at {$this->config['host']} does not advertise STARTTLS. " .
                "Use 'ssl' on port 993 for implicit TLS, or '' to accept an unencrypted session."
            );
        }

        $this->command('STARTTLS');
        $this->socket->enableCrypto();

        // Capabilities advertised before the upgrade are not trustworthy (an
        // active attacker could have stripped or added to them), and RFC 3501
        // requires re-issuing CAPABILITY afterwards anyway.
        $this->capabilities = [];
        $this->loadCapabilities();
    }

    protected function login(): void
    {
        // Quoted, not interpolated: a password containing " or \ is otherwise
        // a protocol error, and one containing CRLF would be command injection.
        $command = 'LOGIN ' . $this->quote((string) $this->config['username'])
            . ' ' . $this->quote((string) $this->config['password']);

        $response = $this->command($command, true);

        if ($response['status'] !== 'OK') {
            throw new AuthenticationException(
                "IMAP login failed for {$this->config['username']}@{$this->config['host']}: {$response['message']}"
            );
        }

        $this->authenticated = true;

        // The tagged OK for LOGIN often carries a fresh capability list, and
        // post-auth capabilities differ from pre-auth ones.
        $this->capabilities = [];
        $this->loadCapabilities();
    }

    protected function loadCapabilities(): void
    {
        if ($this->capabilities !== []) {
            return;
        }

        $response = $this->command('CAPABILITY', true);

        foreach ($response['lines'] as $line) {
            if (preg_match('/^\*\s+CAPABILITY\s+(.*)$/i', $line, $m)) {
                $this->capabilities = array_map('strtoupper', preg_split('/\s+/', trim($m[1])) ?: []);
            }
        }
    }

    protected function ensureConnected(): void
    {
        if (!$this->authenticated || !$this->socket->isConnected()) {
            $this->authenticated = false;
            $this->connect();
        }
    }

    /**
     * Sends a tagged command and reads until its tagged response arrives.
     *
     * $tolerant returns NO/BAD to the caller instead of throwing — used by
     * login() (which raises AuthenticationException instead) and CAPABILITY
     * (which is optional on some servers).
     */
    protected function command(string $command, bool $tolerant = false): array
    {
        $tag = $this->nextTag();
        $this->socket->write($tag . ' ' . $command);

        $lines = [];
        $literals = [];

        while (true) {
            $line = $this->readLine($collected);
            $literals = array_merge($literals, $collected);

            if (str_starts_with($line, $tag . ' ')) {
                $status = strtoupper(strtok(substr($line, strlen($tag) + 1), ' ') ?: '');
                $message = trim(substr($line, strlen($tag) + 1 + strlen($status)));

                if (!$tolerant && $status !== 'OK') {
                    throw new ProtocolException("IMAP {$status} for '{$this->redact($command)}': {$message}");
                }

                return ['status' => $status, 'message' => $message, 'lines' => $lines, 'literals' => $literals];
            }

            // A "+" continuation means the server wants more input. We never
            // send literals ourselves — everything is quoted — so this can
            // only mean the command was malformed; bail rather than deadlock.
            if (str_starts_with($line, '+')) {
                throw new ProtocolException("IMAP server asked for continuation data unexpectedly: {$line}");
            }

            $lines[] = $line;
        }
    }

    /**
     * One logical response line, with any literals spliced in.
     *
     * A line ending in {1234} is not the whole response: exactly 1234 octets
     * follow, and those octets can contain CRLF, so they cannot be read
     * line-wise. After the literal the response continues on the same logical
     * line. Reading this wrong is the single most common bug in hand-written
     * IMAP clients — it truncates every message over one buffer in size.
     *
     * $literals is filled with the raw literal payloads in order, so callers
     * like fetch() get the exact bytes rather than having to find them again
     * inside the reassembled line.
     */
    protected function readLine(?array &$literals = null): string
    {
        $literals = [];
        $line = $this->socket->readLine();

        while (preg_match('/\{(\d+)\}$/', $line, $m)) {
            $payload = $this->socket->readBytes((int) $m[1]);
            $literals[] = $payload;
            $line = substr($line, 0, -strlen($m[0])) . $payload . $this->socket->readLine();
        }

        return $line;
    }

    protected function nextTag(): string
    {
        return sprintf('A%04d', ++$this->sequence);
    }

    /**
     * Wraps a value as an IMAP quoted string.
     *
     * The CR/LF strip is the security-relevant part, not cosmetics: a folder
     * name or search term carrying a newline would otherwise terminate the
     * current command and inject a second one the caller never wrote.
     */
    protected function quote(string $value): string
    {
        $value = str_replace(["\r", "\n", "\0"], '', $value);

        return '"' . addcslashes($value, '"\\') . '"';
    }

    protected function imapDate(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('j-M-Y');
        }

        $timestamp = is_int($value) ? $value : strtotime((string) $value);

        if ($timestamp === false) {
            throw new ProtocolException('Could not read a date from: ' . var_export($value, true));
        }

        return date('j-M-Y', $timestamp);
    }

    /** Keeps credentials out of exception messages and stack traces. */
    protected function redact(string $command): string
    {
        return preg_replace('/^(LOGIN\s+\S+\s+).*$/i', '$1***', $command) ?? $command;
    }

    public function __destruct()
    {
        $this->socket->close();
    }
}
