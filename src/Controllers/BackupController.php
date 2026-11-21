<?php

namespace DRP\DeviceBackups\Controllers;

use App\Models\Device;
use DRP\DeviceBackups\Backup\BackupFailure;
use DRP\DeviceBackups\Backup\BackupStore;
use DRP\DeviceBackups\Backup\Target;
use DRP\DeviceBackups\PluginData;
use DRP\DeviceBackups\TraitValidateAdmin;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class BackupController extends Controller {
    use TraitValidateAdmin;

    public function status(Request $request): View {
        $store = $this->store();
        $run = filter_var($request->query('run'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $status = $request->query('status');
        $status = in_array($status, ['success', 'failed'], true) ? $status : null;

        try {
            return view('device-backups::backup-status', [
                'runs' => $store->runs(),
                'results' => $store->results(run: $run, status: $status),
                'run' => $run,
                'status' => $status,
            ]);
        } catch (QueryException) {
            abort(503, 'The backup database is unavailable.');
        }
    }

    public function devices(Request $request): View {
        $store = $this->store();
        $search = $request->query('search');
        $search = is_string($search) ? mb_substr(trim($search), 0, 128) : '';
        $inventory = Device::query()->withExists([
            'attribs as backup_enrolled' => fn ($query) => $query->where('attrib_type', Target::ATTRIBUTE),
        ])->when($search !== '', function ($query) use ($search): void {
            $query->where(function ($query) use ($search): void {
                $query->whereLike('hostname', "%$search%")
                    ->orWhereLike('sysName', "%$search%");
                if (ctype_digit($search)) {
                    $query->orWhere('device_id', $search);
                }
            });
        })->orderBy('hostname')->orderBy('device_id')->simplePaginate(25)->withQueryString();

        try {
            return view('device-backups::backup-devices', [
                'devices' => $inventory,
                'attempts' => $store->latestAttempts($inventory->getCollection()->modelKeys()),
                'search' => $search,
            ]);
        } catch (QueryException) {
            abort(503, 'The backup database is unavailable.');
        }
    }

    public function history(int $device): View {
        $store = $this->store();
        $model = $this->visibleDevice($device);

        try {
            $results = $store->results(device: $device);
            $hostname = $model?->hostname ?? $store->latestAttempts([$device])->get($device)?->hostname;
            abort_if($hostname === null, 404);

            return view('device-backups::backup-device', [
                'device' => $model,
                'deviceId' => $device,
                'hostname' => $hostname,
                'results' => $results,
                'latest' => $store->findDownload($device),
            ]);
        } catch (QueryException) {
            abort(503, 'The backup database is unavailable.');
        }
    }

    public function latest(int $device): StreamedResponse {
        return $this->sendArchive($device);
    }

    public function download(int $device, int $backup): StreamedResponse {
        return $this->sendArchive($device, $backup);
    }

    private function sendArchive(int $device, ?int $backup = null): StreamedResponse {
        $store = $this->store();
        $this->visibleDevice($device);

        try {
            $result = $store->findDownload($device, $backup);
            abort_unless($result, 404);

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
        }, "device-$device-backup-{$result->id}.zip", [
            'Content-Type' => 'application/zip',
            'Content-Length' => (string) $result->archive_bytes,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function store(): BackupStore {
        $this->validateAdmin();
        abort_unless(PluginData::getPluginModel()?->plugin_active, 404);

        return new BackupStore();
    }

    private function visibleDevice(int $id): ?Device {
        $device = Device::find($id);
        if ($device !== null) {
            Gate::authorize('view', $device);
        }

        return $device;
    }
}
