@extends('layouts.librenmsv1')

@section('content')
    @php
        $timezone = session('preferences.timezone') ?: config('app.timezone');
        $formatLocalTime = static fn (?string $timestamp): string => $timestamp === null
            ? '—'
            : \Carbon\CarbonImmutable::parse($timestamp, 'UTC')->setTimezone($timezone)->format('Y-m-d H:i:s');
        $attemptQuery = [
            'run' => $run,
            'status' => $status,
            'search' => $search !== '' ? $search : null,
            'sort' => $sort,
            'direction' => $direction,
            'runs_page' => $runs->currentPage() > 1 ? $runs->currentPage() : null,
        ];
        $attemptSortUrl = static fn (string $column): string => route('device-backups.backups.status', array_replace(
            $attemptQuery,
            [
                'sort' => $column,
                'direction' => $column === $sort
                    ? ($direction === 'asc' ? 'desc' : 'asc')
                    : ($column === 'started' ? 'desc' : 'asc'),
            ],
        )) . '#backup-attempts';
    @endphp

    @component($plugin->name . '::layouts.panel', ['wide' => true, 'plugin' => $plugin])
        <fieldset style="margin: .5em;">
            <legend style="border: 0px; font-size: 1.1em; font-weight: 600; margin-bottom: .5em;">Backup Runs
            </legend>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th scope="col">Run</th>
                            <th scope="col">Started (local time)</th>
                            <th scope="col">Finished (local time)</th>
                            <th scope="col">Status</th>
                            <th scope="col">Succeeded</th>
                            <th scope="col">Failed</th>
                            <th scope="col">Report Email</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($runs as $entry)
                            <tr>
                                <td><a
                                        href="{{ route('device-backups.backups.status', array_replace($attemptQuery, ['run' => $entry->id])) }}#backup-attempts">#{{ $entry->id }}</a>
                                    @if ($entry->requested_device_id)
                                        <small>
                                            Manual · <a
                                                href="{{ route('device-backups.backups.history', ['device' => $entry->requested_device_id]) }}">Device
                                                #{{ $entry->requested_device_id }}</a>
                                        </small>
                                    @endif
                                </td>
                                <td>{{ $entry->status === 'queued' ? '—' : $formatLocalTime($entry->started_at) }}</td>
                                <td>{{ $formatLocalTime($entry->finished_at) }}</td>
                                <td>@include($plugin->name . '::partials.backup-status-badge', [
                                    'state' => $entry->status,
                                ])</td>
                                <td>{{ $entry->succeeded }}</td>
                                <td>{{ $entry->failed }}</td>
                                <td>@include($plugin->name . '::partials.backup-status-badge', [
                                    'state' => $entry->email_status,
                                ])</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7">No backup runs yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $runs->links('pagination::simple-bootstrap-3') }}
        </fieldset>
        <fieldset id="backup-attempts" style="margin: .5em;">
            <legend style="border: 0px; font-size: 1.1em; font-weight: 600; margin-bottom: .5em;">
                {{ $run ? "Backup Attempts for Run #$run" : 'Backup Attempts' }}
            </legend>


            @if ($selectedRun?->requested_at)
                <p>
                    Requested {{ $formatLocalTime($selectedRun->requested_at) }} local time.
                    @include($plugin->name . '::partials.backup-status-badge', ['state' => $selectedRun->status])
                </p>
                @if ($selectedRun->request_error)
                    <p class="alert alert-danger">{{ $selectedRun->request_error }}</p>
                @endif
            @endif

            <form method="GET" action="{{ route('device-backups.backups.status') }}#backup-attempts" class="form-inline">
                @if ($run)
                    <input type="hidden" name="run" value="{{ $run }}">
                @endif
                @if ($attemptQuery['runs_page'])
                    <input type="hidden" name="runs_page" value="{{ $attemptQuery['runs_page'] }}">
                @endif
                <input type="hidden" name="sort" value="{{ $sort }}">
                <input type="hidden" name="direction" value="{{ $direction }}">
                <label for="backup-attempts-search" style="margin-left: .5em;">Search</label>
                <input type="search" id="backup-attempts-search" name="search" class="form-control"
                    value="{{ $search }}" placeholder="Device, profile, or details" maxlength="128">
                <label for="backup-status-filter" style="margin-left: .5em;">Status</label>
                <select id="backup-status-filter" name="status" class="form-control">
                    <option value="">All</option>
                    <option value="success" @selected($status === 'success')>Succeeded</option>
                    <option value="failed" @selected($status === 'failed')>Failed</option>
                </select>
                <button class="btn btn-default" type="submit">Filter</button>
                @if ($run || $status || $search !== '')
                    <a class="btn btn-link"
                        href="{{ route('device-backups.backups.status', array_replace($attemptQuery, ['run' => null, 'status' => null, 'search' => null])) }}#backup-attempts">Clear Filters</a>
                @endif
            </form>
            @include($plugin->name . '::partials.backup-results', [
                'showDevice' => true,
                'showRun' => false,
                'formatRunStarted' => $formatLocalTime,
                'attemptSortUrl' => $attemptSortUrl,
                'attemptSort' => $sort,
                'attemptDirection' => $direction,
            ])
        </fieldset>
    @endcomponent
@endsection
