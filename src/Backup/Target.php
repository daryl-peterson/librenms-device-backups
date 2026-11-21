<?php

namespace DRP\DeviceBackups\Backup;

use App\Models\Device;
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
        return $device->setAttrib(self::ATTRIBUTE, Crypt::encryptString(json_encode($settings, JSON_THROW_ON_ERROR)));
    }

    public static function promptName(Device $device, array $settings): string {
        $name = $device->sysName ?: $device->hostname;

        return $settings['prompt_name'] ?: explode('.', (string) $name, 2)[0];
    }
}
