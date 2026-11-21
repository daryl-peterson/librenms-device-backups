@php
    $menuRoute = \DRP\DeviceBackups\PluginDb::isReady($info['settings'])
        && \Illuminate\Support\Facades\Route::has('device-backups.backups.status')
        ? 'device-backups.backups.status'
        : 'device-backups.settings';
@endphp
@if (\Illuminate\Support\Facades\Route::has($menuRoute))
    <a href="{{ route($menuRoute) }}">
        <i class="fa fa-floppy-o fa-lg" aria-hidden="true"></i> {{ $info['title'] }}
    </a>
@endif
