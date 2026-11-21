<?php

/**
 * LibreNMS Device Backups Web Routes.
 *
 * @package     librenms-device-backups
 * @author      Daryl Peterson <@gmail.com>
 * @license     https://opensource.org MIT License
 * @link        https://github.com/daryl-peterson/
 * @since       1.0.0
 */


use Illuminate\Support\Facades\Route;

/**
 * Plugin imports.
 */

use DRP\DeviceBackups\Controllers\PluginController;
use DRP\DeviceBackups\Controllers\ActionController;
use const DRP\DeviceBackups\PLUGIN_NAME;

$plugin = PLUGIN_NAME;


/**
 * Upload route
 */
//if (PluginDb::isReady()) {
/*
Route::middleware(['web'])
    ->get("plugin/$plugin/import", [PluginController::class, 'import'])
    ->name("$plugin.import");



Route::middleware(['web'])
    ->get("plugin/$plugin/export", [PluginController::class, 'export'])
    ->name("$plugin.export");
*/

/**
 * Settings route
 */
Route::middleware(['web'])
    ->get("plugin/settings/$plugin", [PluginController::class, 'settings'])
    ->name("$plugin.settings");

Route::middleware(['web', 'auth'])
    ->post("plugin/settings/$plugin/test-connection", [PluginController::class, 'testConnection'])
    ->name("$plugin.test-connection");

Route::middleware(['web', 'auth'])
    ->post("plugin/settings/$plugin/save", [PluginController::class, 'saveSettings'])
    ->name("$plugin.save-settings");

/**
 * Action route
 */
/*
Route::middleware(['web'])
    ->post("plugin/$plugin/action", [ActionController::class, 'handle'])
    ->name("$plugin.action");
*/
