<?php

namespace Laika\Mailman\Pipeline\Source;

use Laika\Mailman\Interfaces\MessageSourceInterface;
use Laika\Mailman\Mime\MimeParser;
use Laika\Mailman\Message;

/**
 * Messages from raw strings or .eml files held in memory.
 *
 * The testing seam: it lets extraction rules and handlers be exercised end to
 * end with no mailbox, no network and no MTA. Also useful for replaying a
 * message that failed in production against a modified rule set.
 *
 * complete() records what would have been applied rather than doing anything,
 * so a test can assert the post-action without a live server.
 */
class RawSource implements MessageSourceInterface
{
    /** @var string[] */
    protected array $raw;

    /** @var array<int,array{subject:string,action:string,folder:string}> */
    protected array $completed = [];

    protected bool $finished = false;

    /** @param string[] $raw complete RFC 2822 message sources */
    public function __construct(array $raw)
    {
        $this->raw = $raw;
    }

    /** @param string[] $paths .eml files on disk */
    public static function fromFiles(array $paths): self
    {
        return new self(array_map(
            static fn (string $path): string => (string) file_get_contents($path),
            $paths
        ));
    }

    public function messages(): iterable
    {
        foreach ($this->raw as $index => $source) {
            // The index doubles as a uid so complete() has something stable
            // to key on, mirroring what a real source provides.
            yield MimeParser::parseMessage($source, $index + 1);
        }
    }

    public function complete(Message $message, string $action, string $folder = ''): void
    {
        $this->completed[] = [
            'subject' => $message->subject,
            'action' => $action,
            'folder' => $folder,
        ];
    }

    public function finish(): void
    {
        $this->finished = true;
    }

    public function describe(): string
    {
        return 'raw (' . count($this->raw) . ' message' . (count($this->raw) === 1 ? '' : 's') . ')';
    }

    /** What complete() was called with, in order — for assertions. */
    public function completed(): array
    {
        return $this->completed;
    }

    public function wasFinished(): bool
    {
        return $this->finished;
    }
}
