<?php

namespace Laika\Mailman\Exceptions;

/**
 * The server understood us and said no: IMAP NO/BAD, POP3 -ERR. The message
 * carries the server's own text verbatim, because that text is usually the
 * only useful diagnostic (e.g. Gmail's "[ALERT] Application-specific password
 * required" arrives this way).
 */
class ProtocolException extends MailmanException
{
}
