<?php

namespace DRP\DeviceBackups\Backup;

final class SshFailure extends BackupFailure {
    public function __construct(public readonly string $status, string $message) {
        parent::__construct($message);
    }
}
