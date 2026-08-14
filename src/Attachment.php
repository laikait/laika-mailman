<?php

namespace Laika\Mailman;

/**
 * One decoded attachment from an inbound message. The content is already
 * base64/quoted-printable decoded by MimeParser, so it is the raw bytes —
 * write it straight to disk.
 */
class Attachment
{
    public function __construct(
        public readonly string $filename,
        public readonly string $mimeType,
        public readonly int $size,
        public readonly string $content,
        public readonly ?string $contentId = null,
        public readonly string $disposition = 'attachment'
    ) {
    }

    /**
     * True for images referenced from the HTML body as cid:..., false for
     * ordinary file attachments. Worth checking before you offer something as
     * a download — inline parts are usually tracking pixels or signature
     * logos the user never asked to see as files.
     */
    public function isInline(): bool
    {
        return $this->disposition === 'inline' || $this->contentId !== null;
    }

    public function saveTo(string $path): bool
    {
        // A directory target is the common call shape, so accept it and
        // append our own filename rather than making the caller concatenate.
        if (is_dir($path)) {
            $path = rtrim($path, "/\\") . DIRECTORY_SEPARATOR . $this->safeFilename();
        }

        return file_put_contents($path, $this->content) !== false;
    }

    /**
     * The filename as the *sender* wrote it is untrusted input: it can contain
     * "../", absolute paths, or NUL bytes. Anything that lands on a filesystem
     * goes through here first.
     */
    public function safeFilename(): string
    {
        $name = str_replace("\0", '', basename(str_replace('\\', '/', $this->filename)));
        $name = preg_replace('/[^\w.\- ]+/u', '_', $name) ?? '';
        $name = trim($name, '. ');

        return $name === '' ? 'attachment.bin' : $name;
    }
}
