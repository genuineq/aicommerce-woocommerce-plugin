/**
 * AICommerce visit tracking token.
 *
 * Maintains a short-lived visit_id cookie while the visitor remains active.
 */

(function() {
    'use strict';

    const COOKIE_NAME = 'visit_id';
    const NEW_SESSION_STORAGE_KEY = 'aicommerce_new_session_visit_id';
    const COOKIE_MAX_AGE_SECONDS = 3 * 60;
    const REFRESH_INTERVAL_MS = 60 * 1000;
    const ACTIVITY_THROTTLE_MS = 60 * 1000;
    const cfg = (typeof aicommerceTrackingTokenConfig !== 'undefined' && aicommerceTrackingTokenConfig)
        ? aicommerceTrackingTokenConfig
        : {};

    function isValidVisitId(visitId) {
        return /^\d+_[a-zA-Z0-9]+_[a-f0-9]{8}$/.test(String(visitId || ''));
    }

    function getCookie(name) {
        const value = `; ${document.cookie}`;
        const parts = value.split(`; ${name}=`);

        if (parts.length === 2) {
            return parts.pop().split(';').shift();
        }

        return null;
    }

    function setCookie(name, value, maxAgeSeconds) {
        const secure = window.location.protocol === 'https:' ? ';secure' : '';
        const expires = new Date();
        expires.setTime(expires.getTime() + (maxAgeSeconds * 1000));

        document.cookie = `${name}=${value};Max-Age=${maxAgeSeconds};expires=${expires.toUTCString()};path=/;SameSite=Lax${secure}`;
    }

    function generateVisitId() {
        const timestamp = Date.now();
        const random = Math.random().toString(36).substring(2, 18);
        const siteHash = window.location.hostname.split('').reduce((acc, char) => {
            return acc + char.charCodeAt(0);
        }, '').substring(0, 8);

        return `${timestamp}_${random}_${siteHash}`;
    }

    function getOrCreateVisitId() {
        const cookieVisitId = getCookie(COOKIE_NAME);
        const visitId = isValidVisitId(cookieVisitId) ? cookieVisitId : generateVisitId();

        setCookie(COOKIE_NAME, visitId, COOKIE_MAX_AGE_SECONDS);

        return visitId;
    }

    function refreshVisitId() {
        getOrCreateVisitId();
    }

    function bindVisitRefresh() {
        let lastRefresh = 0;

        const refreshOnActivity = function() {
            const now = Date.now();

            if ((now - lastRefresh) < ACTIVITY_THROTTLE_MS) {
                return;
            }

            lastRefresh = now;
            refreshVisitId();
        };

        window.setInterval(refreshVisitId, REFRESH_INTERVAL_MS);

        ['click', 'keydown', 'mousemove', 'scroll', 'touchstart'].forEach(function(eventName) {
            window.addEventListener(eventName, refreshOnActivity, { passive: true });
        });

        document.addEventListener('visibilitychange', function() {
            if (!document.hidden) {
                refreshVisitId();
            }
        });
    }

    function getDeviceType() {
        const isMobile = window.matchMedia
            ? window.matchMedia('(max-width: 767px)').matches
            : window.innerWidth <= 767;

        return isMobile ? 'mobile' : 'desktop';
    }

    function getGuestToken() {
        return typeof window.getAicommerceGuestToken === 'function'
            ? window.getAicommerceGuestToken()
            : '';
    }

    function addIdentityToPayload(payload) {
        if (cfg.logged_in && cfg.user_id && cfg.cart_token) {
            payload.user_id = Number(cfg.user_id);
            payload.cart_token = String(cfg.cart_token);
            return payload;
        }

        const guestToken = getGuestToken();

        if (guestToken) {
            payload.guest_token = guestToken;
        }

        return payload;
    }

    function buildEventPayload() {
        return addIdentityToPayload({
            session_id: getOrCreateVisitId(),
            context: {
                user_agent: window.navigator ? window.navigator.userAgent : '',
                page_url: window.location.href,
                device_type: getDeviceType()
            }
        });
    }

    function buildHeaders() {
        const headers = {
            'Accept': 'application/json',
            'Content-Type': 'application/json'
        };

        if (cfg.nonce) {
            headers['X-AICommerce-Tracking-Nonce'] = String(cfg.nonce);
        }

        return headers;
    }

    function sendEvent(eventName) {
        const endpoint = cfg.endpoints && cfg.endpoints[eventName] ? cfg.endpoints[eventName] : '';

        if (!endpoint) {
            return;
        }

        const body = JSON.stringify(buildEventPayload());

        if (typeof window.fetch === 'function') {
            window.fetch(endpoint, {
                method: 'POST',
                headers: buildHeaders(),
                body: body,
                keepalive: true,
                credentials: 'same-origin'
            }).catch(function() {});

            return;
        }

        if (window.navigator && typeof window.navigator.sendBeacon === 'function') {
            const blob = new Blob([body], { type: 'application/json' });

            if (window.navigator.sendBeacon(endpoint, blob)) {
                return;
            }
        }
    }

    function getStoredNewSessionVisitId() {
        try {
            return window.localStorage ? window.localStorage.getItem(NEW_SESSION_STORAGE_KEY) : '';
        } catch (e) {
            return '';
        }
    }

    function storeNewSessionVisitId(visitId) {
        try {
            if (window.localStorage) {
                window.localStorage.setItem(NEW_SESSION_STORAGE_KEY, visitId);
            }
        } catch (e) {}
    }

    function trackNewSession() {
        const visitId = getOrCreateVisitId();

        if (getStoredNewSessionVisitId() === visitId) {
            return;
        }

        storeNewSessionVisitId(visitId);
        sendEvent('new_session');
    }

    function trackChatOpened() {
        refreshVisitId();
        sendEvent('chat_opened');
    }

    window.getAicommerceVisitId = function() {
        return getOrCreateVisitId() || null;
    };

    window.trackAicommerceUsageEvent = sendEvent;

    refreshVisitId();
    bindVisitRefresh();
    trackNewSession();
    window.addEventListener('aicommerce:popup_opened', trackChatOpened);

})();
