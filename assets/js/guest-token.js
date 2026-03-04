/**
 * AICommerce Guest Token Management
 * Creates and stores unique guest token in localStorage and syncs with cookie
 * Also manages customer_id for logged-in users
 */

(function() {
    'use strict';

    const COOKIE_NAME = 'aicommerce_guest_token';
    const STORAGE_KEY = 'aicommerce_guest_token';
    const CUSTOMER_ID_COOKIE_NAME = 'aicommerce_customer_id';
    const CUSTOMER_ID_STORAGE_KEY = 'aicommerce_customer_id';

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

    /**
     * Initialize customer ID
     */
    function initCustomerId() {
        const serverCustomerId = typeof aicommerceGuestToken !== 'undefined' && aicommerceGuestToken.customer_id 
            ? aicommerceGuestToken.customer_id 
            : null;
        let localCustomerId = localStorage.getItem(CUSTOMER_ID_STORAGE_KEY);
        const cookieCustomerId = getCookie(CUSTOMER_ID_COOKIE_NAME);
        
        let finalCustomerId = null;
        
        if (serverCustomerId) {
            finalCustomerId = serverCustomerId;
        } else if (cookieCustomerId) {
            finalCustomerId = cookieCustomerId;
        } else if (localCustomerId) {
            finalCustomerId = localCustomerId;
        }
        
        if (finalCustomerId) {
            localStorage.setItem(CUSTOMER_ID_STORAGE_KEY, finalCustomerId);
            
            if (!cookieCustomerId || cookieCustomerId !== finalCustomerId) {
                setCookie(CUSTOMER_ID_COOKIE_NAME, finalCustomerId, 365);
            }
        } else {
            localStorage.removeItem(CUSTOMER_ID_STORAGE_KEY);
            if (cookieCustomerId) {
                setCookie(CUSTOMER_ID_COOKIE_NAME, '', -1);
            }
        }
        
        window.aicommerceCustomerIdValue = finalCustomerId;
        
        return finalCustomerId;
    }

    /**
     * Initialize guest token
     */
    function initGuestToken() {
        const serverToken = typeof aicommerceGuestToken !== 'undefined' && aicommerceGuestToken.token 
            ? aicommerceGuestToken.token 
            : null;
        
        let localToken = localStorage.getItem(STORAGE_KEY);
        
        const cookieToken = getCookie(COOKIE_NAME);
        
        let finalToken = null;
        
        if (serverToken) {
            finalToken = serverToken;
        } else if (cookieToken) {
            finalToken = cookieToken;
        } else if (localToken) {
            finalToken = localToken;
        } else {
            finalToken = generateToken();
        }
        
        if (finalToken) {
            localStorage.setItem(STORAGE_KEY, finalToken);
            
            if (!cookieToken || cookieToken !== finalToken) {
                setCookie(COOKIE_NAME, finalToken, 365);
            }
            
            if (localToken && localToken !== finalToken && (serverToken || cookieToken)) {
                localStorage.setItem(STORAGE_KEY, finalToken);
            }
        }
        
        window.aicommerceGuestTokenValue = finalToken;
        
        return finalToken;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            initGuestToken();
            initCustomerId();
        });
    } else {
        initGuestToken();
        initCustomerId();
    }

    window.getAicommerceGuestToken = function() {
        return localStorage.getItem(STORAGE_KEY) || window.aicommerceGuestTokenValue || null;
    };

    window.getAicommerceCustomerId = function() {
        return localStorage.getItem(CUSTOMER_ID_STORAGE_KEY) || window.aicommerceCustomerIdValue || null;
    };
})();
