{{-- Tab strip across the top of every Woocommerce screen, matching the live
     module's layout. Built from the project's own .nav-tabs-custom component
     so it inherits the theme in both light and dark mode.

     No tab carries .active: the live strip renders them all flat, and the
     Bootstrap active state painted the first tab as a raised white panel that
     did not match. $active_tab is still passed by the controller in case a
     current-tab indicator is wanted later. --}}
<div class="nav-tabs-custom wc-tabs tw-mb-4">
    <ul class="nav nav-tabs">
        {{-- .wc-tab-brand reads as a static brand label: no active state and
             no hover tint. It stays a link so the other two tabs have a way
             back to this screen. --}}
        <li class="wc-tab-brand">
            <a href="{{ action([\App\Http\Controllers\WoocommerceController::class, 'index']) }}">
                <i class="fab fa-wordpress" aria-hidden="true"></i> Woocommerce
            </a>
        </li>
        <li class="{{ $active_tab === 'sync_log' ? 'active' : '' }}">
            <a href="{{ action([\App\Http\Controllers\WoocommerceController::class, 'syncLog']) }}">
                Sync Log
            </a>
        </li>
        <li class="{{ $active_tab === 'api_settings' ? 'active' : '' }}">
            <a href="{{ action([\App\Http\Controllers\WoocommerceController::class, 'apiSettings']) }}">
                API Settings
            </a>
        </li>
    </ul>
</div>
