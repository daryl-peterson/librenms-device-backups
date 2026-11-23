<div style="display: flex; align-items: flex-start; margin-bottom: 15px;">
    <img src="{{ $plugin->author_image }}" alt="{{ $plugin->author }}"
        style="width: 110px; height: 110px; border-radius: 50%; margin-right: 15px; flex-shrink: 0;">

    <div>
        <strong>Author</strong> : {{ $plugin->author }}<br>
        <strong>Version</strong> : {{ $plugin->version }}<br>
        <div style="margin-top: 4px;">
            <strong>Buy Me Coffee</strong> :
            <a href="{{ $plugin->coffee_link }}" target="_blank" style="display: block; margin-top: 6px;">
                <img src="{{ $plugin->coffee_image }}" alt="Buy Me A Coffee" height="41" width="174">
            </a>
        </div>
    </div>
</div>
