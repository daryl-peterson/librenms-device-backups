@extends('layouts.librenmsv1')

@section('content')
    @component('device-backups::layouts.panel', ['wide' => true])
        <h3>Device SSH Status</h3>
        @include('device-backups::layouts.flash-messages')

        <form method="GET" action="{{ route('device-backups.backups.ssh.index') }}" class="form-inline">
            <label for="backup-ssh-search">Find a Device</label>
            <input id="backup-ssh-search" name="search" class="form-control" value="{{ $search }}"
                placeholder="Hostname or device ID">
            <button type="submit" class="btn btn-default">Search</button>
            @if ($search !== '')
                <a class="btn btn-link" href="{{ route('device-backups.backups.ssh.index') }}">Clear Search</a>
            @endif
        </form>

        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th scope="col">Device</th>
                        <th scope="col">Last SSH Result</th>
                        <th scope="col">Last Checked (UTC)</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($devices as $device)
                        @php
                            $enrolled = \DRP\DeviceBackups\Backup\Target::revision($device) !== null;
                            $check = \DRP\DeviceBackups\Backup\DeviceSshStatus::get($device);
                        @endphp
                        <tr>
                            <td>
                                <a href="{{ route('device.overview', ['device' => $device->getKey()]) }}">{{ $device->hostname }}</a>
                                @if ($device->disabled)
                                    <span class="text-muted">(Disabled)</span>
                                @endif
                            </td>
                            <td>@include('device-backups::partials.ssh-state')</td>
                            <td>{{ $check['checked_at'] ?? '—' }}</td>
                            <td>
                                <a class="btn btn-default btn-sm" href="{{ route('device-backups.backups.ssh.edit', ['device' => $device->getKey()]) }}">
                                    {{ $enrolled ? 'Edit SSH' : 'Configure SSH' }}
                                </a>
                                @if ($enrolled)
                                    <form method="POST" class="form-inline tw:inline-block"
                                        action="{{ route('device-backups.backups.ssh.check', ['device' => $device->getKey()]) }}">
                                        @csrf
                                        <input type="hidden" name="from" value="status">
                                        <input type="hidden" name="search" value="{{ $search }}">
                                        <input type="hidden" name="page" value="{{ $devices->currentPage() }}">
                                        <button type="submit" class="btn btn-default btn-sm">Test SSH</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4">No matching devices.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $devices->links('pagination::simple-bootstrap-3') }}
    @endcomponent
@endsection
