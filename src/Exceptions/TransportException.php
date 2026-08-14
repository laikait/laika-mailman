<?php

namespace Laika\Mailman\Exceptions;

/**
 * The connection itself failed: DNS/TCP refused, TLS handshake rejected,
 * read timeout, or the peer hung up mid-session. Distinct from
 * ProtocolException, which means the connection worked fine and the server
 * simply refused the command.
 */
class TransportException extends MailmanException
{
}
