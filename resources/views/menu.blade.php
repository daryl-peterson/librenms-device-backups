@if ($plugin->hasMenuRoute)
    <a href="{{ route($plugin->menu) }}">
        <i class="fa fa-floppy-o fa-lg" aria-hidden="true"></i> {{ $plugin->title }}
    </a>
@endif
