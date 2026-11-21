@php($pending = in_array($manualRequest?->status, ['queued', 'running'], true))
@if ($canStartBackup && !$pending)
    <form method="POST" class="form-inline tw:inline-block"
        action="{{ route('device-backups.backups.start', ['device' => $deviceId]) }}">
        @csrf
        <button type="submit" class="btn btn-primary btn-sm">Run Backup</button>
    </form>
@endif
@if ($manualRequest)
    <p class="help-block" role="status">
        <a href="{{ route('device-backups.backups.status', ['run' => $manualRequest->id]) }}">Manual backup #{{ $manualRequest->id }}</a>
        @include('device-backups::partials.backup-status-badge', ['state' => $manualRequest->status])
        @if ($manualRequest->request_error)
            {{ $manualRequest->request_error }}
        @endif
    </p>
@endif
