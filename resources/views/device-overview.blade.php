<div class="panel panel-default">

    <div class="panel-heading" style="display: flex; align-items: center; gap: 0.5rem;">
        <i class="fa fa-floppy-o fa-2x" aria-hidden="true"></i>
        <span><strong>{{ $title }}</strong></span>
    </div>
    <div class="panel-body">
        @if ($latest)
            <p>Latest successful backup from run #{{ $latest->run_id }} ({{ \DRP\DeviceBackups\Helper::localTime($latest->run_started_at) }}).</p>
            <a class="btn btn-primary btn-sm"
                href="{{ route('device-backups.backups.latest', ['device' => $device->getKey()]) }}">Download Latest Backup</a>
        @elseif ($unavailable)
            <p>The backup database is unavailable.</p>
        @else
            <p>No successful backup yet.</p>
        @endif
        @unless ($unavailable)
            <a class="btn btn-default btn-sm" href="{{ route('device-backups.backups.history', ['device' => $device->getKey()]) }}">View
                Backup History</a>
        @endunless
        <a class="btn btn-default btn-sm" href="{{ route('device-backups.backups.ssh.edit', ['device' => $device->getKey()]) }}">SSH
            Settings</a>
        @include('device-backups::partials.run-backup', ['deviceId' => $device->getKey()])
    </div>
</div>
