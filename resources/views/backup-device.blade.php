@extends('layouts.librenmsv1')

@section('content')
    @component($plugin->name . '::layouts.panel', ['wide' => true, 'plugin' => $plugin])
        <h3>{{ $hostname }} <small>Device #{{ $deviceId }}</small></h3>
        @include($plugin->name . '::layouts.flash-messages')
        <p>
            @if ($latest)
                <a class="btn btn-primary" href="{{ route($plugin->name . '.backups.latest', ['device' => $deviceId]) }}">Download
                    Latest Backup</a>
            @endif
            @if ($device)
                <a class="btn btn-default" href="{{ route('device.overview', ['device' => $deviceId]) }}">Device Overview</a>
                <a class="btn btn-default" href="{{ route($plugin->name . '.backups.ssh.edit', ['device' => $deviceId]) }}">SSH Settings</a>
            @endif
        </p>
        @include($plugin->name . '::partials.run-backup')
        @include($plugin->name . '::partials.backup-results', ['showDevice' => false])
    @endcomponent
@endsection
