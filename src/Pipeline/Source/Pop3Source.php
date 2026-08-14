<?php

namespace Laika\Mailman\Pipeline\Source;

use Laika\Mailman\Interfaces\MessageSourceInterface;
use Laika\Mailman\Exceptions\MailmanException;
use Laika\Mailman\Pipeline\Pipeline;
use Laika\Mailman\Reader\Pop3Reader;
use Laika\Mailman\Message;

/**
 * Polls a mailbox over POP3, for the case where that is all the server offers.
 *
 * POP3 has no flags and no folders, so MARK_SEEN and MOVE are impossible
 * here. Rather than accept them and silently do nothing — which would make
 * the pipeline reprocess the same mail on every run, forever — this rejects
 * them loudly. The workable configurations are DELETE (destructive but
 * idempotent) and LEAVE (with your own bookkeeping, keyed on uidl()).
 */
class Pop3Source implements MessageSourceInterface
{
    protected Pop3Reader $reader;
    protected int $limit;
    protected bool $connected = false;
    protected bool $deleted = false;

    public function __construct(Pop3Reader $reader, int $limit = 50)
    {
        $this->reader = $reader;
        $this->limit = $limit;
    }

    public function messages(): iterable
    {
        $this->ensureConnected();

        $numbers = array_keys($this->reader->listing());
        rsort($numbers);

        foreach (array_slice($numbers, 0, $this->limit) as $number) {
            $message = $this->reader->fetch($number);

            if ($message !== null) {
                yield $message;
            }
        }
    }

    public function complete(Message $message, string $action, string $folder = ''): void
    {
        if (in_array($action, [Pipeline::MARK_SEEN, Pipeline::MOVE], true)) {
            throw new MailmanException(
                "Pop3Source cannot apply '{$action}': POP3 has no flags or folders. " .
                'Use Pipeline::DELETE, or Pipeline::LEAVE and track processed mail yourself via Pop3Reader::uidl().'
            );
        }

        if ($action === Pipeline::DELETE && $message->uid !== null) {
            $this->reader->delete($message->uid);
            $this->deleted = true;
        }
    }

    public function finish(): void
    {
        // POP3 only applies DELE when the session enters UPDATE state, which
        // happens on QUIT — so expunge() ends the session. Skip it entirely
        // when nothing was marked, to leave the connection reusable.
        if ($this->deleted) {
            $this->reader->expunge();
        }
    }

    public function describe(): string
    {
        return 'POP3 mailbox';
    }

    protected function ensureConnected(): void
    {
        if (!$this->connected) {
            $this->reader->connect();
            $this->connected = true;
        }
    }
}
