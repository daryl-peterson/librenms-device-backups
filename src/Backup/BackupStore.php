<?php

namespace DRP\DeviceBackups\Backup;

use DRP\DeviceBackups\PluginDb;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * LibreNMS Device Backups Store Utility.
 *
 * @package     librenms-device-backups
 * @author      Daryl Peterson <daryl.peterson@gmail.com>
 * @copyright   Copyright (c) 2026, Daryl Peterson
 * @license     https://opensource.org MIT License
 * @link        https://github.com/daryl-peterson/
 * @since       0.0.1
 */
final class BackupStore {
    private const CHUNK_SIZE = 48 * 1024;
    private Connection $db;

    public function __construct() {
        $this->db = DB::connection(PluginDb::getDbConnection());
    }

    /**
     * Start a new backup run.
     *
     * @return int The ID of the newly started run.
     *
     * @since 0.0.1
     */
    public function start(): int {
        return $this->db->table('device_backup_runs')->insertGetId(['started_at' => now('UTC'), 'status' => 'running']);
    }

    /**
     * Finish a backup run.
     *
     * @param int $run The ID of the run to finish.
     * @param bool $failed Whether the run failed.
     * @param string $emailStatus The email status for the run.
     *
     * @since 0.0.1
     */
    public function finish(int $run, bool $failed, string $emailStatus): void {
        $this->db->table('device_backup_runs')->where('id', $run)->update([
            'finished_at' => now('UTC'),
            'status' => $failed ? 'failed' : 'success',
            'email_status' => $emailStatus,
        ]);
    }

    /**
     * Record the result of a backup run.
     *
     * @param array $result The result data to record.
     * @param string|null $archive The path to the encrypted archive, if any.
     * @return int The ID of the recorded result.
     *
     * @since 0.0.1
     */
    public function record(array $result, ?string $archive = null): int {
        return $this->db->transaction(function () use ($result, $archive): int {
            $id = $this->db->table('device_backup_results')->insertGetId($result);
            if ($archive === null) {
                return $id;
            }

            $stream = fopen($archive, 'rb');
            if (!$stream) {
                throw new BackupFailure('Could not read the encrypted archive for database storage.');
            }
            $hash = hash_init('sha256');
            $bytes = 0;
            $sequence = 0;
            try {
                while (!feof($stream)) {
                    $chunk = fread($stream, self::CHUNK_SIZE);
                    if ($chunk === false) {
                        throw new BackupFailure('Could not read the encrypted archive.');
                    }
                    if ($chunk === '') {
                        break;
                    }
                    $this->db->table('device_backup_chunks')->insert([
                        'result_id' => $id,
                        'sequence' => $sequence++,
                        'payload' => $chunk,
                    ]);
                    $bytes += strlen($chunk);
                    hash_update($hash, $chunk);
                }
            } finally {
                fclose($stream);
            }
            if ($bytes !== $result['archive_bytes'] || !hash_equals($result['sha256'], hash_final($hash))) {
                throw new BackupFailure('The archive changed while being stored.');
            }

            return $id;
        });
    }
    /**
     * Find a backup run by its ID.
     *
     * @param int $id The ID of the run to find.
     * @return object|null The run object if found, null otherwise.
     *
     * @since 0.0.1
     */
    public function findRun(int $id): ?object {
        return $this->db->table('device_backup_runs')->where('id', $id)->first();
    }

    /**
     * Get a paginated list of backup runs.
     *
     * @return Paginator
     *
     * @since 0.0.1
     */
    public function runs(): Paginator {
        $counts = $this->db->table('device_backup_results')
            ->whereColumn('run_id', 'device_backup_runs.id');

        return $this->db->table('device_backup_runs')->select('device_backup_runs.*')
            ->selectSub((clone $counts)->where('status', 'success')->selectRaw('count(*)'), 'succeeded')
            ->selectSub((clone $counts)->where('status', 'failed')->selectRaw('count(*)'), 'failed')
            ->orderByDesc('id')->simplePaginate(10, ['*'], 'runs_page')->withQueryString();
    }

