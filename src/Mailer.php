<?php

namespace Laika\Mailman;

use Laika\Mailman\Interfaces\MailerInterface;
use Laika\Mailman\Exceptions\AuthenticationException;
use Laika\Mailman\Exceptions\MailmanException;
use Laika\Mailman\Exceptions\TransportException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\OAuthTokenProvider;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Fluent sending on top of PHPMailer.
 *
 * The point of the wrapper is that callers never touch PHPMailer's untyped
 * public properties directly: configuration is an array (or a DSN), the build
 * methods chain, and every failure arrives as a Laika\Mailman\Exceptions\*
 * so there is one hierarchy to catch rather than two.
 *
 * Reading mail is not here and cannot be — PHPMailer is send-only. See
 * ImapReader and Pop3Reader.
 */
class Mailer implements MailerInterface
{
    protected PHPMailer $mail;
    protected array $config;

    /**
     * Config keys: driver ('smtp'|'mail'|'sendmail'|'qmail'), host, port,
     * username, password, encryption ('tls'|'ssl'|''), from, from_name,
     * charset, timeout, debug, keepalive, auto_tls, validate_cert.
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'driver' => 'smtp',
            'host' => 'localhost',
            'port' => 587,
            'username' => '',
            'password' => '',
            'encryption' => 'tls',
            'from' => '',
            'from_name' => '',
            'charset' => PHPMailer::CHARSET_UTF8,
            'timeout' => 30,
            'debug' => 0,
            'keepalive' => false,
            'auto_tls' => true,
            'validate_cert' => true,
        ], $config);

        // true = throw on failure. We catch those and rethrow as our own
        // types, so the flag is not optional here — without it PHPMailer
        // reports failures only as a false return plus ErrorInfo, and the
        // reason for a failed send gets lost at the first call site that
        // forgets to check.
        $this->mail = new PHPMailer(true);

        $this->applyConfig();
    }

    /**
     * smtp://user:pass@host:587?encryption=tls
     *
     * This builds the PHPMailer itself instead of using PHPMailer's bundled
     * DSNConfigurator, which has a real bug: for the smtps:// scheme it sets
     * SMTPSecure to ENCRYPTION_STARTTLS ('tls') while defaulting the port to
     * 465 (DSNConfigurator.php:140-144). 465 is the implicit-TLS port and
     * needs 'ssl'; that pairing fails against most servers.
     */
    public static function fromDsn(string $dsn, array $overrides = []): self
    {
        $parts = parse_url($dsn);

        if ($parts === false || !isset($parts['scheme'])) {
            throw new MailmanException("Malformed mail DSN: {$dsn}");
        }

        $query = [];
        parse_str($parts['query'] ?? '', $query);

        $scheme = strtolower($parts['scheme']);
        $driver = in_array($scheme, ['mail', 'sendmail', 'qmail'], true) ? $scheme : 'smtp';
        $encryption = $query['encryption'] ?? ($scheme === 'smtps' ? 'ssl' : 'tls');
        $defaultPort = $encryption === 'ssl' ? 465 : 587;

        return new self(array_merge([
            'driver' => $driver,
            'host' => $parts['host'] ?? 'localhost',
            'port' => (int) ($parts['port'] ?? $defaultPort),
            'username' => rawurldecode($parts['user'] ?? ''),
            'password' => rawurldecode($parts['pass'] ?? ''),
            'encryption' => $encryption,
        ], $query, $overrides));
    }

    public function from(string $address, string $name = ''): static
    {
        $this->guard(fn () => $this->mail->setFrom($address, $name));

        return $this;
    }

    public function to(string $address, string $name = ''): static
    {
        $this->guard(fn () => $this->mail->addAddress($address, $name));

        return $this;
    }

    public function cc(string $address, string $name = ''): static
    {
        $this->guard(fn () => $this->mail->addCC($address, $name));

        return $this;
    }

