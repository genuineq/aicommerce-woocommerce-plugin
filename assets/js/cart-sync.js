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

    /** External configuration (if provided). */
    const _cfg = (typeof aicommerceCartSyncConfig !== 'undefined' && aicommerceCartSyncConfig)
        ? aicommerceCartSyncConfig
        : {};

    /** Configured user ID (optional). */
    const _configUserId = _cfg.user_id ? _cfg.user_id : null;

    /** Whether to auto-sync on page load. */
    const _autoSyncOnLoad = !!_cfg.auto_sync_on_load;

    /**
     * Tracks if user has interacted (used to delay heavy sync logic).
     */
    let hasUserInteracted = false;

    /** Retrieve guest token if available. */
    function getGuestToken() {
        return typeof getAicommerceGuestToken === 'function' ? getAicommerceGuestToken() : null;
    }

    /** Check if we have any cart identifier. */
    function hasCartIdentifier() {
        return !!(getGuestToken() || _configUserId);
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
        } else if (_configUserId) {
            url += '?user_id=' + encodeURIComponent(_configUserId);
        } else {
            return null;
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

        const guestToken = getGuestToken();

        /** Abort if no identifier exists. */
        if (!guestToken && !_configUserId) return;

        isSyncing = true;

        try {
            /** Build request body. */
            const body = {};

            if (guestToken) body.guest_token = guestToken;
            else if (_configUserId) body.user_id = _configUserId;

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
                    jQuery(document.body).trigger('wc_fragment_refresh');
                    jQuery(document.body).trigger('update_checkout');
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
     * Start polling cart hash every 5 seconds.
     *
     * - Captures initial hash
     * - Syncs only when hash changes
     */
    async function startPolling() {
        if (pollTimer) return;

        hasUserInteracted = true;

        /** Capture baseline hash. */
        lastHash = await fetchCartHash();

        pollTimer = setInterval(async () => {

            /** Skip when tab is hidden. */
            if (document.hidden) return;

            const hash = await fetchCartHash();

            /** Sync only if hash changed. */
            if (hash && hash !== lastHash) {
                lastHash = hash;
                await syncCartToWCSession();
            }

        }, 5000);
    }

    /** Stop polling mechanism. */
    function stopPolling() {
        if (!pollTimer) return;

        clearInterval(pollTimer);
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

        /** Optional initial sync (e.g. cart/checkout pages). */
        if (_autoSyncOnLoad && hasCartIdentifier()) {
            syncCartToWCSession();
        }

        /** Handle popup open → start polling. */
        window.addEventListener('aicommerce:popup_opened', () => {
            hasUserInteracted = true;

            /** Ensure WC session is synced before polling starts. */
            if (hasCartIdentifier()) {
                syncCartToWCSession().finally(startPolling);
            } else {
                startPolling();
            }
        });

        /** Handle popup close → stop polling. */
        window.addEventListener('aicommerce:popup_closed', stopPolling);

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