    /**
     * Get a paginated list of backup results.
     *
     * @param int|null $device The device ID to filter by.
     * @param int|null $run The run ID to filter by.
     * @param string|null $status The status to filter by.
     * @return Paginator
     *
     * @since 0.0.1
     */
    public function results(?int $device = null, ?int $run = null, ?string $status = null): Paginator {
        return $this->resultDetails()
            ->when($device !== null, fn(Builder $query) => $query->where('results.device_id', $device))
            ->when($run !== null, fn(Builder $query) => $query->where('results.run_id', $run))
            ->when($status !== null, fn(Builder $query) => $query->where('results.status', $status))
            ->orderByDesc('results.id')->simplePaginate(25)->withQueryString();
    }

    /**
     * Get the latest backup attempts for the given devices.
     *
     * @param array $devices The list of device IDs.
     * @return Collection
     *
     * @since 0.0.1
     */
    public function latestAttempts(array $devices): Collection {
        $latest = $this->db->table('device_backup_results')
            ->selectRaw('max(id) as id')
            ->whereIn('device_id', $devices)
            ->groupBy('device_id');

        return $this->resultDetails()->whereIn('results.id', $latest)->get()->keyBy('device_id');
    }

    /**
     * Find a downloadable backup for the given device and optional result ID.
     *
     * @param int $device The device ID.
     * @param int|null $id The result ID, if any.
     * @return object|null The downloadable backup object if found, null otherwise.
     *
     * @since 0.0.1
     */
    public function findDownload(int $device, ?int $id = null): ?object {
        return $this->downloadable()->where('results.device_id', $device)
            ->when($id !== null, fn(Builder $query) => $query->where('results.id', $id))
            ->orderByDesc('results.id')->first();
    }

    /**
     * Get the details of backup results.
     *
     * @return Builder
     *
     * @since 0.0.1
     */
    private function resultDetails(): Builder {
        return $this->db->table('device_backup_results as results')
            ->join('device_backup_runs as runs', 'runs.id', '=', 'results.run_id')
            ->select('results.*', 'runs.started_at as run_started_at');
    }

    /**
     * Get the query builder for downloadable backup results.
     *
     * @return Builder
     *
     * @since 0.0.1
     */
    private function downloadable(): Builder {
        return $this->resultDetails()->where('results.status', 'success')
            ->whereNotNull('results.sha256')->where('results.archive_bytes', '>', 0);
    }

    /**
     * Export a downloadable backup to the specified path.
     *
     * @param int $id The result ID of the backup to export.
     * @param string $path The path to export the backup to.
     *
     * @throws BackupFailure If the export fails.
     *
     * @since 0.0.1
     */
    public function export(int $id, string $path): void {
        $result = $this->downloadable()->where('results.id', $id)->first();
        if (!$result) {
            throw new BackupFailure('No successful backup has that result ID.');
        }
        if (file_exists($path) || !is_dir(dirname($path))) {
            throw new BackupFailure('Choose a new filename in an existing directory.');
        }
        $temporary = dirname($path) . '/.' . bin2hex(random_bytes(12)) . '.partial';
        $stream = fopen($temporary, 'xb');
        if (!$stream) {
            throw new BackupFailure('Could not open the backup export file.');
        }
        try {
            $this->copyVerifiedArchive($result, $stream);
            if (!fflush($stream) || !@link($temporary, $path)) {
                throw new BackupFailure('Could not publish the export. Choose a new, writable filename.');
            }
        } finally {
            fclose($stream);
            unlink($temporary);
        }
    }
    /**
     * Copy the verified archive to the given stream.
     *
     * @param object $result The backup result object.
     * @param resource $stream The stream to copy the archive to.
     *
     * @throws BackupFailure If the copy or verification fails.
     *
     * @since 0.0.1
     */
    public function copyVerifiedArchive(object $result, $stream): void {
        $hash = hash_init('sha256');
        $size = 0;
        $chunks = $this->db->table('device_backup_chunks')->where('result_id', $result->id)->lazyById(20, 'sequence');
        foreach ($chunks as $chunk) {
            if (fwrite($stream, $chunk->payload) !== strlen($chunk->payload)) {
                throw new BackupFailure('Could not write the backup export.');
            }
            hash_update($hash, $chunk->payload);
            $size += strlen($chunk->payload);
        }
        if ($size !== (int) $result->archive_bytes || !hash_equals($result->sha256, hash_final($hash))) {
            throw new BackupFailure('The stored archive failed checksum verification.');
        }
    }
}
