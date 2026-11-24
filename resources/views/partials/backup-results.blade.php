@php($showRun = $showRun ?? true)

<div class="table-responsive">
    <table class="table table-striped">
        <thead>
            <tr>
                @if ($showDevice)
                    <th scope="col">Device</th>
                @endif
                @if ($showRun)
                    <th scope="col">Run</th>
                @endif
                <th scope="col">Run started ({{ isset($formatRunStarted) ? 'local time' : 'UTC' }})</th>
                <th scope="col">Profile</th>
                <th scope="col">Status</th>
                <th scope="col">Details</th>
                <th scope="col">Archive</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($results as $result)
                <tr>
                    @if ($showDevice)
                        <td><a
                                href="{{ route('device-backups.backups.history', ['device' => $result->device_id]) }}">{{ $result->hostname }}</a>
                        </td>
                    @endif
                    @if ($showRun)
                        <td><a
                                href="{{ route('device-backups.backups.status', ['run' => $result->run_id]) }}">#{{ $result->run_id }}</a>
                        </td>
                    @endif
                    <td>{{ isset($formatRunStarted) ? $formatRunStarted($result->run_started_at) : $result->run_started_at }}</td>
                    <td>{{ $result->profile ?? '—' }}</td>
                    <td>@include('device-backups::partials.backup-status-badge', ['state' => $result->status])</td>
                    <td>{{ $result->error ?? '—' }}</td>
                    <td>
                        @if ($result->status === 'success' && $result->sha256 && $result->archive_bytes > 0)
                            <a href="{{ route('device-backups.backups.download', ['device' => $result->device_id, 'backup' => $result->id]) }}"
                                class="btn btn-info mr-3" title="Download Backup" aria-label="Download Backup">
                                <i class="fa fa-download" aria-hidden="true"></i>
                            </a>
                        @else
                            —
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ 5 + (int) $showDevice + (int) $showRun }}">No backup attempts found.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
{{ $results->links('pagination::simple-bootstrap-3') }}
