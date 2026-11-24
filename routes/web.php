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
use DRP\DeviceBackups\PluginData;
use DRP\DeviceBackups\Controllers\BackupController;
use DRP\DeviceBackups\Controllers\BackupScheduleController;
use DRP\DeviceBackups\Controllers\DeviceSshController;
use DRP\DeviceBackups\Controllers\PluginController;

$obj = PluginData::getInstance();
$plugin = $obj->name;

/**
 * Settings routes
 */
Route::middleware(['web', 'auth', 'can:plugin.admin'])->group(function () use ($plugin): void {
    Route::get("plugin/$plugin/settings", [PluginController::class, 'settings'])
        ->name("$plugin.settings");

    // Test connection route
    Route::post("plugin/$plugin/settings/test-connection", [PluginController::class, 'testConnection'])
        ->name("$plugin.test-connection");

    // Test email route
    Route::post("plugin/$plugin/settings/test-email", [PluginController::class, 'testReportEmail'])
        ->name("$plugin.test-email");

    // Save settings route
    Route::post("plugin/$plugin/settings/save", [PluginController::class, 'saveSettings'])
        ->name("$plugin.save-settings");
});


/**
 * Backups routes
 */
Route::middleware(['web', 'auth'])->prefix("plugin/$plugin/backups")->name("$plugin.backups.")->group(function (): void {
    Route::get('/', [BackupController::class, 'status'])->name('status');
    Route::get('/devices', [BackupController::class, 'devices'])->name('devices');
    Route::get('/schedule', [BackupScheduleController::class, 'edit'])->name('schedule');
    Route::post('/schedule', [BackupScheduleController::class, 'update'])->name('schedule.update');
    Route::get('/ssh', [DeviceSshController::class, 'index'])->name('ssh.index');
    Route::get('/ssh/{device}', [DeviceSshController::class, 'edit'])->whereNumber('device')->name('ssh.edit');
    Route::post('/ssh/{device}', [DeviceSshController::class, 'update'])->whereNumber('device')->name('ssh.update');
    Route::post('/ssh/{device}/check', [DeviceSshController::class, 'check'])->whereNumber('device')->name('ssh.check');
    Route::post('/ssh/{device}/fingerprint', [DeviceSshController::class, 'fingerprint'])->whereNumber('device')->name('ssh.fingerprint');
    Route::delete('/ssh/{device}', [DeviceSshController::class, 'remove'])->whereNumber('device')->name('ssh.remove');
    Route::get('/devices/{device}', [BackupController::class, 'history'])->whereNumber('device')->name('history');
    Route::post('/devices/{device}/run', [BackupController::class, 'start'])->whereNumber('device')->name('start');
    Route::get('/devices/{device}/latest', [BackupController::class, 'latest'])->whereNumber('device')->name('latest');
    Route::get('/devices/{device}/compare', [BackupController::class, 'compare'])->whereNumber('device')->name('compare');
    Route::get('/devices/{device}/download/{backup}', [BackupController::class, 'download'])
        ->whereNumber(['device', 'backup'])->name('download');
});
