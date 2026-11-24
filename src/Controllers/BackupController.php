<?php

namespace DRP\DeviceBackups\Controllers;

use App\Models\Device;

use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

use DRP\DeviceBackups\Backup\BackupComparison;
use DRP\DeviceBackups\Backup\BackupFailure;
use DRP\DeviceBackups\Backup\BackupStore;
use DRP\DeviceBackups\Backup\ManualBackupRequests;
use DRP\DeviceBackups\Backup\Target;
use DRP\DeviceBackups\Helper;
use DRP\DeviceBackups\Middleware\RequireBackupDatabase;
use DRP\DeviceBackups\PluginData;
use DRP\DeviceBackups\TraitValidateAdmin;

/**
 * LibreNMS Device Backup Controller.
 *
 * @package     librenms-device-backups
 * @author      Daryl Peterson <daryl.peterson@gmail.com>
 * @copyright   Copyright (c) 2026, Daryl Peterson
 * @license     https://opensource.org MIT License
 * @link        https://github.com/daryl-peterson/
 * @since       0.0.1
 */
final class BackupController extends Controller {
    use TraitValidateAdmin;

    private PluginData $plugin;

    public function __construct() {
        $this->middleware(RequireBackupDatabase::class);
        $this->plugin = PluginData::getInstance();
    }

