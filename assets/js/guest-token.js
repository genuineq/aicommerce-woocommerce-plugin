/**
 * AICommerce Guest Token Management
 * Cookie-first helpers for guest token + customer id.
 *
 * Goals:
 * - minimal JS work on page load
 * - avoid localStorage sync / double token generation
 */

(function() {
    'use strict';

    const COOKIE_NAME = 'aicommerce_guest_token';
    const CUSTOMER_ID_COOKIE_NAME = 'aicommerce_customer_id';

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
        if (cookieToken) return cookieToken;

        // Fallback only when server didn't set it (e.g. cached HTML). No localStorage.
        const newToken = generateToken();
        setCookie(COOKIE_NAME, newToken, 365);
        return newToken;
    }

    window.getAicommerceGuestToken = function() {
        return getOrCreateGuestToken() || null;
    };

    window.getAicommerceCustomerId = function() {
        return getCookie(CUSTOMER_ID_COOKIE_NAME) || null;
    };
})();
