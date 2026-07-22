/**
 * AICommerce explicit cart bridge sync.
 *
 * Sync points:
 * - cart / checkout page load as a safety net
 * - WooCommerce add / remove / quantity update events notify the iframe only
 * - iframe popup open
 * - iframe popup close
 * - iframe cart mutation messages
 * - global cart update events
 * - window focus / visibility resume
 * - explicit manual calls through window.aicommerceCartSync.sync()
 */
(function() {
    'use strict';

    const API_BASE = '/wp-json/aicommerce/v1';
    const SYNC_COOLDOWN_MS = 3500;
    const RETRY_DELAY_MS = 1500;
    const MAX_RETRIES = 1;

    let isSyncing = false;
    let lastSyncAt = 0;
    let lastResumeSyncAt = 0;

    const cfg = (typeof aicommerceCartSyncConfig !== 'undefined' && aicommerceCartSyncConfig)
        ? aicommerceCartSyncConfig
        : {};

    const autoSyncOnLoad = !!cfg.auto_sync_on_load;

    function getGuestToken() {
        return typeof getAicommerceGuestToken === 'function' ? getAicommerceGuestToken() : null;
    }

    function isLoggedIn() {
        if (typeof cfg.logged_in !== 'undefined') {
            return !!cfg.logged_in;
        }

        return !!(document.body && document.body.classList && document.body.classList.contains('logged-in'));
    }

    function hasCartIdentifier() {
        return !!(getGuestToken() || isLoggedIn());
    }

    function addIdentityToBody(body) {
        const loggedIn = isLoggedIn();

        if (loggedIn && cfg.user_id && cfg.cart_token) {
            body.user_id = Number(cfg.user_id);
            body.cart_token = cfg.cart_token;
            return body;
        }

        const guestToken = getGuestToken();
        if (guestToken && !loggedIn) {
            body.guest_token = guestToken;
        }

        return body;
    }

    function isCheckoutPage() {
        if (document.body && document.body.classList.contains('woocommerce-checkout')) return true;
        return !!document.querySelector('form.checkout');
    }

    function shouldRefreshFragments() {
        return !!(
            document.querySelector('.widget_shopping_cart') ||
            document.querySelector('.woocommerce-mini-cart') ||
            document.querySelector('.wc-block-mini-cart') ||
            document.querySelector('.site-header-cart') ||
            document.querySelector('.cart-contents')
        );
    }

    function getIframe() {
        const container = document.getElementById('aicommerce-iframe-container');
        return container ? container.querySelector('iframe') : null;
    }

    function getIframeTargetOrigin(iframe) {
        if (!iframe) return '*';

        try {
            const currentOrigin = iframe.contentWindow && iframe.contentWindow.location
                ? iframe.contentWindow.location.origin
                : '';

            if (currentOrigin && currentOrigin !== 'null') {
                return currentOrigin;
            }
        } catch (e) {
            // Cross-origin iframe: fall back to the declared src origin.
        }

        if (!iframe.src) return '*';

        try {
            return new URL(iframe.src).origin;
        } catch (e) {
            return '*';
        }
    }

    function notifyIframeCartChanged(detail) {
        const iframe = getIframe();
        if (!iframe || !iframe.contentWindow) return;

        const message = {
            type: 'aicommerce:cart_changed',
            source: 'woocommerce',
            detail: detail || {},
        };

        const targetOrigin = getIframeTargetOrigin(iframe);

        try {
            iframe.contentWindow.postMessage(message, targetOrigin);
        } catch (e) {
            if (targetOrigin !== window.location.origin) return;

            iframe.contentWindow.postMessage(message, window.location.origin);
        }
    }

    function canSyncNow() {
        const now = Date.now();
        if (now - lastSyncAt < SYNC_COOLDOWN_MS) return false;
        lastSyncAt = now;
        return true;
    }

    async function syncCartToWCSession(options) {
        const syncOptions = options || {};
        const bypassCooldown = !!syncOptions.bypassCooldown;
        const retryCount = Number(syncOptions.retryCount || 0);

        if (isSyncing) return;
        if (!bypassCooldown && !canSyncNow()) return;

        const loggedIn = isLoggedIn();
        if (!hasCartIdentifier()) return;

        isSyncing = true;

        try {
            const body = addIdentityToBody({});
            const headers = { 'Content-Type': 'application/json' };

            if (loggedIn && cfg.nonce) {
                headers['X-WP-Nonce'] = cfg.nonce;
            }

            const res = await fetch(API_BASE + '/cart/sync', {
                method: 'POST',
                headers: headers,
                credentials: 'same-origin',
                body: JSON.stringify(body),
            });

            const data = await res.json();

            if (data && data.success) {
                if (typeof jQuery !== 'undefined') {
                    if (shouldRefreshFragments()) {
                        jQuery(document.body).trigger('wc_fragment_refresh');
                    }

                    if (isCheckoutPage()) {
                        jQuery(document.body).trigger('update_checkout');
                    }
                }

                window.dispatchEvent(new CustomEvent('aicommerce_cart_synced', { detail: data }));
                notifyIframeCartChanged({
                    reason: 'bridge_sync',
                    result: data,
                });
                return;
            }

            throw new Error('Cart sync request was not successful.');
        } catch (e) {
            if (retryCount < MAX_RETRIES) {
                window.setTimeout(() => {
                    syncCartToWCSession({
                        bypassCooldown: true,
                        retryCount: retryCount + 1,
                    });
                }, RETRY_DELAY_MS);
            }
        } finally {
            isSyncing = false;
        }
    }

    function notifyWooEventToIframe(eventName) {
        notifyIframeCartChanged({
            reason: eventName,
        });
    }

    function bindWooCartEvents() {
        const events = [
            'added_to_cart',
            'removed_from_cart',
            'updated_cart_totals',
            'updated_wc_div',
            'wc-blocks_added_to_cart',
        ];

        if (typeof jQuery !== 'undefined') {
            jQuery(document.body).on(events.join(' '), function(event) {
                notifyWooEventToIframe(event.type);
            });
        }

        events.forEach(function(eventName) {
            document.body.addEventListener(eventName, function() {
                notifyWooEventToIframe(eventName);
            });
        });
    }

    function parseMessageData(rawData) {
        if (typeof rawData !== 'string') return rawData || {};

        try {
            return JSON.parse(rawData);
        } catch (e) {
            return { type: rawData };
        }
    }

    function flattenMessageValues(value, values) {
        if (value === null || typeof value === 'undefined') return;

        if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') {
            values.push(String(value).toLowerCase());
            return;
        }

        if (Array.isArray(value)) {
            value.forEach(function(item) {
                flattenMessageValues(item, values);
            });
            return;
        }

        if (typeof value === 'object') {
            Object.keys(value).forEach(function(key) {
                values.push(String(key).toLowerCase());
                flattenMessageValues(value[key], values);
            });
        }
    }

    function isCartMutationMessage(rawData) {
        const data = parseMessageData(rawData);
        const values = [];
        const cartChangeTypes = [
            'aicommerce:cart_changed',
            'aicommerce:cart_added',
            'aicommerce:cart_add',
            'aicommerce:cart_removed',
            'aicommerce:cart_remove',
            'aicommerce:cart_item_removed',
            'aicommerce:cart_quantity_changed',
            'aicommerce:cart_quantity_increased',
            'aicommerce:cart_quantity_decreased',
            'aicommerce:cart_updated',
            'aicommerce_cart_changed',
            'aicommerce_cart_added',
            'aicommerce_cart_add',
            'aicommerce_cart_removed',
            'aicommerce_cart_remove',
            'aicommerce_cart_item_removed',
            'aicommerce_cart_quantity_changed',
            'aicommerce_cart_quantity_increased',
            'aicommerce_cart_quantity_decreased',
            'aicommerce_cart_updated',
            'cart_changed',
            'cart_added',
            'cart_add',
            'cart_removed',
            'cart_remove',
            'cart_item_removed',
            'cart_quantity_changed',
            'cart_quantity_increased',
            'cart_quantity_decreased',
            'cart_updated',
            'cart:changed',
            'cart:added',
            'cart:add',
            'cart:removed',
            'cart:remove',
            'cart:item_removed',
            'cart:quantity_changed',
            'cart:quantity_increased',
            'cart:quantity_decreased',
            'cart:updated',
            'add_to_cart',
            'remove_from_cart',
            'removed_from_cart',
            'update_cart',
            'updated_cart',
            'quantity_changed',
            'increase_quantity',
            'decrease_quantity',
        ];

        flattenMessageValues(data, values);

        if (values.some(function(value) {
            return cartChangeTypes.indexOf(value) !== -1;
        })) {
            return true;
        }

        const joined = values.join(' ');
        const hasCart = joined.indexOf('cart') !== -1 || joined.indexOf('cos') !== -1;
        const hasMutation = [
            'add',
            'added',
            'remove',
            'removed',
            'delete',
            'deleted',
            'update',
            'updated',
            'quantity',
            'qty',
            'increase',
            'decrease',
            'increment',
            'decrement',
        ].some(function(keyword) {
            return joined.indexOf(keyword) !== -1;
        });

        return hasCart && hasMutation;
    }

    function bindIframeCartMessages() {
        window.addEventListener('message', function(event) {
            const iframe = getIframe();
            if (!iframe) return;

            const targetOrigin = getIframeTargetOrigin(iframe);
            if (targetOrigin !== '*' && event.origin !== targetOrigin) return;

            if (!isCartMutationMessage(event.data)) return;

            syncCartToWCSession({
                bypassCooldown: true,
                reason: 'iframe_message',
            });
        });
    }

    function handleLocalCartUpdate(reason) {
        if (!hasCartIdentifier()) return;

        syncCartToWCSession({
            bypassCooldown: true,
            reason: reason || 'cart_updated',
        });
    }

    function bindGlobalCartUpdateEvents() {
        const events = [
            'aicommerce:cart_updated',
            'aicommerce:cart_changed',
            'aicommerce:cart_added',
            'aicommerce:cart_removed',
            'aicommerce:cart_quantity_changed',
        ];

        events.forEach(function(eventName) {
            window.addEventListener(eventName, function() {
                handleLocalCartUpdate(eventName);
            });

            document.addEventListener(eventName, function() {
                handleLocalCartUpdate(eventName);
            });
        });
    }

    function bindResumeEvents() {
        function syncOnResume(reason) {
            if (!hasCartIdentifier()) return;

            const now = Date.now();
            if (now - lastResumeSyncAt < 750) return;
            lastResumeSyncAt = now;

            syncCartToWCSession({
                bypassCooldown: true,
                reason: reason,
            });
        }

        window.addEventListener('focus', function() {
            syncOnResume('window_focus');
        });

        window.addEventListener('pageshow', function() {
            syncOnResume('page_show');
        });

        document.addEventListener('visibilitychange', function() {
            if (document.hidden) return;
            syncOnResume('visibility_resume');
        });
    }

    function init() {
        if (autoSyncOnLoad && hasCartIdentifier()) {
            syncCartToWCSession();
        }

        bindWooCartEvents();
        bindIframeCartMessages();
        bindGlobalCartUpdateEvents();
        bindResumeEvents();

        /**
         * Keep the storefront cart aligned when the chat popup is opened.
         *
         * This is useful when the chat updated the persistent cart before the
         * browser reached a WooCommerce page where the safety-net import runs.
         */
        window.addEventListener('aicommerce:popup_opened', function() {
            if (!hasCartIdentifier()) return;
            syncCartToWCSession({
                bypassCooldown: true,
                reason: 'popup_opened',
            });
        });

        /**
         * Re-apply sync once the popup closes so any last chat-side cart
         * mutation is reflected in Woo fragments and checkout UI.
         */
        window.addEventListener('aicommerce:popup_closed', function() {
            if (!hasCartIdentifier()) return;
            syncCartToWCSession({
                bypassCooldown: true,
                reason: 'popup_closed',
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.aicommerceCartSync = {
        sync: syncCartToWCSession,
        notifyCartUpdated: handleLocalCartUpdate,
    };
})();
