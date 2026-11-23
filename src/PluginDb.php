<?php

namespace DRP\DeviceBackups;

use DateTimeImmutable;
use Illuminate\Database\QueryException;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

use DRP\DeviceBackups\Helper;
use DRP\DeviceBackups\PluginCache;

/**
 * LibreNMS Device Backups Database Check.
 *
 * @package     device-importer
 * @author      Daryl Peterson <@gmail.com>
 * @copyright   Copyright (c) 2026, Daryl Peterson
 * @license     https://opensource.org MIT License
 * @link        https://github.com/daryl-peterson/
 * @since       0.0.1
 */
class PluginDb {

    use TraitHidePrivates;

    public const SETUP_REQUIRED = 'Test and save the backup database settings, then run migrations to enable backups.';
    private const MIGRATIONS_PATH = __DIR__ . '/../database/migrations';

    /**
     * Plugin cache instance
     */
    private static ?PluginCache $pluginCache = null;

    /**
     * Initialization flag
     */
    private static bool $initialized = false;
    private static ?array $configuredDatabase = null;

    /**
     * Check submitted settings with a fresh, read-only query.
     *
     * @param array{host: string, port: int|string, database: string, username: string, password?: string|null} $settings
     */
    public static function testConnection(array $settings): void {
        // PDO treats "localhost" as a Unix socket and ignores the supplied TCP port.
        $host = self::tcpHost($settings['host']);
        $dsn = "mysql:host=$host;port={$settings['port']};dbname={$settings['database']}";

        $pdo = new \PDO($dsn, $settings['username'], $settings['password'] ?? '', [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => 5,
        ]);
        $pdo->query('SELECT 1');
    }