    public function bcc(string $address, string $name = ''): static
    {
        $this->guard(fn () => $this->mail->addBCC($address, $name));

        return $this;
    }

    public function replyTo(string $address, string $name = ''): static
    {
        $this->guard(fn () => $this->mail->addReplyTo($address, $name));

        return $this;
    }

    public function subject(string $subject): static
    {
        $this->mail->Subject = $subject;

        return $this;
    }

    /**
     * HTML body, with an optional plaintext alternative. Passing the plaintext
     * matters more than it looks: a multipart/alternative with a real text
     * part scores better with spam filters than HTML alone.
     */
    public function body(string $body, string $plainText = ''): static
    {
        $this->mail->isHTML(true);
        $this->mail->Body = $body;

        // html2text() is a reasonable fallback, but a hand-written plaintext
        // version is always better — hence the explicit parameter.
        $this->mail->AltBody = $plainText !== '' ? $plainText : $this->mail->html2text($body);

        return $this;
    }

    /** Alias of body(), for call sites where the intent reads better. */
    public function html(string $body, string $plainText = ''): static
    {
        return $this->body($body, $plainText);
    }

    public function text(string $body): static
    {
        $this->mail->isHTML(false);
        $this->mail->Body = $body;
        $this->mail->AltBody = '';

        return $this;
    }

    public function attach(string $path, string $name = ''): static
    {
        $this->guard(fn () => $this->mail->addAttachment($path, $name));

        return $this;
    }

    /** Attach bytes you already hold, without writing a temp file first. */
    public function attachData(string $contents, string $filename, string $mimeType = ''): static
    {
        $this->guard(fn () => $this->mail->addStringAttachment(
            $contents,
            $filename,
            PHPMailer::ENCODING_BASE64,
            $mimeType
        ));

        return $this;
    }

    /** Embed an image and reference it from the HTML body as <img src="cid:$cid">. */
    public function embed(string $path, string $cid, string $name = ''): static
    {
        $this->guard(fn () => $this->mail->addEmbeddedImage($path, $cid, $name));

        return $this;
    }

    public function priority(int $priority): static
    {
        $this->mail->Priority = $priority;

        return $this;
    }

    public function header(string $name, string $value): static
    {
        $this->guard(fn () => $this->mail->addCustomHeader($name, $value));

        return $this;
    }

    /** XOAUTH2. See the README on implementing OAuthTokenProvider yourself. */
    public function oauth(OAuthTokenProvider $provider): static
    {
        $this->mail->AuthType = 'XOAUTH2';
        $this->mail->setOAuth($provider);

        return $this;
    }

    public function send(): bool
    {
        return $this->guard(fn (): bool => $this->mail->send());
    }

    /**
     * Several distinct mails over one SMTP connection.
     *
     * Each entry is a callable receiving this mailer to build one message;
     * recipients and attachments are cleared between them. SMTPKeepAlive is
     * forced on for the duration, which is the whole point — reconnecting and
     * re-authenticating per message is what makes bulk sending slow, and some
     * providers rate-limit on connections rather than messages.
     *
     * @param  callable[] $builders
     * @return array<int,bool> results in the order given
     */
    public function sendMany(array $builders): array
    {
        $previous = $this->mail->SMTPKeepAlive;
        $this->mail->SMTPKeepAlive = true;

        $results = [];

        try {
            foreach ($builders as $index => $builder) {
                $this->reset();
                $builder($this);

                // One bad recipient shouldn't abort the rest of the batch —
                // record the failure and keep going.
                try {
                    $results[$index] = $this->send();
                } catch (MailmanException $e) {
                    $results[$index] = false;
                }
            }
        } finally {
            $this->mail->SMTPKeepAlive = $previous;
            $this->mail->smtpClose();
        }

        return $results;
    }

