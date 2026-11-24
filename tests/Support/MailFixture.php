<?php

namespace DRP\DeviceBackups\Tests\Support;

final class MailFixture {
    public static array $messages = [];
    public static bool $delivered = true;

    public static function send(string $recipient, string $subject, string $body, bool ...$options): bool {
        self::$messages[] = compact('recipient', 'subject', 'body');

        return self::$delivered;
    }
}
