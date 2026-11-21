<?php

namespace DRP\DeviceBackups\Backup;

use DRP\DeviceBackups\PluginDb;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class ManualBackupRequests {
    public function latest(int $device): ?object {
        return $this->runs()->where('requested_device_id', $device)->orderByDesc('id')->first();
    }

    public function pending(int $device): ?object {
        return $this->runs()->where('requested_device_id', $device)->whereIn('status', ['queued', 'running'])
            ->orderByDesc('id')->first();
    }

    /** Call while holding the LibreNMS device row lock. */
    public function enqueue(int $device): int {
        return $this->runs()->insertGetId([
            'requested_device_id' => $device,
            'requested_at' => now('UTC'),
            // The run timestamp is replaced when the worker starts this request.
            'started_at' => now('UTC'),
            'status' => 'queued',
        ]);
    }

    public function next(): ?object {
        return $this->runs()->where('status', 'queued')->whereNotNull('requested_device_id')
            ->orderBy('requested_at')->orderBy('id')->first();
    }

    public function isQueued(int $run, int $device): bool {
        return $this->request($run, $device)->where('status', 'queued')->exists();
    }

    public function claim(int $run, int $device): bool {
        return $this->request($run, $device)->where('status', 'queued')->update([
            'started_at' => now('UTC'),
            'status' => 'running',
        ]) === 1;
    }

    public function fail(int $run, int $device, bool $claimed, string $message): void {
        $this->request($run, $device)->where('status', $claimed ? 'running' : 'queued')
            ->update($this->failureFields($message));
    }

    public function expireInterrupted(): void {
        $this->runs()->whereNotNull('requested_device_id')->where('status', 'running')
            ->where('started_at', '<', now('UTC')->subHour())
            ->update($this->failureFields('The backup did not finish. Start another backup to retry.'));
    }

    private function request(int $run, int $device): Builder {
        return $this->runs()->where('id', $run)->where('requested_device_id', $device);
    }

    private function runs(): Builder {
        return DB::connection(PluginDb::getDbConnection())->table('device_backup_runs');
    }

    private function failureFields(string $message): array {
        return [
            'finished_at' => now('UTC'),
            'status' => 'failed',
            'email_status' => 'not_sent',
            'request_error' => $message,
        ];
    }
}