    /**
     * Get the current date and time in 'Y-m-d H:i:s' format.
     *
     * @return string
     * @since 0.0.1
     */
    public static function now(): string {
        return (new DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    /**
     * Get the TCP host for the database connection.
     *
     * @param string $host
     * @return string
     * @since 0.0.1
     */
    private static function tcpHost(string $host): string {
        return strcasecmp($host, 'localhost') === 0 ? '127.0.0.1' : $host;
    }

    /**
     * Clear all database connection checks from the cache.
     *
     * @return void
     * @since 0.0.1
     */
    public static function clearConnectionChecks(): void {
        PluginCache::forget(CACHE_DB_PASS);
        PluginCache::forget(CACHE_DB_ERROR);
        PluginCache::forget(CACHE_DB_MIGRATION_CHECK);
    }

    /**
     * Check database connection.
     *
     * @return boolean
     * @since 0.0.1
     */
    public static function checkConnection(): bool {
        $ttlPass = Helper::hours(1);
        $ttlError = Helper::minutes(5);

        if (!self::$initialized) {
            self::initConfig();
        }

        try {
            $result = self::$pluginCache->get(CACHE_DB_PASS, false);
            if ($result) {
                return true;
            }

            if (self::$pluginCache->has(CACHE_DB_ERROR)) {
                return false;
            }

            $conn = self::getDbConnection();
            $result = DB::connection($conn)->getPdo();

            self::$pluginCache->set(
                CACHE_DB_PASS,
                true,
                $ttlPass
            );

            return true;
        } catch (\Exception $e) {
            self::$pluginCache->set(
                CACHE_DB_PASS,
                false,
                $ttlError
            );
            self::$pluginCache->set(
                CACHE_DB_ERROR,
                'Unable to connect to database',
                $ttlError
            );
            return false;
        }
    }

    /**
     * Apply plugin migrations using the tested, saved connection.
     *
     * @param array{host: string, port: int|string, database: string, username: string, password?: string|null} $settings
     */
    public static function migrateSettings(#[\SensitiveParameter] array $settings): bool {
        $connection = self::getDbConnection();
        $key = 'database.connections.' . $connection;
        $previous = Config::get($key);

        DB::purge($connection);
        Config::set($key, self::connectionConfig($settings));

        try {
            return Cache::lock('device-backups:migrations', 3600)->get(static function () use ($connection): bool {
                $migrator = app('migrator');

                return $migrator->usingConnection($connection, static function () use ($migrator): bool {
                    $repository = $migrator->getRepository();
                    if (!$migrator->repositoryExists()) {
                        $repository->createRepository();
                    }

                    $migrator->run([self::MIGRATIONS_PATH]);
                    return array_diff(self::migrationNames(), $repository->getRan()) === [];
                });
            });
        } finally {
            DB::purge($connection);
            Config::set($key, $previous);
        }
    }

    /**
     * Get the list of migration file names.
     *
     * @return array
     * @since 0.0.1
     */
    private static function migrationNames(): array {
        return array_map(static fn(string $file): string => basename($file, '.php'), glob(self::MIGRATIONS_PATH . '/*.php'));
    }

    /**
     * Get the current migration version hash.
     *
     * @return string
     * @since 0.0.1
     */
    public static function migrationVersion(): string {
        return hash('sha256', implode("\n", self::migrationNames()));
    }

    /**
     * Check if the plugin database is ready.
     *
     * @param array|null $settings
     * @return bool
     * @since 0.0.1
     */
    public static function isReady(?array $settings = null): bool {
        $settings ??= PluginData::getSettings();
        if (!PluginSettings::hasSavedDatabase($settings)) {
            return false;
        }

        if (self::$initialized && $settings['database_connection'] !== self::$configuredDatabase) {
            return false;
        }

        if (array_key_exists('database_migrations', $settings)) {
            return $settings['database_migrations'] === self::migrationVersion();
        }

        // Earlier releases tested connections before saving them. Verify their migration history.
        $repository = Config::get('database.migrations', 'migrations');
        $table = is_array($repository) ? $repository['table'] : $repository;
        try {
            $applied = DB::connection(self::getDbConnection())->table($table)->pluck('migration')->all();
            return array_diff(self::migrationNames(), $applied) === [];
        } catch (QueryException) {
            return false;
        }
    }


    /**
     * Get the last database error message.
     *
     * @return string|null
     * @since 0.0.1
     */
    public static function getError(): ?string {
        return PluginCache::get(CACHE_DB_ERROR);
    }

    /**
     * Get the database connection name.
     *
     * @return string The database connection name.
     * @since 0.0.1
     */
    public static function getDbConnection(): string {
        return PLUGIN_DB_CONNECTION;
    }

    /**
     * Get the database name.
     *
     * @return string
     * @since 0.0.1
     */
    public static function getDbName(): string {
        return (string) Config::get('database.connections.' . PLUGIN_DB_CONNECTION . '.database');
    }

    /**
     * Initialize the plugin database configuration.
     *
     * @return void
     * @since 0.0.1
     */
    public static function initConfig(): void {
        if (self::$initialized) {
            return;
        }

        $saved = (new PluginSettings())->all();
        $settings = PluginSettings::databaseSettings($saved);
        self::$pluginCache = new PluginCache();

        Config::set('database.connections.' . PLUGIN_DB_CONNECTION, self::connectionConfig($settings));

        Config::set('queue.connections.device_backups', [
            'connection' => PLUGIN_DB_CONNECTION,
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'plugin_queue',
            'retry_after' => 90,
        ]);

        self::$configuredDatabase = $saved['database_connection'] ?? null;
        self::$initialized = true;
    }

    /**
     * Get the TCP host for the database connection.
     *
     * @param string $host
     * @return string
     * @since 0.0.1
     */
    private static function connectionConfig(#[\SensitiveParameter] array $settings): array {
        $settings['host'] = self::tcpHost($settings['host']);

        return $settings + [
            'driver' => 'mysql',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'options' => [\PDO::ATTR_TIMEOUT => 5],
        ];
    }

    /**
     * Get the current date and time in 'Y-m-d H:i:s' format.
     *
     * @return string
     * @since 0.0.1
     */
    private static function getDate(): string {
        $date = new DateTimeImmutable();
        return $date->format('Y-m-d H:i:s');
    }
}
