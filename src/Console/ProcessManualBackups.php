<?php

namespace DRP\DeviceBackups\Console;

use DRP\DeviceBackups\Backup\ManualBackupRequests;
use DRP\DeviceBackups\Log;
use DRP\DeviceBackups\PluginDb;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

final class ProcessManualBackups extends Command {
    private const MIGRATIONS_REQUIRED = 'The manual backup queue schema is missing. Run the Device Backups migrations on the device_backups connection.';

    protected $signature = 'device-backups:process-requests
        {--check : Check the manual backup queue schema without processing any requests}';
    protected $description = 'Run the next manually requested device backup';

    public function handle(ManualBackupRequests $requests): int {
        try {
            if ($this->option('check')) {
                if (!Schema::connection(PluginDb::getDbConnection())->hasColumns('device_backup_runs', [
                    'requested_device_id', 'requested_at', 'request_error',
                ])) {
                    $this->error(self::MIGRATIONS_REQUIRED);
                    return self::FAILURE;
                }

                $requests->next();
                $this->info('The manual backup queue is readable.');
                return self::SUCCESS;
            }

            $requests->expireInterrupted();
            $next = $requests->next();
        } catch (QueryException $exception) {
            $state = (string) ($exception->errorInfo[0] ?? $exception->getCode());
            $code = (int) ($exception->errorInfo[1] ?? $exception->getCode());
            $message = match (true) {
                in_array($code, [1045, 1698], true) => "The backup database rejected the login (database error $code). Verify the credentials in Device Backups Settings.",
                in_array($state, ['42S02', '42S22'], true) => self::MIGRATIONS_REQUIRED,
                default => "Could not access the manual backup queue (database error $code, SQLSTATE $state). Check the backup database connection and permissions.",
            };
            $this->error($message);
            Log::error($message);
            return self::FAILURE;
        }

        return $next ? $this->call(RunBackups::class, [
            'device' => (string) $next->requested_device_id,
            '--request' => (string) $next->id,
        ]) : self::SUCCESS;
    }
}
