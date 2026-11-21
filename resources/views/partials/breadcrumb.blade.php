@php
    $currentRouteName = request()->route()->getName();
@endphp


<div class="tw:flex tw:justify-between">
    <div class="tw:min-h-8">
        <div class="tw:inline-block tw:p-1">
            <div class="tw:inline-block tw:p-1" style="border-bottom: 0">
                <span style="font-weight: bold">{{ $info['title'] }}</span> »

                @if ($currentRouteName === 'plugin.page')
                    <span class="pagemenu-selected" style="margin-right: 4px">
                @endif

                @if ($currentRouteName !== 'plugin.page')
                    <span>
                @endif

                <a href="{{ route('plugin.page', $plugin) }}" class="sync-filter-url">Plugin</a>
                </span>

                @can('access-admin')
                    |

                    @if ($currentRouteName === "$plugin.settings")
                        <span class="pagemenu-selected" style="margin-right: 4px">
                    @endif

                    @if ($currentRouteName !== "$plugin.settings")
                        <span>
                    @endif

                    <a href="{{ route("$plugin.settings") }}" class="sync-filter-url">Settings</a>
                    </span>
                @endcan
            </div>
        </div>
    </div>
</div>
