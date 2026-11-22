<?php

namespace DRP\DeviceBackups\Backup;

/**
 * LibreNMS Device Backups SSH Failure.
 * Represents an SSH connection failure for device backups.
 *
 * @package     librenms-device-backups
 * @author      Daryl Peterson <daryl.peterson@gmail.com>
 * @copyright   Copyright (c) 2026, Daryl Peterson
 * @license     https://opensource.org MIT License
 * @link        https://github.com/daryl-peterson/
 * @since       0.0.1
 */
final class SshFailure extends BackupFailure {
    public function __construct(public readonly string $status, string $message) {
        parent::__construct($message);
    }
}
