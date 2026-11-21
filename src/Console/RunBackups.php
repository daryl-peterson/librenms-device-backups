<?php

namespace DRP\DeviceBackups\Console;

use App\Models\Device;
use DRP\DeviceBackups\Backup\Archives;
use DRP\DeviceBackups\Backup\BackupFailure;
use DRP\DeviceBackups\Backup\BackupStore;
use DRP\DeviceBackups\Backup\Collector;
use DRP\DeviceBackups\Backup\DeviceSshStatus;
use DRP\DeviceBackups\Backup\ManualBackupRequests;
use DRP\DeviceBackups\Backup\SshConnection;
use DRP\DeviceBackups\Backup\Target;
use DRP\DeviceBackups\PluginData;
use DRP\DeviceBackups\PluginDb;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use phpseclib4\Exception\BaseException as SshException;
use Illuminate\Support\Facades\Schema;
use LibreNMS\Util\Mail;
use Throwable;

final class RunBackups extends Command {
    protected $signature = 'device-backups:run
        {device? : Back up only this LibreNMS device ID}
        {--check : Validate local settings and enrollment without contacting devices}
        {--request= : Process the specified queued manual backup request}';

    protected $description = 'Collect and verify encrypted backups from enrolled LibreNMS devices';

    public function handle(ManualBackupRequests $requests): int {
        $id = $this->positiveInt($this->argument('device'));
        $request = $this->positiveInt($this->option('request'));
        if (($this->argument('device') !== null && $id === null)
            || ($this->option('request') !== null && ($request === null || $id === null || $this->option('check')))) {
            $this->error('Enter a valid device ID and manual request ID.');
            return self::FAILURE;
        }

        $claimed = false;
        $previousUmask = umask(0077);
        try {
            if ($request !== null && !$requests->isQueued($request, $id)) {
                return self::SUCCESS;
            }

            return $this->runBackups($id, $request, $requests, $claimed);
        } catch (BackupFailure $e) {
            $message = $e->getMessage();
        } catch (Throwable) {
            $message = 'Device Backups could not complete the run. Check backup database access and storage.';
        } finally {
            umask($previousUmask);
        }

        if ($request !== null) {
            try {
                $requests->fail($request, $id, $claimed, $message);
            } catch (Throwable) {
                $this->warn('Could not update the manual request in the backup database.');
            }
        }
        $this->error($message);
        return self::FAILURE;
    }

    private function positiveInt(mixed $value): ?int {
        return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
    }

    private function runBackups(?int $id, ?int $request, ManualBackupRequests $requests, bool &$claimed): int {
        if (!PluginData::getPluginModel()?->plugin_active) {
            throw new BackupFailure('Enable Device Backups in LibreNMS first.');
        }

        $targets = Device::query()
            ->where('disabled', false)
            ->whereHas('attribs', fn ($query) => $query->where('attrib_type', Target::ATTRIBUTE))
            ->with(['attribs' => fn ($query) => $query->where('attrib_type', Target::ATTRIBUTE)])
            ->when($id !== null, fn ($query) => $query->whereKey($id));
        if (!$targets->exists()) {
            throw new BackupFailure('No enabled, enrolled devices matched. Enroll a device through Device Backups SSH Status or device-backups:configure.');
        }

        $password = config('device-backups.archive_password');
        if (!is_string($password) || $password === '') {
            throw new BackupFailure('Set DEVICE_BACKUPS_ARCHIVE_PASSWORD in LibreNMS before running backups.');
        }
        Archives::verifyAvailable();
        $storage = storage_path('app/private/device-backups');
        $reserve = max(0, (int) config('device-backups.reserve_bytes'));
        $recipient = (string) (PluginData::getSettings()['backup_report_email'] ?? '');
        $schema = Schema::connection(PluginDb::getDbConnection());
        foreach (['device_backup_runs', 'device_backup_results', 'device_backup_chunks'] as $table) {
            if (!$schema->hasTable($table)) {
                throw new BackupFailure('Backup database tables are missing. Run the Device Backups migration for the configured database.');
            }
        }

        if ($this->option('check')) {
            $count = 0;
            foreach ($targets->lazyById(100, 'device_id') as $device) {
                try {
                    $this->deviceSettings($device);
                } catch (Throwable) {
                    throw new BackupFailure("Backup enrollment for device {$device->getKey()} could not be loaded.");
                }
                $count++;
            }
            Archives::checkSpace(is_dir($storage) ? $storage : storage_path(), $reserve);
            $this->info("$count device(s) enrolled. Backup database tables are available.");
            if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                $this->warn('Set a backup report email address in Device Backups Settings.');
            }
            return self::SUCCESS;
        }

        if (!is_dir($storage) && !mkdir($storage, 0700, true) && !is_dir($storage)) {
            throw new BackupFailure('Could not create the private backup storage directory.');
        }
        $lock = fopen($storage . '/run.lock', 'c');
        if (!$lock) {
            throw new BackupFailure('Could not open the backup run lock.');
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                if ($request !== null) {
                    return self::SUCCESS;
                }
                throw new BackupFailure('Another Device Backups run is active.');
            }

