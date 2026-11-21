<?php

namespace DRP\DeviceBackups\Hooks;

use App\Models\Device;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use App\Plugins\Hooks\DeviceOverviewHook;
use DRP\DeviceBackups\Backup\BackupStore;
use Illuminate\Database\QueryException;

final class DeviceOverview extends DeviceOverviewHook {
    public function authorize(?Authenticatable $user, Device $device): bool {
        return $user instanceof User && $user->hasRole('admin') && $user->can('view', $device);
    }

    public function data(Device $device): array {
        $latest = null;
        $unavailable = false;
        try {
            $latest = (new BackupStore())->findDownload($device->getKey());
        } catch (QueryException) {
            $unavailable = true;
        }

        return ['device' => $device, 'latest' => $latest, 'unavailable' => $unavailable];
    }
}
