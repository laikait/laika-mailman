<?php

namespace Laika\Mailman\Transport;

use Laika\Mailman\Exceptions\TransportException;

/**
 * A CRLF line-protocol socket, shared by ImapReader and Pop3Reader.
 *
 * Both protocols are the same shape underneath — connect, optionally upgrade
 * to TLS, write a line, read lines back — so the socket handling lives here
 * once instead of being copy-pasted into each reader. The readers stay pure
 * protocol logic.
 *
 * The one non-obvious member is readBytes(): IMAP literals ({1234} followed
 * by exactly 1234 octets) cannot be read line-wise, because the octets may
 * contain bare LFs or a CRLF that is part of the payload rather than a line
 * terminator. Only the byte count is authoritative.
 */
class Socket
{
    /** @var resource|null */
    protected $stream = null;

    protected string $host;
    protected int $port;
    protected string $encryption;
    protected int $timeout;
    protected array $streamOptions;

    /**
     * $encryption is one of:
     *   'ssl' — implicit TLS from the first byte (IMAPS 993, POP3S 995)
     *   'tls' — connect in the clear, then upgrade via STARTTLS/STLS, which
     *           the reader triggers by calling enableCrypto()
     *   ''    — plaintext, no encryption at all
     *
     * $streamOptions is merged into the 'ssl' stream context. Peer
     * verification is on by default; passing ['verify_peer' => false,
     * 'verify_peer_name' => false] disables it for self-signed dev servers,
     * which also disables any protection against an active MITM — only do it
     * against a server you control on a network you trust.
     */
    public function __construct(string $host, int $port, string $encryption = 'ssl', int $timeout = 30, array $streamOptions = [])
    {
        $this->host = $host;
        $this->port = $port;
        $this->encryption = strtolower($encryption);
        $this->timeout = $timeout;
        $this->streamOptions = $streamOptions;
    }

    public function connect(): void
    {
        if ($this->stream) {
            return;
        }

        // Only 'ssl' gets the ssl:// prefix. 'tls' has to start plaintext,
        // because STARTTLS is negotiated *inside* the cleartext session.
        $prefix = $this->encryption === 'ssl' ? 'ssl://' : '';
        $context = stream_context_create(['ssl' => $this->sslOptions()]);

        $errno = 0;
        $errstr = '';

        $stream = @stream_socket_client(
            $prefix . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$stream) {
            throw new TransportException(
                "Could not connect to {$prefix}{$this->host}:{$this->port} — {$errstr} (errno {$errno})"
            );
        }

        $this->stream = $stream;
        stream_set_timeout($this->stream, $this->timeout);
    }

    /**
     * Upgrade an already-open plaintext connection to TLS. Called after the
     * server has answered OK to STARTTLS (IMAP) or STLS (POP3) — never
     * before, and never with credentials already on the wire.
     */
    public function enableCrypto(): void
    {
        if (!$this->stream) {
            throw new TransportException('Cannot enable crypto: not connected.');
        }

        // STREAM_CRYPTO_METHOD_TLS_CLIENT is a floor, not an exact version —
        // PHP negotiates the best mutually supported TLS version from it.
        $ok = @stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

        if ($ok !== true) {
            $error = error_get_last()['message'] ?? 'handshake failed';
            throw new TransportException("TLS upgrade to {$this->host}:{$this->port} failed — {$error}");
        }
    }

    public function write(string $line): void
    {
        if (!$this->stream) {
            throw new TransportException('Cannot write: not connected.');
        }

        $payload = $line . "\r\n";
        $written = @fwrite($this->stream, $payload);

        if ($written === false || $written < strlen($payload)) {
            throw new TransportException("Failed writing to {$this->host}:{$this->port} (connection closed?).");
        }
    }

    /**
     * One response line, CRLF stripped. Distinguishes a read timeout from a
     * clean EOF because they mean very different things when debugging: a
     * timeout is usually a firewall or a wrong port, an EOF is usually the
     * server rejecting us and hanging up.
     */
    public function readLine(): string
    {
        if (!$this->stream) {
            throw new TransportException('Cannot read: not connected.');
        }

        $line = @fgets($this->stream, 8192);

        if ($line === false) {
            if ((stream_get_meta_data($this->stream)['timed_out'] ?? false)) {
                throw new TransportException("Timed out after {$this->timeout}s reading from {$this->host}:{$this->port}.");
            }

            throw new TransportException("Connection to {$this->host}:{$this->port} closed unexpectedly.");
        }

        return rtrim($line, "\r\n");
    }

    /**
     * Exactly $count octets, no more. fread() returns short reads on a socket
     * whenever the kernel buffer runs dry mid-literal, so this has to loop —
     * a single fread($stream, $count) silently truncates large message bodies
     * and is the classic bug in hand-rolled IMAP clients.
     */
    public function readBytes(int $count): string
    {
        if (!$this->stream) {
            throw new TransportException('Cannot read: not connected.');
        }

        $buffer = '';

        while (strlen($buffer) < $count) {
            $chunk = @fread($this->stream, $count - strlen($buffer));

            if ($chunk === false || $chunk === '') {
                if ((stream_get_meta_data($this->stream)['timed_out'] ?? false)) {
                    throw new TransportException("Timed out after {$this->timeout}s reading a {$count}-byte literal.");
                }

                throw new TransportException(
                    'Connection closed mid-literal: expected ' . $count . ' bytes, got ' . strlen($buffer) . '.'
                );
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }

    public function isConnected(): bool
    {
        return $this->stream !== null && !feof($this->stream);
    }

    public function close(): void
    {
        if ($this->stream) {
            @fclose($this->stream);
            $this->stream = null;
        }
    }

    protected function sslOptions(): array
    {
        return array_merge([
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'SNI_enabled' => true,
            'peer_name' => $this->host,
        ], $this->streamOptions);
    }

    public function __destruct()
    {
        $this->close();
    }
}
