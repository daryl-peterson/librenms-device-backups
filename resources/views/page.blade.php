<div style="margin-top:-12px; padding-bottom: 1em;">
    @includeIf($info['name'] . '::layouts.flash-messages')
</div>
@component('device-backups::layouts.panel')
    @includeIf($info['name'] . '::partials.author')
    @includeIf($info['name'] . '::partials.dbstatus')
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
