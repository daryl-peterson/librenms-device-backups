<?php

namespace DRP\DeviceBackups\Backup;

use App\Models\Device;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Crypt;

/**
 * LibreNMS Device Backups Target
 * Manages backup targets and their configurations.
 *
 * @package     librenms-device-backups
 * @author      Daryl Peterson <daryl.peterson@gmail.com>
 * @copyright   Copyright (c) 2026, Daryl Peterson
 * @license     https://opensource.org MIT License
 * @link        https://github.com/daryl-peterson/
 * @since       0.0.1
 */
final class Target {
    public const ATTRIBUTE = 'device_backups_ssh';
    public const SWITCHES = ['dell', 'ios', 'nxos', 'asa', 'timos', 'edgeswitch'];
    public const CONFIG_FILES = [
        'edgeos' => '/config/config.boot',
        'airos' => '/tmp/system.cfg',
        'airfiber' => '/tmp/system.cfg',
    ];
    public const SERVERS = ['dhcp', 'dns', 'dhcp-dns', 'dhcp-dns-old', 'dns-old', 'kea', 'dhcp-helper', 'kea-helper'];

    /**
     * Get the profile for the given device.
     *
     * @param Device $device
     * @return string|null
     *
     * @since 0.0.1
     */
    public static function profile(Device $device): ?string {
        return match ($device->os) {
            'ios', 'iosxe' => 'ios',
            'nxos' => 'nxos',
            'asa' => 'asa',
            'timos' => 'timos',
            'dnos', 'powerconnect' => 'dell',
            'edgeos', 'airos' => $device->os,
            'airos-af' => 'airfiber',
            'edgeswitch' => preg_match('/\b(?:UniFi|USW|US-\d)/i', $device->hardware . ' ' . $device->sysDescr)
                ? null : 'edgeswitch',
            default => null,
        };
    }

    /**
     * Get all available profiles.
     *
     * @return array
     *
     * @since 0.0.1
     */
    public static function profiles(): array {
        return [...self::SWITCHES, ...array_keys(self::CONFIG_FILES), ...self::SERVERS];
    }

    /**
     * Determine if the given profile requires a CLI hostname.
     *
     * @param string $profile
     * @return bool
     *
     * @since 0.0.1
     */
    public static function needsCliHostname(string $profile): bool {
        return $profile !== 'edgeswitch' && in_array($profile, self::SWITCHES, true);
    }

    /**
     * Get the archive member name for the given profile.
     *
     * @param string $profile
     * @return string
     *
     * @since 0.0.1
     */
    public static function archiveMember(string $profile): string {
        if (isset(self::CONFIG_FILES[$profile])) {
            return basename(self::CONFIG_FILES[$profile]);
        }

        return in_array($profile, self::SWITCHES, true) ? 'running-config.txt' : 'configuration-and-state.tar.gz';
    }
    /**
     * Get the backup settings for the given device.
     *
     * @param Device $device
     * @return array
     *
     * @since 0.0.1
     */
    public static function get(Device $device): array {
        $value = $device->getAttrib(self::ATTRIBUTE);
        if (!is_string($value) || $value === '') {
            throw new BackupFailure('This device is not enrolled for backups.');
        }

        return json_decode(Crypt::decryptString($value), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Save the backup settings for the given device.
     *
     * @param Device $device
     * @param array $settings
     * @return bool
     *
     * @since 0.0.1
     */
    public static function save(Device $device, #[\SensitiveParameter] array $settings): bool {
        $encrypted = Crypt::encryptString(json_encode($settings, JSON_THROW_ON_ERROR));

        return self::withLockedDevice($device, static fn(Device $current) => $current->setAttrib(self::ATTRIBUTE, $encrypted));
    }

    /**
     * Remove the backup settings for the given device.
     *
     * @param Device $device
     * @return bool
     *
     * @since 0.0.1
     */
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

    /**
     * Get the revision hash for the given device's backup settings.
     *
     * @param Device $device
     * @return string|null
     *
     * @since 0.0.1
     */
    public static function revision(Device $device): ?string {
        $encrypted = $device->getAttrib(self::ATTRIBUTE);

        return is_string($encrypted) && $encrypted !== '' ? hash('sha256', $device->hostname . "\0" . $encrypted) : null;
    }

    /**
     * Execute an operation with the device locked for update.
     *
     * @param Device $device
     * @param Closure $operation
     * @return mixed
     *
     * @since 0.0.1
     */
    public static function withLockedDevice(Device $device, Closure $operation): mixed {
        return $device->getConnection()->transaction(static function () use ($device, $operation): mixed {
            $current = $device->newQuery()->lockForUpdate()->findOrFail($device->getKey());

            return $operation($current);
        });
    }

    /**
     * Get the inventory of devices matching the search criteria.
     *
     * @param string $search
     * @return Builder
     *
     * @since 0.0.1
     */
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

    /**
     * Get the prompt name for the given device based on its settings.
     *
     * @param Device $device
     * @param array $settings
     * @return string
     *
     * @since 0.0.1
     */
    public static function promptName(Device $device, array $settings): string {
        $name = $device->sysName ?: $device->hostname;

        return ($settings['prompt_name'] ?? '') ?: explode('.', (string) $name, 2)[0];
    }
}
