<?php

/**
 * LibreNMS Device Backups Plugin Service Provider.
 *
 * @package     device-backups
 * @author      Daryl Peterson <@gmail.com>
 * @copyright   Copyright (c) 2026, Daryl Peterson
 * @license     https://opensource.org MIT License
 * @link        https://github.com/daryl-peterson/
 * @since       0.0.1
 */

namespace DRP\DeviceBackups;

/**
 * Standard PHP imports.
 */

use Throwable;


/**
 * Laravel and application imports.
 */

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use LibreNMS\Interfaces\Plugins\Hooks\DeviceOverviewHook;
use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook as MenuEntryHookInterface;
use LibreNMS\Interfaces\Plugins\Hooks\SettingsHook as SettingsHookInterface;
use LibreNMS\Interfaces\Plugins\Hooks\SinglePageHook;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;

/**
 * Plugin imports.
 */

use DRP\DeviceBackups\Backup\BackupSchedule;
use DRP\DeviceBackups\Console\ConfigureBackup;
use DRP\DeviceBackups\Console\ExportBackup;
use DRP\DeviceBackups\Console\ProcessManualBackups;
use DRP\DeviceBackups\Console\RunBackups;
use DRP\DeviceBackups\Hooks\DeviceOverview;
use DRP\DeviceBackups\Hooks\Menu;
use DRP\DeviceBackups\Hooks\Page;
use DRP\DeviceBackups\Hooks\Settings;
use DRP\DeviceBackups\Log;
use DRP\DeviceBackups\PluginDb;

/**
 * Define the plugin path constant.
 */

/*
define(
    'DEVICE_IMPORTER_PATH',
    'vendor/daryl-peterson/librenms-device-importer/'
);
*/

/**
 * LibreNMS Device Backups Plugin Service Provider.
 *
 * @package     device-backups
 * @author      Daryl Peterson <@gmail.com>
 * @copyright   Copyright (c) 2026, Daryl Peterson
 * @license     https://opensource.org MIT License
 * @link        https://github.com/daryl-peterson/
 * @since       0.0.1
 */
class PluginProvider extends ServiceProvider {

    /**
     * Register the plugin services.
     *
     * @since 0.0.1
     */
    public function register(): void {
        require_once __DIR__ . '/constants.php';
        $this->mergeConfigFrom(dirname(__DIR__) . '/config/device-backups.php', 'device-backups');
        \Illuminate\Foundation\Http\Middleware\TrimStrings::except(['ssh_password', 'enable_password']);

        if ($this->app->runningInConsole()) {
            $this->app['events']->listen(CommandFinished::class, $this->clearStaleRouteCache(...));
        }
    }

    private function clearStaleRouteCache(CommandFinished $event): void {
        if ($event->command !== 'package:discover' || $event->exitCode !== 0 || !$this->app->routesAreCached()) {
            return;
        }

        if ($this->app['router']->has(PLUGIN_NAME . '.backups.status')) {
            return;
        }

        if (!$this->app['files']->delete($this->app->getCachedRoutesPath())) {
            $event->output->writeln('<comment>Could not clear the stale route cache. Run ./lnms route:clear as the LibreNMS service user.</comment>');
        }
    }

    /**
     * Bootstrap the plugin services.
     *
     * @since 0.0.1
     */
    public function boot(): void {
        try {

            $pluginName = PLUGIN_NAME;

            /*
            * Compatibility view path.
            *
            * LibreNMS local plugins commonly reference views like:
            * device-backups::resources.views.page
            *
            * Package views can also be referenced as:
            * device-backups::page
            */
            $paths = [
                __DIR__ . '/..',
                __DIR__ . '/../resources/views',
            ];
            $this->loadViewsFrom($paths, $pluginName);
            $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');

            $pluginManager = $this->app->make(PluginManagerInterface::class);
            $pluginManager->publishHook($pluginName, MenuEntryHookInterface::class, Menu::class);
            $pluginManager->publishHook($pluginName, SinglePageHook::class, Page::class);
            $pluginManager->publishHook($pluginName, SettingsHookInterface::class, Settings::class);

            $pluginManager->publishHook($pluginName, DeviceOverviewHook::class, DeviceOverview::class);

            PluginDb::initConfig();
            if (PluginSettings::hasSavedDatabase(PluginData::getSettings())) {
                PluginDb::checkConnection();
            }

            if ($this->app->runningInConsole()) {
                $this->commands([ConfigureBackup::class, ExportBackup::class, ProcessManualBackups::class, RunBackups::class]);

                if ($pluginManager->pluginEnabled($pluginName)) {
                    $this->app->booted(function () {
                        $settings = PluginData::getSettings();
                        if (!PluginDb::isReady($settings)) {
                            return;
                        }

                        $schedule = BackupSchedule::fromSettings($settings);
                        if ($schedule->enabled) {
                            $this->app->make(Schedule::class)
                                ->command('device-backups:run', ['--scheduled'])
                                ->cron($schedule->expression())
                                ->timezone($schedule->timezone);
                        }

                        $this->app->make(Schedule::class)->command(ProcessManualBackups::class)->everyMinute();
                    });
                }
            }
        } catch (Throwable $th) {
            Log::error("Error booting plugin provider: " . $th->getMessage());
        }
    }
}
