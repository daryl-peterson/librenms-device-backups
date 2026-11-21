@if (\Illuminate\Support\Facades\Route::has('device-backups.backups.status'))
    <a href="{{ route('device-backups.backups.status') }}">
        <i class="fa fa-floppy-o fa-lg" aria-hidden="true"></i> {{ $info['title'] }}
    </a>
@endif
