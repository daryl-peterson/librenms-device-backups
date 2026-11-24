@php
    [$badge, $label] = match ($state) {
        'success' => ['success', 'Succeeded'],
        'failed' => ['danger', 'Failed'],
        'queued' => ['warning', 'Queued'],
        'not_sent' => ['default', 'Not sent'],
        'running' => ['info', 'Running'],
        'sent' => ['success', 'Sent'],
        'pending' => ['default', 'Pending'],
        'not_configured' => ['warning', 'Not configured'],
        default => ['default', 'No backups'],
    };
@endphp
<span class="label label-{{ $badge }} tw:inline-block tw:text-sm tw:px-3 tw:py-2">{{ $label }}</span>
