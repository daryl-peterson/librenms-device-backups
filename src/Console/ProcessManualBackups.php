<?php

namespace DRP\DeviceBackups\Console;

use DRP\DeviceBackups\Backup\ManualBackupRequests;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

final class ProcessManualBackups extends Command {
    protected $signature = 'device-backups:process-requests';
    protected $description = 'Run the next manually requested device backup';

    public function handle(ManualBackupRequests $requests): int {
        try {
            $requests->expireInterrupted();
            $next = $requests->next();
        } catch (QueryException) {
            $this->error('Could not read manual requests from the backup database.');
            return self::FAILURE;
        }

        return $next ? $this->call(RunBackups::class, [
            'device' => (string) $next->requested_device_id,
            '--request' => (string) $next->id,
        ]) : self::SUCCESS;
    }
}
