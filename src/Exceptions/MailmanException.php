<?php

namespace Laika\Mailman\Exceptions;

/**
 * Base for everything this package throws. Sending wraps PHPMailer's own
 * exception in one of these subclasses so callers only ever have to catch
 * a single hierarchy — never a mix of Laika\Mailman\Exceptions\* and
 * PHPMailer\PHPMailer\Exception.
 */
class MailmanException extends \RuntimeException
{
}