            if ($request !== null) {
                $claimed = $requests->claim($request, $id);
                if (!$claimed) {
                    return self::SUCCESS;
                }
            }

            $run = $storage . '/work-' . bin2hex(random_bytes(12));
            if (!mkdir($run, 0700)) {
                throw new BackupFailure('Could not create the private backup staging directory.');
            }

            try {
                return $this->collect(new BackupStore(), $targets, $run, $password, $reserve, $recipient, $request);
            } finally {
                rmdir($run);
            }
        } finally {
            fclose($lock);
        }
    }

    private function collect(BackupStore $store, Builder $targets, string $work, #[\SensitiveParameter] string $password, int $reserve, string $recipient, ?int $request): int {
        $run = $request ?? $store->start();
        $lines = [];
        $success = 0;
        $failure = 0;

        foreach ($targets->lazyById(100, 'device_id') as $device) {
            $archive = "$work/device-{$device->getKey()}.zip";
            try {
                $result = $this->backupDevice($device, $work, $password, $reserve, $archive);
                $result['run_id'] = $run;
                try {
                    $id = $store->record($result, $result['status'] === 'success' ? $archive : null);
                } catch (Throwable) {
                    $result['status'] = 'failed';
                    $result['error'] = 'Could not store the backup in the database.';
                    unset($result['sha256'], $result['archive_bytes']);
                    try {
                        $id = $store->record($result);
                    } catch (Throwable) {
                        $id = null;
                    }
                }
            } finally {
                if (is_file($archive)) {
                    unlink($archive);
                }
            }

            $name = preg_replace('/[\r\n]+/', ' ', $result['hostname']);
            $detail = $result['status'] === 'success'
                ? "OK $name (device {$result['device_id']}, backup ID $id)"
                : "FAILED $name (device {$result['device_id']}) - {$result['error']}";
            $lines[] = $detail;
            $this->line($detail);
            if ($result['status'] === 'success') {
                $success++;
            } else {
                $failure++;
            }
        }

        $failed = $failure > 0 || $success === 0;
        $emailStatus = 'not_configured';
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $subject = ($failed ? '[ACTION REQUIRED]' : '[SUCCESS]') . " Device Backups run $run";
            $body = "Device Backups run $run\nSucceeded: $success\nFailed: $failure\n\n" . implode("\n", $lines);
            try {
                $emailStatus = Mail::send($recipient, $subject, $body, false, false, false) ? 'sent' : 'failed';
            } catch (Throwable) {
                $emailStatus = 'failed';
            }
        }

        $store->finish($run, $failed, $emailStatus);
        $this->info("Run $run. Succeeded: $success. Failed: $failure. Email: $emailStatus.");

        return $failed || $emailStatus !== 'sent' ? self::FAILURE : self::SUCCESS;
    }

    private function deviceSettings(Device $device): array {
        $settings = Target::get($device);
        if (!in_array($settings['profile'] ?? null, Target::profiles(), true)
            || !SshConnection::validFingerprint($settings['fingerprint'] ?? '')
            || ($settings['user'] ?? '') === '' || ($settings['password'] ?? '') === ''
            || !filter_var($settings['port'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]])) {
            throw new BackupFailure('Invalid device backup enrollment.');
        }
        SshConnection::host($device->hostname);

        return $settings;
    }

    private function backupDevice(Device $device, string $run, #[\SensitiveParameter] string $password, int $reserve, string $archive): array {
        $id = $device->getKey();
        $result = ['device_id' => $id, 'hostname' => $device->hostname, 'status' => 'failed'];
        $stage = null;

        try {
            Archives::checkSpace($run, $reserve);
            $settings = $this->deviceSettings($device);
            $result['profile'] = $settings['profile'];
            $member = Target::archiveMember($settings['profile']);
            $stage = "$run/.$id-$member";
            $ssh = DeviceSshStatus::connect($device, $settings);
            Collector::capture($ssh, Target::promptName($device, $settings), $settings, $stage);
            unset($ssh);

            Archives::checkSpace($run, $reserve);
            $checksum = Archives::zip($archive, $stage, $member, $password);
            $bytes = filesize($archive);
            if ($bytes === false) {
                throw new BackupFailure('Could not determine the encrypted archive size.');
            }

            return array_replace($result, ['status' => 'success', 'archive_bytes' => $bytes, 'sha256' => $checksum]);
        } catch (BackupFailure $e) {
            $result['error'] = $e->getMessage();
        } catch (SshException) {
            $result['error'] = 'Device SSH connection or export failed.';
        } catch (Throwable) {
            $result['error'] = 'Could not read the device enrollment or verify the captured backup.';
        } finally {
            if ($stage !== null && is_file($stage)) {
                unlink($stage);
            }
        }

        return $result;
    }

}
