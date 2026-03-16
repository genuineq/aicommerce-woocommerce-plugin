/**
 * AICommerce Cart Synchronization
 * Syncs guest/user cart with WooCommerce session.
 * Polls /cart/hash while AI popup is open; one-time sync on page load, focus, visibility.
 */

(function() {
    'use strict';

    const API_BASE = '/wp-json/aicommerce/v1';
    let isSyncing  = false;
    let pollTimer  = null;
    let lastHash   = null;

    const _configUserId = (typeof aicommerceCartSyncConfig !== 'undefined' && aicommerceCartSyncConfig.user_id)
        ? aicommerceCartSyncConfig.user_id
        : null;

    function getGuestToken() {
        return typeof getAicommerceGuestToken === 'function' ? getAicommerceGuestToken() : null;
    }

    function hasCartIdentifier() {
        return !!(getGuestToken() || _configUserId);
    }

    /**
     * Lightweight hash check — no writes, < 10ms server-side.
     * Returns null on error.
     */
    async function fetchCartHash() {
        const guestToken = getGuestToken();
        let url = API_BASE + '/cart/hash';
        if (guestToken) {
            url += '?guest_token=' + encodeURIComponent(guestToken);
        } else if (_configUserId) {
            url += '?user_id=' + encodeURIComponent(_configUserId);
        } else {
            return null;
        }

        try {
            const res  = await fetch(url, { credentials: 'same-origin' });
            const data = await res.json();
            return data.hash || null;
        } catch (e) {
            return null;
        }
    }

    /**
     * Sync cart to WooCommerce session and refresh mini-cart fragments.
     */
    async function syncCartToWCSession() {
        if (isSyncing) return;

        const guestToken = getGuestToken();
        if (!guestToken && !_configUserId) return;

        isSyncing = true;

        try {
            const body = {};
            if (guestToken)    body.guest_token = guestToken;
            else if (_configUserId) body.user_id = _configUserId;

            const res  = await fetch(API_BASE + '/cart/sync', {
                method:      'POST',
                headers:     { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body:        JSON.stringify(body),
            });
            const data = await res.json();

            if (data && data.success) {
                if (typeof jQuery !== 'undefined') {
                    jQuery(document.body).trigger('wc_fragment_refresh');
                    jQuery(document.body).trigger('update_checkout');
                }
                window.dispatchEvent(new CustomEvent('aicommerce_cart_synced', { detail: data }));
            }
        } catch (e) {
            // silent
        } finally {
            isSyncing = false;
        }
    }

    /**
     * Start polling /cart/hash every 5s.
     * Records initial hash on first call; syncs only when hash changes.
     */
    async function startPolling() {
        if (pollTimer) return;

        // Capture baseline hash so first interval only fires on real change
        lastHash = await fetchCartHash();

        pollTimer = setInterval(async () => {
            if (document.hidden) return;
            const hash = await fetchCartHash();
            if (hash && hash !== lastHash) {
                lastHash = hash;
                await syncCartToWCSession();
            }
        }, 5000);
    }

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function init() {
        // One-time sync on page load
        if (hasCartIdentifier()) {
            syncCartToWCSession();
        }

        // Start/stop polling with popup lifecycle
        window.addEventListener('aicommerce:popup_opened', startPolling);
        window.addEventListener('aicommerce:popup_closed', stopPolling);

        // One-time sync when user returns to tab or window
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden && hasCartIdentifier()) {
                syncCartToWCSession();
            }
        });

        window.addEventListener('focus', () => {
            if (hasCartIdentifier()) {
                syncCartToWCSession();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.aicommerceCartSync = {
        sync:         syncCartToWCSession,
        startPolling: startPolling,
        stopPolling:  stopPolling,
    };

})();
