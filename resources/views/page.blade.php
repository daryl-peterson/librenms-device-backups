<div style="margin-top:-12px; padding-bottom: 1em;">
    @includeIf($plugin->name . '::layouts.flash-messages')
</div>
@component($plugin->name . '::layouts.panel', ['plugin' => $plugin])
    @includeIf($plugin->name . '::partials.author')
    @includeIf($plugin->name . '::partials.dbstatus')
@endcomponent


@push('scripts')
    <script>
        $(document).ready(function() {
            console.log("LibreNMS custom script running!");
            $(".alert").delay(5000).fadeOut(500, function() {
                $(this).alert('close');
            });
        });
    </script>
@endpush
