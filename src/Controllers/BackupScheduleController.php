<?php

namespace DRP\DeviceBackups\Controllers;

use DateTimeZone;

use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

use DRP\DeviceBackups\PluginData;
use DRP\DeviceBackups\Backup\BackupSchedule;
use DRP\DeviceBackups\Middleware\RequireBackupDatabase;
use DRP\DeviceBackups\PluginSettings;
use DRP\DeviceBackups\TraitValidateAdmin;

/**
 * LibreNMS Device Backups Schedule Controller.
 *
 * @package     librenms-device-backups
 * @author      Daryl Peterson <daryl.peterson@gmail.com>
 * @copyright   Copyright (c) 2026, Daryl Peterson
 * @license     https://opensource.org MIT License
 * @link        https://github.com/daryl-peterson/
 * @since       0.0.1
 */
final class BackupScheduleController extends Controller {
    use TraitValidateAdmin;

    /**
     * The plugin data instance.
     *
     * @version 0.0.1
     * @since 0.0.1
     */
    private PluginData $plugin;

    /**
     * PluginData constructor.
     *
     * @version 0.0.1
     * @since 0.0.1
     */
    public function __construct() {
        $this->middleware(RequireBackupDatabase::class);
        $this->plugin = PluginData::getInstance();
    }

    /**
     * Show the form for editing the backup schedule.
     *
     * @version 0.0.1
     * @since 0.0.1
     */
    public function edit(): View {
        $settings = $this->settings();
        $schedule = BackupSchedule::fromSettings($settings->all());

        return view('device-backups::backup-schedule', [
            'schedule' => $schedule,
            'weekdays' => BackupSchedule::WEEKDAYS,
            'timezones' => DateTimeZone::listIdentifiers(),
            'nextRun' => $schedule->nextRun(),
            'plugin' => $this->plugin,
        ]);
    }

    /**
     * Update the backup schedule.
     *
     * @version 0.0.1
     * @since 0.0.1
     */
    public function update(Request $request): RedirectResponse {
        $settings = $this->settings();
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'days' => ['required', 'array', 'min:1', 'max:7'],
            'days.*' => ['required', 'integer', 'distinct', 'between:0,6'],
            'time' => ['required', 'date_format:H:i'],
            'timezone' => ['required', 'timezone'],
        ], [
            'days.required' => 'Select at least one day.',
        ], [
            'days.*' => 'day',
            'timezone' => 'time zone',
        ]);

        $days = array_map('intval', array_values($data['days']));
        sort($days);
        $schedule = new BackupSchedule((bool) $data['enabled'], $days, $data['time'], $data['timezone']);

        try {
            $saved = $settings->saveSchedule($schedule);
        } catch (QueryException) {
            $saved = false;
        }

        if (!$saved) {
            return redirect()->route($this->plugin->name . '.backups.schedule')
                ->withInput($data)->with('error', 'Could not save the backup schedule.');
        }

        return redirect()->route($this->plugin->name . '.backups.schedule')
            ->with('success', $schedule->enabled ? 'Backup schedule saved.' : 'Scheduled backups are paused.');
    }

    /**
     * Get the plugin settings.
     *
     * @version 0.0.1
     * @since 0.0.1
     */
    private function settings(): PluginSettings {
        $this->validateAdmin();
        $settings = new PluginSettings();
        abort_unless($settings->plugin?->plugin_active, 404);

        return $settings;
    }
}
