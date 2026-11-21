
<div style="margin-top:-12px; padding-bottom: 1em;">
    @includeIf("$plugin::layouts.flash-messages")
</div>
@component('device-backups::layouts.panel')
    @includeIf("$plugin::partials.author")
    @includeIf("$plugin::partials.dbstatus")
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
