<?php

namespace DRP\DeviceBackups\Backup;

use App\Models\Device;
use DRP\DeviceBackups\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use phpseclib4\Exception\BaseException as SshException;
use phpseclib4\Net\SSH2;

final class DeviceSshStatus {
    public const ATTRIBUTE = 'device_backups_ssh_status';

    public const LABELS = [
        'connected' => 'Connected',
        'authentication_failed' => 'Authentication failed',
        'host_key_mismatch' => 'Host key mismatch',
        'connection_failed' => 'Connection failed',
        'invalid_settings' => 'Invalid SSH settings',
    ];

    public static function get(Device $device): ?array {
        $revision = Target::revision($device);
        $check = json_decode((string) $device->getAttrib(self::ATTRIBUTE), true);
        if ($revision === null || !is_array($check) || ($check['revision'] ?? null) !== $revision
            || !isset(self::LABELS[$check['status'] ?? ''], $check['checked_at'])) {
            return null;
        }

        return $check;
    }

    public static function connect(Device $device, #[\SensitiveParameter] array $settings, int $timeout = 10): SSH2 {
        $status = 'connection_failed';
        try {
            $port = filter_var($settings['port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
            $user = $settings['user'] ?? '';
            $password = $settings['password'] ?? '';
            $fingerprint = $settings['fingerprint'] ?? '';
            if (!$port || $user === '' || $password === '' || !SshConnection::validFingerprint($fingerprint)) {
                throw new SshFailure('invalid_settings', 'Check the saved SSH credentials, port, and host key fingerprint.');
            }

            $ssh = new SSH2(SshConnection::host($device->hostname), $port, $timeout);
            SshConnection::login($ssh, $fingerprint, $user, $password);
            $status = 'connected';
            return $ssh;
        } catch (SshFailure $e) {
            $status = $e->status;
            throw $e;
        } catch (BackupFailure|SshException) {
            throw new SshFailure('connection_failed', 'Could not complete the SSH connection. Check the device address, port, and SSH access.');
        } finally {
            self::record($device, $status);
        }
    }

    private static function record(Device $device, string $status): void {
        $revision = Target::revision($device);
        if ($revision === null) {
            return;
        }

        try {
            Target::withLockedDevice($device, static function (Device $current) use ($revision, $status): void {
                if (Target::revision($current) === $revision) {
                    $current->setAttrib(self::ATTRIBUTE, json_encode([
                        'revision' => $revision,
                        'status' => $status,
                        'checked_at' => now('UTC')->toDateTimeString(),
                    ], JSON_THROW_ON_ERROR));
                }
            });
        } catch (ModelNotFoundException) {
            return;
        } catch (QueryException) {
            Log::error('Could not record the device SSH check.', ['device_id' => $device->getKey()]);
        }
    }
}
