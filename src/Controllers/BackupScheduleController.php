<?php

namespace DRP\DeviceBackups\Controllers;

use DateTimeZone;
use DRP\DeviceBackups\Backup\BackupSchedule;
use DRP\DeviceBackups\Middleware\RequireBackupDatabase;
use DRP\DeviceBackups\PluginSettings;
use DRP\DeviceBackups\TraitValidateAdmin;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

final class BackupScheduleController extends Controller {
    use TraitValidateAdmin;

    public function __construct() {
        $this->middleware(RequireBackupDatabase::class);
    }

    public function edit(): View {
        $settings = $this->settings();
        $schedule = BackupSchedule::fromSettings($settings->all());

        return view('device-backups::backup-schedule', [
            'schedule' => $schedule,
            'weekdays' => BackupSchedule::WEEKDAYS,
            'timezones' => DateTimeZone::listIdentifiers(),
            'nextRun' => $schedule->nextRun(),
        ]);
    }

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
            return redirect()->route('device-backups.backups.schedule')
                ->withInput($data)->with('error', 'Could not save the backup schedule.');
        }

        return redirect()->route('device-backups.backups.schedule')
            ->with('success', $schedule->enabled ? 'Backup schedule saved.' : 'Scheduled backups are paused.');
    }

    private function settings(): PluginSettings {
        $this->validateAdmin();
        $settings = new PluginSettings();
        abort_unless($settings->plugin?->plugin_active, 404);

        return $settings;
    }
}
