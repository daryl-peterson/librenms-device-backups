<?php

namespace DRP\DeviceBackups\Hooks;

use App\Models\Device;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use App\Plugins\Hooks\DeviceOverviewHook;
use DRP\DeviceBackups\Backup\BackupStore;
use DRP\DeviceBackups\Backup\ManualBackupRequests;
use DRP\DeviceBackups\Backup\Target;
use DRP\DeviceBackups\PluginDb;
use DRP\DeviceBackups\PluginData;

/**
 * Device overview hook for the device backups plugin.
 */
final class DeviceOverview extends DeviceOverviewHook {
    public function authorize(?Authenticatable $user, Device $device): bool {
        return $user instanceof User && $user->hasRole('admin') && $user->can('view', $device) && PluginDb::isReady();
    }

    public function data(Device $device): array {
        $latest = null;
        $manualRequest = null;
        $unavailable = false;
        $obj = PluginData::getInstance();
        try {
            $latest = (new BackupStore())->findDownload($device->getKey());
            $requests = new ManualBackupRequests();
            $manualRequest = $requests->pending($device->getKey()) ?? $requests->latest($device->getKey());
        } catch (QueryException) {
            $unavailable = true;
        }

        return [
            'title' => $obj->title,
            'device' => $device,
            'latest' => $latest,
            'unavailable' => $unavailable,
            'manualRequest' => $manualRequest,
            'canStartBackup' => !$unavailable && !$device->disabled && Target::revision($device) !== null,
        ];
    }
}
