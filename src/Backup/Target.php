<?php

namespace DRP\DeviceBackups\Backup;

use App\Models\Device;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Crypt;

final class Target {
    public const ATTRIBUTE = 'device_backups_ssh';
    public const SWITCHES = ['dell', 'ios', 'nxos', 'asa', 'timos'];
    public const SERVERS = ['dhcp', 'dns', 'dhcp-dns', 'dhcp-dns-old', 'dns-old', 'kea', 'dhcp-helper', 'kea-helper'];

    public static function profile(Device $device): ?string {
        return match ($device->os) {
            'ios', 'iosxe' => 'ios',
            'nxos' => 'nxos',
            'asa' => 'asa',
            'timos' => 'timos',
            'dnos', 'powerconnect' => 'dell',
            default => null,
        };
    }

    public static function get(Device $device): array {
        $value = $device->getAttrib(self::ATTRIBUTE);
        if (!is_string($value) || $value === '') {
            throw new BackupFailure('This device is not enrolled for backups.');
        }

        return json_decode(Crypt::decryptString($value), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function save(Device $device, #[\SensitiveParameter] array $settings): bool {
        $encrypted = Crypt::encryptString(json_encode($settings, JSON_THROW_ON_ERROR));

        return self::withLockedDevice($device, static fn (Device $current) => $current->setAttrib(self::ATTRIBUTE, $encrypted));
    }

    public static function remove(Device $device): bool {
        return self::withLockedDevice($device, static function (Device $current): bool {
            if ($current->hasAttrib(self::ATTRIBUTE) && !$current->forgetAttrib(self::ATTRIBUTE)) {
                return false;
            }
            if ($current->hasAttrib(DeviceSshStatus::ATTRIBUTE)) {
                $current->forgetAttrib(DeviceSshStatus::ATTRIBUTE);
            }

            return true;
        });
    }

    public static function revision(Device $device): ?string {
        $encrypted = $device->getAttrib(self::ATTRIBUTE);

        return is_string($encrypted) && $encrypted !== '' ? hash('sha256', $device->hostname . "\0" . $encrypted) : null;
    }

    public static function withLockedDevice(Device $device, Closure $operation): mixed {
        return $device->getConnection()->transaction(static function () use ($device, $operation): mixed {
            $current = $device->newQuery()->lockForUpdate()->findOrFail($device->getKey());

            return $operation($current);
        });
    }

    public static function inventory(string $search): Builder {
        return Device::query()->when($search !== '', function (Builder $query) use ($search): void {
            $query->where(function (Builder $query) use ($search): void {
                $query->whereLike('hostname', "%$search%")->orWhereLike('sysName', "%$search%");
                if (ctype_digit($search)) {
                    $query->orWhere('device_id', $search);
                }
            });
        })->orderBy('hostname')->orderBy('device_id');
    }

    public static function promptName(Device $device, array $settings): string {
        $name = $device->sysName ?: $device->hostname;

        return ($settings['prompt_name'] ?? '') ?: explode('.', (string) $name, 2)[0];
    }
}
