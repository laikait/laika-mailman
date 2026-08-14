<?php

namespace Laika\Mailman\Interfaces;

/**
 * The sending contract. Kept small and fluent so a mailer can be swapped for
 * a fake in tests without reimplementing PHPMailer's whole surface.
 */
interface MailerInterface
{
    public function to(string $address, string $name = ''): static;

    public function subject(string $subject): static;

    public function body(string $body, string $plainText = ''): static;

    public function attach(string $path, string $name = ''): static;

    public function send(): bool;

    public function reset(): static;

    public function lastError(): string;
}
