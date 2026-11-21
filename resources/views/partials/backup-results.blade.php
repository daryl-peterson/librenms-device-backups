<div class="table-responsive">
    <table class="table table-striped">
        <thead>
            <tr>
                @if ($showDevice)
                    <th scope="col">Device</th>
                @endif
                <th scope="col">Run</th>
                <th scope="col">Run started (UTC)</th>
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
                        <td><a href="{{ route('device-backups.backups.history', ['device' => $result->device_id]) }}">{{ $result->hostname }}</a></td>
                    @endif
                    <td><a href="{{ route('device-backups.backups.status', ['run' => $result->run_id]) }}">#{{ $result->run_id }}</a></td>
                    <td>{{ $result->run_started_at }}</td>
                    <td>{{ $result->profile ?? '—' }}</td>
                    <td>@include('device-backups::partials.backup-status-badge', ['state' => $result->status])</td>
                    <td>{{ $result->error ?? '—' }}</td>
                    <td>
                        @if ($result->status === 'success' && $result->sha256 && $result->archive_bytes > 0)
                            <a href="{{ route('device-backups.backups.download', ['device' => $result->device_id, 'backup' => $result->id]) }}">Download #{{ $result->id }}</a>
                        @else
                            —
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="{{ $showDevice ? 7 : 6 }}">No backup attempts found.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
{{ $results->links('pagination::simple-bootstrap-3') }}