    /**
     * Display the status of backup runs.
     *
     * @param Request $request
     * @return View|RedirectResponse
     *
     * @since 0.0.1
     */
    public function status(Request $request): View|RedirectResponse {
        $store = $this->store();
        $run = filter_var($request->query('run'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $status = $request->query('status');
        $status = in_array($status, ['success', 'failed'], true) ? $status : null;
        $search = $this->searchTerm($request->query('search'));
        [$sort, $direction] = $this->resultSort($request, ['run', 'archive']);

        $pname = $this->plugin->name;

        try {
            return view($pname . '::backup-status', [
                'plugin' => $this->plugin,
                'runs' => $store->runs(),
                'results' => $store->results(run: $run, status: $status, search: $search, sort: $sort, direction: $direction)
                    ->fragment('backup-attempts'),
                'run' => $run,
                'selectedRun' => $run === null ? null : $store->findRun($run),
                'status' => $status,
                'search' => $search,
                'sort' => $sort,
                'direction' => $direction,
            ]);
        } catch (QueryException) {
            return $this->backupDatabaseSettings();
        }
    }

    /**
     * Display the list of devices available for backup.
     *
     * @param Request $request
     * @param ManualBackupRequests $requests
     * @return View|RedirectResponse
     *
     * @since 0.0.1
     */
    public function devices(Request $request, ManualBackupRequests $requests): View|RedirectResponse {
        $store = $this->store();
        $search = $this->searchTerm($request->query('search'));
        $inventory = Target::inventory($search)->withExists([
            'attribs as backup_enrolled' => fn($query) => $query->where('attrib_type', Target::ATTRIBUTE),
        ])->simplePaginate(25)->withQueryString();

        try {
            $deviceIds = $inventory->getCollection()->modelKeys();
            $pname = $this->plugin->name;

            return view($pname . '::backup-devices', [
                'plugin' => $this->plugin,
                'devices' => $inventory,
                'attempts' => $store->latestAttempts($deviceIds),
                'pendingRequests' => $requests->pendingFor($deviceIds),
                'search' => $search,
            ]);
        } catch (QueryException) {
            return $this->backupDatabaseSettings();
        }
    }

    /**
     * Display the backup history for a specific device.
     *
     * @param int $device
     * @param ManualBackupRequests $requests
     * @return View|RedirectResponse
     *
     * @since 0.0.1
     */
    public function history(int $device, ManualBackupRequests $requests): View|RedirectResponse {
        $store = $this->store();
        $model = $this->visibleDevice($device);
        [$sort, $direction] = $this->resultSort(request(), ['device']);

        try {
            $results = $store->results(device: $device, sort: $sort, direction: $direction)->fragment('backup-attempts');
            $archives = $store->recentDownloads($device);
            $hostname = $model?->hostname ?? $store->latestAttempts([$device])->get($device)?->hostname;
            abort_if($hostname === null, 404);

            return view($this->plugin->name . '::backup-device', [
                'plugin' => $this->plugin,
                'device' => $model,
                'deviceId' => $device,
                'hostname' => $hostname,
                'results' => $results,
                'sort' => $sort,
                'direction' => $direction,
                'latest' => $archives->first(),
                'canCompare' => BackupComparison::supports($archives->first(), $archives->get(1)),
                'manualRequest' => $requests->pending($device) ?? $requests->latest($device),
                'canStartBackup' => $model !== null && !$model->disabled && Target::revision($model) !== null,
            ]);
        } catch (QueryException) {
            return $this->backupDatabaseSettings();
        }
    }

    /**
     * Redirect to the backup settings page with an error message if the backup database cannot be loaded.
     *
     * @return RedirectResponse
     *
     * @since 0.0.1
     */
    private function backupDatabaseSettings(): RedirectResponse {
        return redirect()->route('device-backups.settings')->with(
            'error',
            'Could not load the backup database. Test and save the connection in Settings, then run migrations.'
        );
    }

    /**
     * Start a backup for the specified device.
     *
     * @param Request $request
     * @param Device $device
     * @param ManualBackupRequests $requests
     * @return RedirectResponse
     *
     * @since 0.0.1
     */
    public function start(Request $request, Device $device, ManualBackupRequests $requests): RedirectResponse {
        $this->authorizePlugin();
        Gate::authorize('view', $device);
        $search = $this->searchTerm($request->query('search'));
        $redirect = $request->query('from') === 'devices'
            ? redirect()->route('device-backups.backups.devices', [
                'search' => $search !== '' ? $search : null,
                'page' => filter_var($request->query('page'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 2]]) ?: null,
            ])
            : redirect()->route('device-backups.backups.history', ['device' => $device->getKey()]);

        $password = config('device-backups.archive_password');
        if (!is_string($password) || $password === '') {
            return $redirect->with('error', 'Set DEVICE_BACKUPS_ARCHIVE_PASSWORD before starting a backup.');
        }

        try {
            [$id, $queued] = Target::withLockedDevice($device, static function (Device $current) use ($requests): array {
                if ($current->disabled || Target::revision($current) === null) {
                    throw new BackupFailure('Enable the device and save its SSH settings before starting a backup.');
                }

                $pending = $requests->pending($current->getKey());
                return $pending
                    ? [$pending->id, false]
                    : [$requests->enqueue($current->getKey()), true];
            });
        } catch (BackupFailure $e) {
            return $redirect->with('error', $e->getMessage());
        } catch (QueryException) {
            return $redirect->with('error', 'Could not queue the backup. Check the backup database.');
        }

        return $redirect->with(
            $queued ? 'success' : 'info',
            $queued ? "Backup #$id queued. LibreNMS will pick it up on a scheduler pass." : "Backup #$id is already queued or running.",
        );
    }

    /**
     * Retrieve the latest backup for the specified device.
     *
     * @param int $device
     * @return StreamedResponse
     *
     * @since 0.0.1
     */
    public function latest(int $device): StreamedResponse {
        return $this->sendArchive($device);
    }

    public function compare(int $device): Response {
        $store = $this->store();
        $model = $this->visibleDevice($device);
        $password = config('device-backups.archive_password');
        $diff = null;
        $error = null;

        try {
            $archives = $store->recentDownloads($device);
            $latest = $archives->first();
            $previous = $archives->get(1);
            abort_unless($previous !== null, 404, 'Keep at least two successful backups to compare.');
            if (!is_string($password) || $password === '') {
                throw new BackupFailure('The backup archive password is unavailable.');
            }
            $diff = BackupComparison::compare($store, $previous, $latest, $password);
        } catch (BackupFailure $e) {
            $error = $e->getMessage();
        } catch (QueryException) {
            abort(503, 'The backup database is unavailable.');
        }

        return response()->view($this->plugin->name . '::backup-comparison', [
            'plugin' => $this->plugin,
            'deviceId' => $device,
            'hostname' => $model?->hostname ?? $latest->hostname,
            'previous' => $previous,
            'latest' => $latest,
            'diff' => $diff,
            'error' => $error,
        ], $error === null ? 200 : 422, ['Cache-Control' => 'private, no-store']);
    }

    /**
     * Download a specific backup for the specified device.
     *
     * @param int $device
     * @param int $backup
     * @return StreamedResponse
     *
     * @since 0.0.1
     */
    public function download(int $device, int $backup): StreamedResponse {
        return $this->sendArchive($device, $backup);
    }

    /**
     * Send the backup archive for the specified device and backup.
     *
     * @param int $device
     * @param int|null $backup
     * @return StreamedResponse
     *
     * @since 0.0.1
     */
    private function sendArchive(int $device, ?int $backup = null): StreamedResponse {
        $store = $this->store();
        $model = $this->visibleDevice($device);

        try {
            $result = $store->findDownload($device, $backup);
            abort_unless($result, 404);
            $filename = $this->archiveFilename($model, $result);

            $stream = tmpfile();
            abort_unless($stream, 503, 'Could not prepare the backup download.');
            try {
                $store->copyVerifiedArchive($result, $stream);
                if (!rewind($stream)) {
                    throw new BackupFailure('Could not prepare the verified backup.');
                }
            } catch (BackupFailure $e) {
                fclose($stream);
                abort(503, $e->getMessage());
            } catch (QueryException $e) {
                fclose($stream);
                throw $e;
            }
        } catch (QueryException) {
            abort(503, 'The backup database is unavailable.');
        }

        return response()->streamDownload(static function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, $filename, [
            'Content-Type' => 'application/zip',
            'Content-Length' => (string) $result->archive_bytes,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Generate the filename for the backup archive.
     *
     * @param Device|null $device
     * @param object $result
     * @return string
     *
     * @since 0.0.1
     */
    private function archiveFilename(?Device $device, object $result): string {
        $name = trim((string) $device?->sysName);
        if ($name === '') {
            $name = trim($result->hostname);
        }
        if (!filter_var($name, FILTER_VALIDATE_IP)) {
            $name = explode('.', $name, 2)[0];
        }

        $name = trim(preg_replace('/[^A-Za-z0-9_.-]+/', '-', $name), '._-');
        $name = $name !== '' ? $name : "device-{$result->device_id}";
        $date = Helper::localTime($result->run_started_at, 'YmdHs');

        return "$name.$date.zip";
    }

    private function resultSort(Request $request, array $excludedColumns): array {
        $sort = $request->query('sort');
        if (!is_string($sort) || !isset(BackupStore::RESULT_SORT_COLUMNS[$sort]) || in_array($sort, $excludedColumns, true)) {
            $sort = 'started';
        }
        $direction = $request->query('direction');

        return [$sort, in_array($direction, ['asc', 'desc'], true) ? $direction : BackupStore::defaultSortDirection($sort)];
    }

    /**
     * Normalize a search term from a query parameter.
     *
     * @param mixed $value
     * @return string
     *
     * @since 0.0.1
     */
    private function searchTerm(mixed $value): string {
        return is_string($value) ? mb_substr(trim($value), 0, 128) : '';
    }

    /**
     * Get an instance of the backup store.
     *
     * @return BackupStore
     *
     * @since 0.0.1
     */
    private function store(): BackupStore {
        $this->authorizePlugin();

        return new BackupStore();
    }

    /**
     * Authorize the plugin for the current user.
     *
     * @return void
     *
     * @since 0.0.1
     */
    private function authorizePlugin(): void {
        $this->validateAdmin();
        abort_unless(PluginData::getPluginModel()?->plugin_active, 404);
    }

    /**
     * Retrieve a device if it is visible to the current user.
     *
     * @param integer $id
     * @return Device|null
     */
    private function visibleDevice(int $id): ?Device {
        $device = Device::find($id);
        if ($device !== null) {
            Gate::authorize('view', $device);
        }

        return $device;
    }
}
