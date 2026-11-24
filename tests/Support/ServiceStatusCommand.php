<?php

namespace DRP\DeviceBackups\Tests\Support;

use Throwable;

final class ServiceStatusCommand {
    public static string|Throwable|null $output = null;
    public static array $commands = [];
}

namespace DRP\DeviceBackups;

use DRP\DeviceBackups\Tests\Support\ServiceStatusCommand;
use Throwable;

// Loaded only by isolated tests to intercept the helper's shell command.
function shell_exec(string $command): ?string {
    ServiceStatusCommand::$commands[] = $command;
    $output = ServiceStatusCommand::$output;
    if ($output instanceof Throwable) {
        throw $output;
    }

    return $output;
}
