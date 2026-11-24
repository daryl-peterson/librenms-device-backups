@extends('layouts.librenmsv1')

@section('content')
    @component($plugin->name . '::layouts.panel', ['wide' => true, 'plugin' => $plugin])
        <h3>Backup Comparison <small>{{ $hostname }}</small></h3>
        <p>
            <a href="{{ route($plugin->name . '.backups.history', ['device' => $deviceId]) }}">Back to Backup History</a>
        </p>
        <dl class="dl-horizontal">
            <dt>Previous</dt>
            <dd>Run #{{ $previous->run_id }} · {{ \DRP\DeviceBackups\Helper::localTime($previous->run_started_at) }}</dd>
            <dt>Latest</dt>
            <dd>Run #{{ $latest->run_id }} · {{ \DRP\DeviceBackups\Helper::localTime($latest->run_started_at) }}</dd>
        </dl>
        @if ($error !== null)
            <p class="alert alert-warning" role="alert">{{ $error }}</p>
        @elseif ($diff === '')
            <p>No configuration changes.</p>
        @else
            <p><code>+</code> Added lines · <code>-</code> Removed lines</p>
            <pre>{{ $diff }}</pre>
        @endif
    @endcomponent
@endsection
