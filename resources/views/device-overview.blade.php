<div class="panel panel-default">
    <div class="panel-heading"><strong>Device Backups</strong></div>
    <div class="panel-body">
        @if ($latest)
            <p>Latest successful backup from run #{{ $latest->run_id }} ({{ $latest->run_started_at }} UTC).</p>
            <a class="btn btn-primary btn-sm" href="{{ route('device-backups.backups.latest', ['device' => $device->getKey()]) }}">Download Latest Backup</a>
        @elseif ($unavailable)
            <p>The backup database is unavailable.</p>
        @else
            <p>No successful backup yet.</p>
        @endif
        @unless ($unavailable)
            <a class="btn btn-default btn-sm" href="{{ route('device-backups.backups.history', ['device' => $device->getKey()]) }}">View Backup History</a>
        @endunless
        <a class="btn btn-default btn-sm" href="{{ route('device-backups.backups.ssh.edit', ['device' => $device->getKey()]) }}">SSH Settings</a>
    </div>
</div>
