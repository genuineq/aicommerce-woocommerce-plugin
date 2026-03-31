/**
 * AICommerce Cart Synchronization
 *
 * Syncs guest/user cart with WooCommerce session.
 * - Polls /cart/hash while popup is open
 * - One-time sync on load, focus, visibility change
 */
(function() {
    'use strict';

    /** Base API endpoint. */
    const API_BASE = '/wp-json/aicommerce/v1';

    /** Internal state flags. */
    let isSyncing = false;
    let pollTimer = null;
    let lastHash  = null;
    let eventSource = null;

    /** External configuration (if provided). */
    const _cfg = (typeof aicommerceCartSyncConfig !== 'undefined' && aicommerceCartSyncConfig)
        ? aicommerceCartSyncConfig
        : {};

    /** Whether to auto-sync on page load. */
    const _autoSyncOnLoad = !!_cfg.auto_sync_on_load;

    /**
     * Tracks if user has interacted (used to delay heavy sync logic).
     */
    let hasUserInteracted = false;

    /** Debounce / cooldown for sync calls (ms). */
    const SYNC_COOLDOWN_MS = 3500;
    let lastSyncAt = 0;

    /** Polling backoff configuration. */
    const POLL_FAST_MS = 5000;   // first minute
    const POLL_SLOW_MS = 12000;  // after first minute
    const POLL_FAST_WINDOW_MS = 60000;

    /** Stop polling after inactivity inside the popup (ms). */
    const INACTIVITY_STOP_MS = 120000; // 2 minutes
    let lastActivityAt = Date.now();
    let pollingStartedAt = 0;
    let popupOpen = false;

    /** Retrieve guest token if available. */
    function getGuestToken() {
        return typeof getAicommerceGuestToken === 'function' ? getAicommerceGuestToken() : null;
    }

    function isLoggedIn() {
        // WordPress adds `logged-in` to body class for authenticated users.
        return !!(document.body && document.body.classList && document.body.classList.contains('logged-in'));
    }

    /** Check if we have any cart identifier. */
    function hasCartIdentifier() {
        // Guests require a guest token; logged-in users can be resolved server-side.
        return !!(getGuestToken() || isLoggedIn());
    }

    function isCheckoutPage() {
        if (document.body && document.body.classList.contains('woocommerce-checkout')) return true;
        return !!document.querySelector('form.checkout');
    }

    function shouldRefreshFragments() {
        // Trigger fragments refresh only if a mini-cart / fragments UI is present.
        return !!(
            document.querySelector('.widget_shopping_cart') ||
            document.querySelector('.woocommerce-mini-cart') ||
            document.querySelector('.wc-block-mini-cart') ||
            document.querySelector('.site-header-cart') ||
            document.querySelector('.cart-contents')
        );
    }

    function canSyncNow() {
        const now = Date.now();
        if (now - lastSyncAt < SYNC_COOLDOWN_MS) return false;
        lastSyncAt = now;
        return true;
    }

    function markActivity() {
        lastActivityAt = Date.now();
    }

    /**
     * Lightweight cart hash fetch (read-only).
     *
     * - No writes
     * - Fast (<10ms server-side)
     *
     * @returns {Promise<string|null>}
     */
    async function fetchCartHash() {
        const guestToken = getGuestToken();

        /** Build request URL. */
        let url = API_BASE + '/cart/hash';

        if (guestToken) {
            url += '?guest_token=' + encodeURIComponent(guestToken);
        } else {
            // Logged-in user hash can be resolved by session; no query params needed.
            if (!isLoggedIn()) return null;
        }

        try {
            /** Execute request. */
            const res  = await fetch(url, { credentials: 'same-origin' });
            const data = await res.json();

            /** Return hash if available. */
            return data.hash || null;
        } catch (e) {
            return null;
        }
    }

    /**
     * Sync cart to WooCommerce session and refresh UI fragments.
     */
    async function syncCartToWCSession() {
        if (isSyncing) return;
        if (!canSyncNow()) return;

        const guestToken = getGuestToken();
        if (!guestToken && !isLoggedIn()) return;

        isSyncing = true;

        try {
            /** Build request body. */
            const body = {};

            if (guestToken) body.guest_token = guestToken;

            /** Send sync request. */
            const res  = await fetch(API_BASE + '/cart/sync', {
                method:      'POST',
                headers:     { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body:        JSON.stringify(body),
            });

            const data = await res.json();

            /** Handle successful sync. */
            if (data && data.success) {

                /** Refresh WooCommerce fragments if jQuery is available. */
                if (typeof jQuery !== 'undefined') {
                    if (shouldRefreshFragments()) {
                        jQuery(document.body).trigger('wc_fragment_refresh');
                    }
                    if (isCheckoutPage()) {
                        jQuery(document.body).trigger('update_checkout');
                    }
                }

                /** Dispatch global event. */
                window.dispatchEvent(new CustomEvent('aicommerce_cart_synced', { detail: data }));
            }
        } catch (e) {
            /** Silent failure. */
        } finally {
            isSyncing = false;
        }
    }

    /**
     * Start polling cart hash with adaptive backoff.
     *
     * - Captures initial hash
     * - Syncs only when hash changes
     */
    async function startPolling() {
        if (pollTimer) return;
        if (!hasCartIdentifier()) return;
        pollingStartedAt = Date.now();
        hasUserInteracted = true;
        markActivity();

        /** Capture baseline hash. */
        lastHash = await fetchCartHash();

        const tick = async () => {
            if (!pollTimer) return;

            /** Stop polling when tab is hidden. */
            if (document.hidden) {
                pollTimer = setTimeout(tick, POLL_SLOW_MS);
                return;
            }

            /** Stop polling after inactivity while popup is open. */
            if (popupOpen && Date.now() - lastActivityAt > INACTIVITY_STOP_MS) {
                stopPolling();
                return;
            }

            const hash = await fetchCartHash();

            /** Sync only if hash changed. */
            if (hash && hash !== lastHash) {
                lastHash = hash;
                await syncCartToWCSession();
            }

            const elapsed = Date.now() - pollingStartedAt;
            const delay = elapsed < POLL_FAST_WINDOW_MS ? POLL_FAST_MS : POLL_SLOW_MS;
            pollTimer = setTimeout(tick, delay);
        };

        pollTimer = setTimeout(tick, POLL_FAST_MS);
    }

    /**
     * Listen to SSE cart updates and sync immediately.
     *
     * This makes remove operations feel instant as well (polling-only can miss
     * the right moment when the widget/popup lifecycle changes).
     */
    function startSseCartUpdates() {
        if (eventSource) return;
        if (!window.EventSource) return;
        if (!hasCartIdentifier()) return;

        const guestToken = getGuestToken();
        if (!guestToken) return;

        const url = API_BASE + '/sse/cart?guest_token=' + encodeURIComponent(guestToken);
        try {
            eventSource = new EventSource(url);
        } catch (e) {
            return;
        }

        eventSource.addEventListener('cart_updated', () => {
            // syncCartToWCSession already has cooldown + in-flight guard.
            syncCartToWCSession();
        });

        eventSource.addEventListener('error', () => {
            // On any SSE error, keep polling mechanism as fallback.
            try {
                if (eventSource) {
                    eventSource.close();
                }
            } catch (e) {}
            eventSource = null;
        });
    }

    /** Stop polling mechanism. */
    function stopPolling() {
        if (!pollTimer) return;

        clearTimeout(pollTimer);
        pollTimer = null;
    }

    /**
     * Initialize cart sync behavior.
     *
     * Handles:
     * - Initial sync (optional)
     * - Popup lifecycle events
     * - Tab visibility + focus sync
     */
    function init() {
        // Track activity while popup is open (used to stop polling after inactivity).
        const modal = document.getElementById('aicommerce-iframe-modal');
        if (modal) {
            const activityEvents = ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll'];
            activityEvents.forEach((evt) => modal.addEventListener(evt, markActivity, { passive: true }));
        }

        /** Optional initial sync (e.g. cart/checkout pages). */
        if (_autoSyncOnLoad && hasCartIdentifier()) {
            syncCartToWCSession();
        }

        // Keep WooCommerce fragments in sync with guest cart updates
        // triggered by server-side cart operations.
        startSseCartUpdates();

        /** Handle popup open → start polling. */
        window.addEventListener('aicommerce:popup_opened', () => {
            hasUserInteracted = true;
            popupOpen = true;
            markActivity();

            /** Ensure WC session is synced before polling starts. */
            if (hasCartIdentifier()) {
                syncCartToWCSession().finally(startPolling);
            } else {
                // No identifier: avoid useless polling / sync calls.
            }
        });

        /** Handle popup close → stop polling. */
        window.addEventListener('aicommerce:popup_closed', () => {
            popupOpen = false;
            stopPolling();
        });

        /** Sync when returning to visible tab. */
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden && hasUserInteracted && hasCartIdentifier()) {
                syncCartToWCSession();
            }
        });

        /** Sync on window focus. */
        window.addEventListener('focus', () => {
            if (hasUserInteracted && hasCartIdentifier()) {
                syncCartToWCSession();
            }
        });
    }

    /** Initialize when DOM is ready. */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    /**
     * Public API exposure.
     */
    window.aicommerceCartSync = {
        sync:         syncCartToWCSession,
        startPolling: startPolling,
        stopPolling:  stopPolling,
    };

})();
