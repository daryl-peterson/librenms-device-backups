@extends('layouts.librenmsv1')

@section('content')
    @component('device-backups::layouts.panel', ['wide' => true])
        <h3>{{ $hostname }} <small>Device #{{ $deviceId }}</small></h3>
        <p>
            @if ($latest)
                <a class="btn btn-primary" href="{{ route('device-backups.backups.latest', ['device' => $deviceId]) }}">Download
                    Latest Backup</a>
            @endif
            @if ($device)
                <a class="btn btn-default" href="{{ route('device.overview', ['device' => $deviceId]) }}">Device Overview</a>
            @endif
        </p>
        @include('device-backups::partials.backup-results', ['showDevice' => false])
    @endcomponent
@endsection
