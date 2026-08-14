<?php

namespace Laika\Mailman\Pipeline\Source;

use Laika\Mailman\Interfaces\MessageSourceInterface;
use Laika\Mailman\Exceptions\MailmanException;
use Laika\Mailman\Mime\MimeParser;
use Laika\Mailman\Message;

/**
 * One raw message read from STDIN — the classic "email pipe".
 *
 * Postfix (transport_maps / a .forward file), Exim, and cPanel's "Pipe to a
 * Program" all deliver by executing your script and writing the complete
 * message to its standard input. That is lower latency than polling, since
 * the pipeline runs at delivery rather than on a schedule.
 *
 * Two things about piped scripts that are easy to get wrong, both handled by
 * this class and its example:
 *
 *   1. The MTA reads your *exit code*. A non-zero exit makes it treat
 *      delivery as failed and bounce the mail back to the sender — so a
 *      crashed handler turns into a confusing bounce for a real person. The
 *      pipeline already contains handler errors; make sure the wrapper script
 *      exits 0 as well.
 *   2. There is no mailbox to act on afterwards, so complete() is a no-op.
 *      Delivery already happened; the post-action has nothing to apply to.
 */
class StdinSource implements MessageSourceInterface
{
    /** @var resource */
    protected $stream;

    protected int $maxBytes;

    /**
     * $maxBytes caps how much will be read. A mail server will normally have
     * its own message size limit well below this, but a pipe is an untrusted
     * input and an unbounded read is an easy way to exhaust memory.
     */
    public function __construct($stream = null, int $maxBytes = 52428800)
    {
        $this->stream = $stream ?? STDIN;
        $this->maxBytes = $maxBytes;
    }

    public function messages(): iterable
    {
        $raw = stream_get_contents($this->stream, $this->maxBytes);

        if ($raw === false || trim($raw) === '') {
            throw new MailmanException('Nothing arrived on STDIN — this source expects a raw message piped by the MTA.');
        }

        yield MimeParser::parseMessage($raw);
    }

    /** Nothing to mark, move or delete: the MTA already delivered this. */
    public function complete(Message $message, string $action, string $folder = ''): void
    {
    }

    public function finish(): void
    {
    }

    public function describe(): string
    {
        return 'STDIN pipe';
    }
}
