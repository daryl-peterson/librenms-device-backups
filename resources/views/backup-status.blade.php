@extends('layouts.librenmsv1')

@section('content')
    @component('device-backups::layouts.panel', ['wide' => true])
        <fieldset style="margin: .5em;">
            <legend style="border: 0px; font-size: 1.1em; font-weight: 600; margin-bottom: .5em;">Backup Runs
            </legend>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th scope="col">Run</th>
                            <th scope="col">Started (UTC)</th>
                            <th scope="col">Finished (UTC)</th>
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
                                        href="{{ route('device-backups.backups.status', ['run' => $entry->id, 'status' => $status]) }}">#{{ $entry->id }}</a>
                                </td>
                                <td>{{ $entry->started_at }}</td>
                                <td>{{ $entry->finished_at ?? '—' }}</td>
                                <td>@include('device-backups::partials.backup-status-badge', [
                                    'state' => $entry->status,
                                ])</td>
                                <td>{{ $entry->succeeded }}</td>
                                <td>{{ $entry->failed }}</td>
                                <td>@include('device-backups::partials.backup-status-badge', [
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
        <fieldset style="margin: .5em;">
            <legend style="border: 0px; font-size: 1.1em; font-weight: 600; margin-bottom: .5em;">
                {{ $run ? "Backup Attempts for Run #$run" : 'Backup Attempts' }}
            </legend>


            <form method="GET" action="{{ route('device-backups.backups.status') }}" class="form-inline">
                @if ($run)
                    <input type="hidden" name="run" value="{{ $run }}">
                @endif
                <label for="backup-status-filter" style="margin-left: .5em;">Status</label>
                <select id="backup-status-filter" name="status" class="form-control">
                    <option value="">All</option>
                    <option value="success" @selected($status === 'success')>Succeeded</option>
                    <option value="failed" @selected($status === 'failed')>Failed</option>
                </select>
                <button class="btn btn-default" type="submit">Filter</button>
                @if ($run || $status)
                    <a class="btn btn-link" href="{{ route('device-backups.backups.status') }}">Clear Filters</a>
                @endif
            </form>
            @include('device-backups::partials.backup-results', ['showDevice' => true])
        </fieldset>
    @endcomponent
@endsection
