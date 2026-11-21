@if ($check)
    <span class="label {{ $check['status'] === 'connected' ? 'label-success' : 'label-danger' }}">
        {{ \DRP\DeviceBackups\Backup\DeviceSshStatus::LABELS[$check['status']] }}
    </span>
@else
    <span class="label label-default">{{ $enrolled ? 'Not tested' : 'Not enrolled' }}</span>
@endif
