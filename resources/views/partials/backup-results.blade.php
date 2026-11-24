@php
    $showRun = $showRun ?? true;
    $columns = array_filter([
        'device' => $showDevice ? 'Device' : null,
        'run' => $showRun ? 'Run' : null,
        'started' => 'Run started',
        'profile' => 'Profile',
        'status' => 'Status',
        'details' => 'Details',
        'archive' => 'Archive',
    ]);
@endphp

<div class="table-responsive">
    <table class="table table-striped">
        <thead>
            <tr>
                @foreach ($columns as $column => $label)
                    @php
                        $sortable =
                            isset($sortRoute) && isset(\DRP\DeviceBackups\Backup\BackupStore::RESULT_SORT_COLUMNS[$column])
                            && ($column !== 'archive' || ($sortArchive ?? false));
                        $sorted = $sortable && $sort === $column;
                    @endphp
                    <th scope="col"
                        @if ($sorted) aria-sort="{{ $direction === 'asc' ? 'ascending' : 'descending' }}" @endif>
                        @if ($sortable)
                            @php
                                $nextDirection = $sorted
                                    ? ($direction === 'asc' ? 'desc' : 'asc')
                                    : \DRP\DeviceBackups\Backup\BackupStore::defaultSortDirection($column);
                                $sortUrl = route($sortRoute, array_replace($sortQuery, [
                                    'sort' => $column,
                                    'direction' => $nextDirection,
                                ]));
                            @endphp
                            <a href="{{ $sortUrl }}#backup-attempts">
                                {{ $label }}
                                <i class="fa {{ $sorted ? 'fa-sort-' . $direction : 'fa-sort' }}" aria-hidden="true"></i>
                            </a>
                        @else
                            {{ $label }}
                        @endif
                    </th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($results as $result)
                <tr>
                    @if ($showDevice)
                        <td><a
                                href="{{ route('device-backups.backups.history', ['device' => $result->device_id]) }}">{{ $result->hostname }}</a>
                        </td>
                    @endif
                    @if ($showRun)
                        <td><a
                                href="{{ route('device-backups.backups.status', ['run' => $result->run_id]) }}">#{{ $result->run_id }}</a>
                        </td>
                    @endif
                    <td>{{ \DRP\DeviceBackups\Helper::localTime($result->run_started_at) }}</td>
                    <td>{{ $result->profile ?? '—' }}</td>
                    <td>@include('device-backups::partials.backup-status-badge', ['state' => $result->status])</td>
                    <td>{{ $result->error ?? '—' }}</td>
                    <td>
                        @if ($result->status === 'success' && $result->sha256 && $result->archive_bytes > 0)
                            <a href="{{ route('device-backups.backups.download', ['device' => $result->device_id, 'backup' => $result->id]) }}"
                                class="btn btn-info mr-3" title="Download Backup" aria-label="Download Backup">
                                <i class="fa fa-download" aria-hidden="true"></i>
                            </a>
                        @else
                            —
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($columns) }}">No backup attempts found.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
{{ $results->links('pagination::simple-bootstrap-3') }}
