<?php

/**
 * LibreNMS Device Backups Database Check.
 *
 * @package     device-importer
 * @author      Daryl Peterson <@gmail.com>
 * @license     https://opensource.org MIT License
 * @link        https://github.com/daryl-peterson/
 * @since       0.0.1
 */

namespace DRP\DeviceBackups;

/**
 * Standard PHP imports.
 */

use Throwable;
use DateTimeImmutable;

/**
 * Laravel and application imports.
 */

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;


/**
 * Plugin database imports.
 */

use DRP\DeviceBackups\Helper;
use DRP\DeviceBackups\Log;
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

    /**
     * Plugin cache instance
     */
    private static ?PluginCache $pluginCache = null;

    /**
     * Initialization flag
     */
    private static bool $initialized = false;

    /**
     * Run the plugin database initialization and checks.
     *
     * @return void
     * @since 0.0.1
     */
    public static function run(): void {
        if (!self::checkConnection()) {
            return;
        }
        self::checkMigrationsTable();
    }

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

    private static function tcpHost(string $host): string {
        return strcasecmp($host, 'localhost') === 0 ? '127.0.0.1' : $host;
    }

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
     * Check if the migrations table exists and install it if necessary.
     *
     * @param bool $bypassCache Whether to bypass the cached result and perform a fresh check.
     * @return void
     * @since 0.0.1
     */
    public static function checkMigrationsTable(bool $bypassCache = false) {
        $path = dirname(__DIR__) . '/database/migrations';
        if (!is_dir($path)) {
            return;
        }

        $ttl_days = Helper::days(1);
        $ttl_mins = Helper::minutes(5);

        if (!self::$initialized) {
            self::initConfig();
        }

        // Check database connection before proceeding
        if (!self::checkConnection()) {
            return;
        }

        $conn = self::getDbConnection();

        if (self::$pluginCache->has(CACHE_DB_MIGRATION_CHECK) && !$bypassCache) {
            return;
        }

        try {

            // Check if the migrations table exists before attempting to install migrations
            if (! Schema::connection($conn)->hasTable('migrations')) {
                Artisan::call('migrate:install', [
                    '--database' => $conn,
                ]);
            }

            $result = Artisan::call('migrate', [
                '--database' => $conn,
                // Ensures migrations run without interactive prompts
                '--force' => true,
                // Optional: isolates to just your plugin files
                '--path'     => $path,
                '--realpath' => true,
            ]);

            $output = Artisan::output();
            Log::debug("Migrations output: " . PHP_EOL . $output);

            self::$pluginCache->set(
                CACHE_DB_MIGRATION_CHECK,
                true,
                $ttl_days
            );
        } catch (Throwable $th) {
            Log::error("Error checking migrations table: " . $th->getMessage());
            self::$pluginCache->set(
                CACHE_DB_MIGRATION_CHECK,
                true,
                $ttl_mins
            );
        }
    }

    public static function hasPendingMigrations(): bool {
        $path = dirname(__DIR__) . '/database/migrations';
        if (!is_dir($path)) {
            return false;
        }

        $conn = self::getDbConnection();
        $migrations = Artisan::call('migrate:status', [
            '--database' => $conn,
            '--path'     => $path,
            '--realpath' => true,
        ]);
        $output = Artisan::output();
        return str_contains($output, 'No');
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

    public static function getDbName(): string {
        return (string) Config::get('database.connections.' . PLUGIN_DB_CONNECTION . '.database');
    }

    public static function initConfig(): void {
        if (self::$initialized) {
            return;
        }

        $settings = PluginSettings::databaseSettings((new PluginSettings())->all());
        $settings['host'] = self::tcpHost($settings['host']);
        self::$pluginCache = new PluginCache();

        Config::set('database.connections.' . PLUGIN_DB_CONNECTION, $settings + [
            'driver' => 'mysql',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'options' => [\PDO::ATTR_TIMEOUT => 5],
        ]);

        Config::set('queue.connections.device_backups', [
            'connection' => PLUGIN_DB_CONNECTION,
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'plugin_queue',
            'retry_after' => 90,
        ]);

        self::$initialized = true;
    }

    private static function getDate(): string {
        $date = new DateTimeImmutable();
        return $date->format('Y-m-d H:i:s');
    }
}
