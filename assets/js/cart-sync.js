/**
 * AICommerce Cart Synchronization
 * Syncs guest cart with WooCommerce session and listens for real-time updates via SSE
 */

(function() {
    'use strict';

    const API_BASE = '/wp-json/aicommerce/v1';
    let sseConnection = null;
    let isSyncing = false;
    let lastSyncAt = 0;
    let reconnectTimeout = null;

    const _configUserId = (typeof aicommerceCartSyncConfig !== 'undefined' && aicommerceCartSyncConfig.user_id)
        ? aicommerceCartSyncConfig.user_id
        : null;
    const _enableSSE = !!(typeof aicommerceCartSyncConfig !== 'undefined' && aicommerceCartSyncConfig.enable_sse);
    const _syncCooldownMs = (typeof aicommerceCartSyncConfig !== 'undefined' && Number.isFinite(Number(aicommerceCartSyncConfig.sync_cooldown_ms)))
        ? Number(aicommerceCartSyncConfig.sync_cooldown_ms)
        : 5000;

    /**
     * Check if a cart identifier (guest token or user ID) is available
     */
    function hasCartIdentifier() {
        if (getGuestToken()) {
            return true;
        }
        if (_configUserId) {
            return true;
        }
        return false;
    }

    /**
     * Get guest token
     */
    function getGuestToken() {
        if (typeof getAicommerceGuestToken === 'function') {
            return getAicommerceGuestToken();
        }
        return null;
    }

    /**
     * Sync cart to WooCommerce session
     * Supports both guest_token and user_id
     */
    async function syncCartToWCSession(force = false) {
        if (isSyncing) {
            return;
        }

        const guestToken = getGuestToken();
        const userId = _configUserId;

        if (!guestToken && !userId) {
            return;
        }

        const now = Date.now();
        if (!force && now - lastSyncAt < _syncCooldownMs) {
            return;
        }

        isSyncing = true;
        lastSyncAt = now;

        try {
            const syncData = {};
            if (guestToken) {
                syncData.guest_token = guestToken;
            } else if (userId) {
                syncData.user_id = userId;
            }

            const response = await fetch(API_BASE + '/cart/sync', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'same-origin',
                body: JSON.stringify(syncData),
            });

            const data = await response.json();

            if (data && data.success) {
                if (data.synced && typeof jQuery !== 'undefined' && jQuery.fn.trigger) {
                    jQuery(document.body).trigger('wc_fragment_refresh');
                    jQuery(document.body).trigger('update_checkout');
                }

                window.dispatchEvent(new CustomEvent('aicommerce_cart_synced', {
                    detail: data
                }));

                console.log('AICommerce: Cart synced to WC session', data);
            }
        } catch (error) {
            console.error('AICommerce: Error syncing cart', error);
        } finally {
            isSyncing = false;
        }
    }

    /**
     * Handle cart event from SSE
     */
    function handleCartEvent(event) {
        if (!event || !event.data) {
            return;
        }

        try {
            const eventData = JSON.parse(event.data);
            console.log('AICommerce: Cart event received', eventData);

            window.dispatchEvent(new CustomEvent('aicommerce_cart_event', {
                detail: eventData
            }));

            switch (event.type) {
                case 'cart_updated':
                    if (eventData.action === 'item_added') {
                        syncCartToWCSession(true);
                        updateCartUI(eventData);
                    }
                    break;
                case 'ping':
                    break;
                case 'connected':
                    console.log('AICommerce: SSE connected', eventData);
                    syncCartToWCSession();
                    break;
                case 'error':
                case 'timeout':
                    console.error('AICommerce: SSE error/timeout', eventData);
                    scheduleReconnect();
                    break;
            }
        } catch (error) {
            console.error('AICommerce: Error parsing SSE event', error);
        }
    }

    /**
     * Update cart UI
     */
    function updateCartUI(eventData) {
        if (!eventData) {
            return;
        }

        const cartCount = eventData.cart_count;
        if (cartCount !== undefined) {
            const cartCountSelectors = [
                '.cart-contents-count',
                '.wc-cart-count',
                '[data-cart-count]',
                '.shopping-cart .count',
            ];

            cartCountSelectors.forEach(selector => {
                const elements = document.querySelectorAll(selector);
                elements.forEach(el => {
                    el.textContent = cartCount;
                    el.setAttribute('data-cart-count', cartCount);
                });
            });

            if (typeof jQuery !== 'undefined' && jQuery.fn.trigger) {
                jQuery(document.body).trigger('wc_fragment_refresh');
            }
        }

        window.dispatchEvent(new CustomEvent('aicommerce_cart_ui_updated', {
            detail: eventData
        }));
    }

    function scheduleReconnect() {
        if (!_enableSSE) {
            return;
        }
        if (reconnectTimeout) {
            return;
        }
        reconnectTimeout = setTimeout(() => {
            reconnectTimeout = null;
            connectSSE();
        }, 5000);
    }

    /**
     * Connect to SSE endpoint
     */
    function connectSSE() {
        if (!_enableSSE) {
            return;
        }
        const guestToken = getGuestToken();
        if (!guestToken) {
            console.warn('AICommerce: Guest token not available for SSE');
            return;
        }

        if (sseConnection) {
            sseConnection.close();
            sseConnection = null;
        }

        const sseUrl = API_BASE + '/sse/cart?guest_token=' + encodeURIComponent(guestToken);

        try {
            sseConnection = new EventSource(sseUrl);

            sseConnection.onopen = function() {
                console.log('AICommerce: SSE connection opened');
            };

            sseConnection.onerror = function(error) {
                console.error('AICommerce: SSE connection error', error);
                
                scheduleReconnect();
            };

            sseConnection.addEventListener('cart_updated', handleCartEvent);
            sseConnection.addEventListener('ping', handleCartEvent);
            sseConnection.addEventListener('connected', handleCartEvent);
            sseConnection.addEventListener('error', handleCartEvent);
            sseConnection.addEventListener('timeout', handleCartEvent);

        } catch (error) {
            console.error('AICommerce: Failed to create SSE connection', error);
        }
    }

    /**
     * Disconnect SSE
     */
    function disconnectSSE() {
        if (reconnectTimeout) {
            clearTimeout(reconnectTimeout);
            reconnectTimeout = null;
        }
        if (sseConnection) {
            sseConnection.close();
            sseConnection = null;
            console.log('AICommerce: SSE connection closed');
        }
    }

    /**
     * Initialize cart sync
     */
    function init() {
        const checkIdentifier = setInterval(() => {
            if (hasCartIdentifier()) {
                clearInterval(checkIdentifier);
                
                syncCartToWCSession(true).then(() => {
                    if (_enableSSE) {
                        const guestToken = getGuestToken();
                        if (guestToken) {
                            connectSSE();
                        }
                    }
                });
            }
        }, 100);

        setTimeout(() => {
            clearInterval(checkIdentifier);
            if (hasCartIdentifier() && !isSyncing) {
                syncCartToWCSession();
            }
        }, 2000);

        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                disconnectSSE();
            } else {
                if (hasCartIdentifier()) {
                    const guestToken = getGuestToken();
                    if (_enableSSE && guestToken) {
                        connectSSE();
                    }
                    syncCartToWCSession(true);
                }
            }
        });

        window.addEventListener('focus', () => {
            if (hasCartIdentifier()) {
                syncCartToWCSession();
            }
        });

        window.addEventListener('beforeunload', () => {
            disconnectSSE();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.aicommerceCartSync = {
        sync: syncCartToWCSession,
        connect: connectSSE,
        disconnect: disconnectSSE,
    };

})();
