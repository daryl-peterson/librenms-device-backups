<?php

namespace DRP\DeviceBackups\Console;

use DRP\DeviceBackups\Backup\BackupFailure;
use DRP\DeviceBackups\Backup\BackupStore;
use Illuminate\Console\Command;
use Throwable;

final class ExportBackup extends Command {
    protected $signature = 'device-backups:export {result : Successful backup result ID} {path : New ZIP filename}';

    protected $description = 'Export and verify an encrypted backup stored in the backup database';

    public function handle(): int {
        $id = filter_var($this->argument('result'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id) {
            $this->error('Enter a valid backup result ID.');
            return self::FAILURE;
        }

        $previousUmask = umask(0077);
        try {
            (new BackupStore())->export($id, $this->argument('path'));
        } catch (BackupFailure $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Could not export the backup from the database.');
            return self::FAILURE;
        } finally {
            umask($previousUmask);
        }

        $this->info('Encrypted backup exported and checksum verified.');
        return self::SUCCESS;
    }
}
