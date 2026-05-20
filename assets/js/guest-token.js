/**
 * AICommerce Guest Token Management
 * Cookie-first helpers for guest token.
 *
 * Goals:
 * - minimal JS work on page load
 * - keep the same token across tabs when a cached page misses the cookie
 */

(function() {
    'use strict';

    const COOKIE_NAME = 'aicommerce_guest_token';
    const STORAGE_KEY = 'aicommerce_guest_token';
    const cfg = (typeof aicommerceGuestTokenConfig !== 'undefined' && aicommerceGuestTokenConfig)
        ? aicommerceGuestTokenConfig
        : {};

    function isValidToken(token) {
        return /^guest_\d+_[a-zA-Z0-9]+_[a-f0-9]{8}$/.test(String(token || ''));
    }
    /**
     * Get cookie value by name
     */
    function getCookie(name) {
        const value = `; ${document.cookie}`;
        const parts = value.split(`; ${name}=`);
        if (parts.length === 2) {
            return parts.pop().split(';').shift();
        }
        return null;
    }

    /**
     * Set cookie
     */
    function setCookie(name, value, days) {
        const expires = new Date();
        expires.setTime(expires.getTime() + (days * 24 * 60 * 60 * 1000));
        const secure = window.location.protocol === 'https:' ? ';secure' : '';
        document.cookie = `${name}=${value};expires=${expires.toUTCString()};path=/;SameSite=Lax${secure}`;
    }

    function getStoredToken() {
        try {
            const token = window.localStorage ? window.localStorage.getItem(STORAGE_KEY) : null;
            return isValidToken(token) ? token : null;
        } catch (e) {
            return null;
        }
    }

    function storeToken(token) {
        if (!isValidToken(token)) return;

        try {
            if (window.localStorage) {
                window.localStorage.setItem(STORAGE_KEY, token);
            }
        } catch (e) {
            // Some browsers block storage; the cookie remains the source of truth.
        }
    }

    function persistToken(token) {
        if (!isValidToken(token)) return null;

        setCookie(COOKIE_NAME, token, 365);
        storeToken(token);
        return token;
    }

    function getUrlToken() {
        try {
            const token = new URLSearchParams(window.location.search).get('guest_token');
            return isValidToken(token) ? token : null;
        } catch (e) {
            return null;
        }
    }

    /**
     * Generate unique token
     */
    function generateToken() {
        const timestamp = Date.now();
        const random = Math.random().toString(36).substring(2, 18);
        const siteHash = window.location.hostname.split('').reduce((acc, char) => {
            return acc + char.charCodeAt(0);
        }, '').substring(0, 8);
        
        return `guest_${timestamp}_${random}_${siteHash}`;
    }

    function getOrCreateGuestToken() {
        const cookieToken = getCookie(COOKIE_NAME);
        const storedToken = getStoredToken();
        const urlToken = getUrlToken();

        if (urlToken) {
            return persistToken(urlToken);
        }

        if (isValidToken(cookieToken)) {
            storeToken(cookieToken);
            return cookieToken;
        }

        if (storedToken) {
            setCookie(COOKIE_NAME, storedToken, 365);
            return storedToken;
        }

        if (isValidToken(cfg.token)) {
            return persistToken(cfg.token);
        }

        // Fallback only when server didn't set it (e.g. cached HTML).
        const newToken = generateToken();
        return persistToken(newToken);
    }

    window.getAicommerceGuestToken = function() {
        return getOrCreateGuestToken() || null;
    };

    getOrCreateGuestToken();

})();
