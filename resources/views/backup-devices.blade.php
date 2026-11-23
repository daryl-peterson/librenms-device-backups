@extends('layouts.librenmsv1')

@section('content')
    @component($plugin->name . '::layouts.panel', ['wide' => true, 'plugin' => $plugin])
        <div style="font-size: 1.1em; font-weight: 600; margin-bottom: .5em;">Backups by Device</div>
        @include($plugin->name . '::layouts.flash-messages')
        <form method="GET" action="{{ route($plugin->name . '.backups.devices') }}" class="form-inline">
            <label for="backup-device-search" style="margin-left: .5em;">Find a Device</label>
            <input id="backup-device-search" name="search" class="form-control" value="{{ $search }}"
                placeholder="Hostname or device ID">
            <button type="submit" class="btn btn-default">Search</button>
            @if ($search !== '')
                <a class="btn btn-link" href="{{ route($plugin->name . '.backups.devices') }}">Clear Search</a>
            @endif
        </form>

        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th scope="col">Device</th>
                        <th scope="col">Enrollment</th>
                        <th scope="col">Latest Attempt</th>
                        <th scope="col">Run started (UTC)</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($devices as $device)
                        @php($attempt = $attempts->get($device->getKey()))
                        <tr>
                            <td><a href="{{ route('device.overview', ['device' => $device->getKey()]) }}">{{ $device->hostname }}</a></td>
                            <td>{{ $device->disabled ? 'Disabled device' : ($device->backup_enrolled ? 'Enrolled' : 'Not enrolled') }}</td>
                            <td>@include('device-backups::partials.backup-status-badge', ['state' => $attempt?->status])</td>
                            <td>{{ $attempt?->run_started_at ?? '—' }}</td>
                            <td>
                                <a href="{{ route($plugin->name . '.backups.history', ['device' => $device->getKey()]) }}">View Backups</a>
                                <span aria-hidden="true"> · </span>
                                <a href="{{ route($plugin->name . '.backups.ssh.edit', ['device' => $device->getKey()]) }}">SSH Settings</a>
                                @include('device-backups::partials.run-backup', [
                                    'deviceId' => $device->getKey(),
                                    'canStartBackup' => !$device->disabled && $device->backup_enrolled,
                                    'manualRequest' => $pendingRequests->get($device->getKey()),
                                    'returnQuery' => [
                                        'from' => 'devices',
                                        'search' => $search,
                                        'page' => $devices->currentPage(),
                                    ],
                                ])
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">No matching devices.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $devices->links('pagination::simple-bootstrap-3') }}
    @endcomponent
@endsection