    /**
     * Clears recipients, attachments and custom headers so one instance can
     * send a second, unrelated message. The From address and all transport
     * config survive, since those are configuration rather than message state.
     */
    public function reset(): static
    {
        $this->mail->clearAllRecipients();
        $this->mail->clearAttachments();
        $this->mail->clearCustomHeaders();
        $this->mail->Subject = '';
        $this->mail->Body = '';
        $this->mail->AltBody = '';

        if ($this->config['from'] !== '') {
            $this->guard(fn () => $this->mail->setFrom(
                (string) $this->config['from'],
                (string) $this->config['from_name']
            ));
        }

        return $this;
    }

    public function lastError(): string
    {
        return $this->mail->ErrorInfo;
    }

    /** Escape hatch for the handful of PHPMailer settings not exposed here. */
    public function phpMailer(): PHPMailer
    {
        return $this->mail;
    }

    protected function applyConfig(): void
    {
        match ((string) $this->config['driver']) {
            'smtp' => $this->mail->isSMTP(),
            'sendmail' => $this->mail->isSendmail(),
            'qmail' => $this->mail->isQmail(),
            default => $this->mail->isMail(),
        };

        // PHPMailer 7 still defaults CharSet to ISO-8859-1 (PHPMailer.php:78).
        // Setting UTF-8 explicitly is not optional in practice — without it
        // every non-ASCII subject and body arrives as mojibake.
        $this->mail->CharSet = (string) $this->config['charset'];
        $this->mail->Encoding = PHPMailer::ENCODING_BASE64;

        if ($this->config['driver'] === 'smtp') {
            $this->mail->Host = (string) $this->config['host'];
            $this->mail->Port = (int) $this->config['port'];
            $this->mail->Timeout = (int) $this->config['timeout'];
            $this->mail->SMTPAutoTLS = (bool) $this->config['auto_tls'];
            $this->mail->SMTPKeepAlive = (bool) $this->config['keepalive'];
            $this->mail->SMTPDebug = (int) $this->config['debug'];

            // Empty string means "no encryption" and must stay empty — passing
            // it through to SMTPSecure as anything else makes PHPMailer try to
            // negotiate TLS against a server that isn't offering it.
            $this->mail->SMTPSecure = match (strtolower((string) $this->config['encryption'])) {
                'tls', 'starttls' => PHPMailer::ENCRYPTION_STARTTLS,
                'ssl', 'smtps' => PHPMailer::ENCRYPTION_SMTPS,
                default => '',
            };

            if ($this->config['username'] !== '') {
                $this->mail->SMTPAuth = true;
                $this->mail->Username = (string) $this->config['username'];
                $this->mail->Password = (string) $this->config['password'];
            }

            if (!$this->config['validate_cert']) {
                // Turning verification off accepts any certificate, including
                // an attacker's. Only for a dev server you control.
                $this->mail->SMTPOptions = ['ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ]];
            }
        }

        if ($this->config['from'] !== '') {
            $this->guard(fn () => $this->mail->setFrom(
                (string) $this->config['from'],
                (string) $this->config['from_name']
            ));
        }
    }

    /**
     * Runs a PHPMailer call and translates its exception into ours.
     *
     * The classification is by message text because PHPMailer's Exception
     * carries no distinguishing type or code for these cases — it is one
     * class for every failure. Matching on the text is imperfect, but the
     * alternative is making the caller parse the string themselves.
     */
    protected function guard(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (PHPMailerException $e) {
            $message = $e->getMessage();

            if (stripos($message, 'authenticate') !== false || stripos($message, 'Username and Password') !== false) {
                throw new AuthenticationException("SMTP authentication failed: {$message}", (int) $e->getCode(), $e);
            }

            if (stripos($message, 'connect') !== false || stripos($message, 'Could not instantiate') !== false) {
                throw new TransportException("Mail transport failed: {$message}", (int) $e->getCode(), $e);
            }

            throw new MailmanException($message, (int) $e->getCode(), $e);
        }
    }
}
