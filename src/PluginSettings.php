<?php

/**
 * LibreNMS Device Backups Plugin Settings.
 *
 * @package     librenms-device-backups
 * @author      Daryl Peterson <daryl.peterson@gmail.com>
 * @copyright   Copyright (c) 2026, Daryl Peterson
 * @license     https://opensource.org MIT License
 * @link        https://github.com/daryl-peterson/
 * @since       0.0.1
 */

namespace DRP\DeviceBackups;

use Throwable;
use App\Models\Plugin;
use Closure;
use DRP\DeviceBackups\Backup\BackupSchedule;
use DRP\DeviceBackups\Log;
use Illuminate\Support\Facades\Crypt;

/**
 * LibreNMS Device Backups Plugin Settings.
 *
 * @package     librenms-device-backups
 * @author      Daryl Peterson <daryl.peterson@gmail.com>
 * @copyright   Copyright (c) 2026, Daryl Peterson
 * @license     https://opensource.org MIT License
 * @link        https://github.com/daryl-peterson/
 * @since       0.0.1
 */
class PluginSettings {

    /**
     * Import settings for the Device Backups plugin.
     *
     * @since 0.0.1
     */
    public array $settings;

    /**
     * The plugin instance.
     *
     * @since 0.0.1
     */
    public Plugin|null $plugin = null;

    /**
     * Device Backups plugin settings constructor.
     *
     * @since 0.0.1
     */
    public function __construct() {
        $this->plugin = PluginData::getPluginModel();
        $this->settings = (array) $this->plugin?->settings;
    }

    public static function hasDatabaseConfiguration(array $settings): bool {
        return is_array($settings['database_connection'] ?? null)
            || env('PLUGIN_DB_DATABASE') !== null
            || env('PLUGIN_DB_HOST') !== null
            || env('PLUGIN_DB_PORT') !== null
            || env('PLUGIN_DB_USERNAME') !== null
            || env('PLUGIN_DB_PASSWORD') !== null;
    }

    public static function databaseSettings(array $settings): array {
        $saved = $settings['database_connection'] ?? null;
        if (is_array($saved)) {
            return [
                'database' => $saved['database'],
                'host' => $saved['host'],
                'port' => $saved['port'],
                'username' => $saved['username'],
                'password' => Crypt::decryptString($saved['encrypted_password']),
            ];
        }

        return [
            'database' => env('PLUGIN_DB_DATABASE', PLUGIN_DB_DATABASE),
            'host' => env('PLUGIN_DB_HOST', PLUGIN_DB_HOST),
            'port' => env('PLUGIN_DB_PORT', PLUGIN_DB_PORT),
            'username' => env('PLUGIN_DB_USERNAME', PLUGIN_DB_USERNAME),
            'password' => env('PLUGIN_DB_PASSWORD', PLUGIN_DB_PASSWORD),
        ];
    }

    public static function sftpPassword(array $settings): ?string {
        return isset($settings['encrypted_sftp_password'])
            ? Crypt::decryptString($settings['encrypted_sftp_password'])
            : ($settings['sftppassword'] ?? null);
    }

    public function saveConnections(array $database, array $formSettings): bool {
        return $this->updateSettings(function (array $settings) use ($database, $formSettings): array {

            $database['encrypted_password'] = Crypt::encryptString($database['password']);
            unset($database['password'], $settings['password']);
            $settings['database_connection'] = $database;

            foreach (['sftpserver', 'sftpport', 'sftpuser', 'sftpfingerprint', 'backup_report_email'] as $key) {
                if (array_key_exists($key, $formSettings)) {
                    $settings[$key] = $formSettings[$key];
                }
            }

            $sftpPassword = $formSettings['sftppassword'] ?? $settings['sftppassword'] ?? null;
            if ($sftpPassword !== null) {
                $settings['encrypted_sftp_password'] = Crypt::encryptString($sftpPassword);
            }
            unset($settings['sftppassword']);

            return $settings;
        });
    }

    public function saveSchedule(BackupSchedule $schedule): bool {
        return $this->updateSettings(static function (array $settings) use ($schedule): array {
            $settings[BackupSchedule::SETTINGS_KEY] = [
                'enabled' => $schedule->enabled,
                'days' => $schedule->days,
                'time' => $schedule->time,
                'timezone' => $schedule->timezone,
            ];

            return $settings;
        });
    }

    private function updateSettings(Closure $update): bool {
        if ($this->plugin === null) {
            return false;
        }

        return $this->plugin->getConnection()->transaction(function () use ($update): bool {
            $plugin = $this->plugin->newQuery()->lockForUpdate()->findOrFail($this->plugin->getKey());
            $plugin->settings = $update((array) $plugin->settings);

            return $plugin->save();
        });
    }

    /**
     * Get all plugin settings.
     *
     * @return array
     *
     * @since 0.0.1
     */
    public function all(): array {
        return $this->settings;
    }

    /**
     * Get a specific plugin setting.
     *
     * @param string $key The setting key to retrieve.
     * @param mixed $default The default value to return if the setting key does not exist.
     * @return mixed
     *
     * @since 0.0.1
     */
    public function get(string $key, $default = null) {
        return $this->settings[$key] ?? $default;
    }

    /**
     * Set a plugin setting.
     *
     * @param string $key The setting key to set.
     * @param mixed $value The value to set for the specified setting key.
     * @return bool
     *
     * @since 0.0.1
     */
    public function set(string $key, $value): bool {
        try {
            $this->settings[$key] = $value;

            if (is_null($this->plugin)) {
                return false;
            }

            $this->plugin->settings = $this->settings;
            return $this->plugin->save();
        } catch (Throwable $th) {
            Log::error("Error setting plugin setting: " . $th->getMessage());
            return false;
        }
    }

    /**
     * Check if a plugin setting exists.
     *
     * @param string $key The setting key to check for existence.
     * @return bool
     *
     * @since 0.0.1
     */
    public function has(string $key): bool {
        return isset($this->settings[$key]);
    }

    /**
     * Reset all plugin settings.
     *
     * @return void
     *
     * @since 0.0.1
     */
    public function reset() {
        $this->settings = [];
        if (!is_null($this->plugin)) {
            $this->plugin->settings = $this->settings;
            $this->plugin->save();
        }
    }

    /**
     * Delete a specific plugin setting.
     *
     * @param string $key The setting key to delete.
     * @return bool
     *
     * @since 0.0.1
     */
    public function delete(string $key): bool {
        try {
            if (isset($this->settings[$key])) {
                unset($this->settings[$key]);
            }

            if (is_null($this->plugin)) {
                return false;
            }

            $this->plugin->settings = $this->settings;
            return $this->plugin->save();
        } catch (Throwable $th) {
            Log::error("Error deleting plugin setting: " . $th->getMessage());
            return false;
        }
    }

}
