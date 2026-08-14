<?php

namespace Laika\Mailman\Exceptions;

/**
 * Credentials were rejected — IMAP answered NO to LOGIN, POP3 answered -ERR
 * to PASS, or SMTP refused AUTH. Split out from ProtocolException because
 * this is the one failure callers routinely want to handle differently
 * (re-prompt for a password rather than retry).
 */
class AuthenticationException extends MailmanException
{
}
