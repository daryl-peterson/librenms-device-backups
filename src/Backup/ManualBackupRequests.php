<?php

namespace DRP\DeviceBackups\Backup;

use DRP\DeviceBackups\PluginDb;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * LibreNMS Device Backups Manual Backup Requests Utility.
 *
 * @package     librenms-device-backups
 * @author      Daryl Peterson <daryl.peterson@gmail.com>
 * @copyright   Copyright (c) 2026, Daryl Peterson
 * @license     https://opensource.org MIT License
 * @link        https://github.com/daryl-peterson/
 * @since       0.0.1
 */
final class ManualBackupRequests {

    /**
     * Get the latest manual backup request for the given device.
     *
     * @param int $device The device ID.
     *
     * @return object|null The latest backup request or null if none exists.
     *
     * @since 0.0.1
     */
    public function latest(int $device): ?object {
        return $this->runs()->where('requested_device_id', $device)->orderByDesc('id')->first();
    }

    /**
     * Get the pending manual backup request for the given device.
     *
     * @param int $device The device ID.
     *
     * @return object|null The pending backup request or null if none exists.
     *
     * @since 0.0.1
     */
    public function pending(int $device): ?object {
        return $this->pendingRuns()->where('requested_device_id', $device)->orderByDesc('id')->first();
    }

    /**
     * Get the latest pending manual backup requests for the given devices.
     *
     * @param array $devices The device IDs.
     *
     * @return Collection The collection of pending backup requests keyed by device ID.
     *
     * @since 0.0.1
     */
    public function pendingFor(array $devices): Collection {
        $latest = $this->pendingRuns()->whereIn('requested_device_id', $devices)
            ->selectRaw('max(id) as id')->groupBy('requested_device_id');

        return $this->runs()->whereIn('id', $latest)->get()->keyBy('requested_device_id');
    }

    private function pendingRuns(): Builder {
        return $this->runs()->whereIn('status', ['queued', 'running']);
    }

    /**
     * Enqueue a new manual backup request for the given device.
     *
     * Call while holding the LibreNMS device row lock.
     *
     * @param int $device The device ID.
     *
     * @return int The ID of the newly created backup request.
     *
     * @since 0.0.1
     */
    public function enqueue(int $device): int {
        return $this->runs()->insertGetId([
            'requested_device_id' => $device,
            'requested_at' => now('UTC'),
            // The run timestamp is replaced when the worker starts this request.
            'started_at' => now('UTC'),
            'status' => 'queued',
        ]);
    }

    /**
     * Get the next queued manual backup request.
     *
     * @return object|null The next queued backup request or null if none exists.
     *
     * @since 0.0.1
     */
    public function next(): ?object {
        return $this->runs()->where('status', 'queued')->whereNotNull('requested_device_id')
            ->orderBy('requested_at')->orderBy('id')->first();
    }

    /**
     * Check if a specific manual backup request is queued.
     *
     * @param int $run The backup request ID.
     * @param int $device The device ID.
     *
     * @return bool True if the request is queued, false otherwise.
     *
     * @since 0.0.1
     */
    public function isQueued(int $run, int $device): bool {
        return $this->request($run, $device)->where('status', 'queued')->exists();
    }

    /**
     * Claim a queued manual backup request for processing.
     *
     * @param int $run The backup request ID.
     * @param int $device The device ID.
     *
     * @return bool True if the request was successfully claimed, false otherwise.
     *
     * @since 0.0.1
     */
    public function claim(int $run, int $device): bool {
        return $this->request($run, $device)->where('status', 'queued')->update([
            'started_at' => now('UTC'),
            'status' => 'running',
        ]) === 1;
    }

    /**
     * Mark a manual backup request as failed.
     * Expire interrupted manual backup requests that have been running for too long.
     *
     * @param int $run The backup request ID.
     * @param int $device The device ID.
     * @param bool $claimed Whether the request was claimed.
     * @param string $message The failure message.
     *
     * @since 0.0.1
     */
    public function fail(int $run, int $device, bool $claimed, string $message): void {
        $this->request($run, $device)->where('status', $claimed ? 'running' : 'queued')
            ->update($this->failureFields($message));
    }

    /**
     * Expire interrupted manual backup requests that have been running for too long.
     *
     * @since 0.0.1
     */
    public function expireInterrupted(): void {
        $this->runs()->whereNotNull('requested_device_id')->where('status', 'running')
            ->where('started_at', '<', now('UTC')->subHour())
            ->update($this->failureFields('The backup did not finish. Start another backup to retry.'));
    }

    /**
     * Get the next queued manual backup request.
     *
     * @param int $device The device ID.
     * @return object|null The next queued backup request or null if none exists.
     *
     * @since 0.0.1
     */
    private function request(int $run, int $device): Builder {
        return $this->runs()->where('id', $run)->where('requested_device_id', $device);
    }

    /**
     * Get the query builder for the device backup runs table.
     *
     * @return Builder The query builder instance.
     *
     * @since 0.0.1
     */
    private function runs(): Builder {
        return DB::connection(PluginDb::getDbConnection())->table('device_backup_runs');
    }

    /**
     * Get the failure fields for updating a failed backup request.
     *
     * @param string $message The failure message.
     * @return array The failure fields.
     *
     * @since 0.0.1
     */
    private function failureFields(string $message): array {
        return [
            'finished_at' => now('UTC'),
            'status' => 'failed',
            'email_status' => 'not_sent',
            'request_error' => $message,
        ];
    }
}
