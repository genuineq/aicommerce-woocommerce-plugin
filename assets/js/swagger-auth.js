(function() {
    'use strict';

    let apiKey = localStorage.getItem('aicommerce_api_key') || '';
    let apiSecret = localStorage.getItem('aicommerce_api_secret') || '';

    // Generate signature function
    function generateSignature(method, path, body, timestamp, secret) {
        if (!secret) {
            console.error('API Secret not set');
            return '';
        }
        
        const signatureString = method + path + body + timestamp;
        
        if (typeof CryptoJS !== 'undefined') {
            return CryptoJS.HmacSHA256(signatureString, secret).toString();
        } else {
            console.error('CryptoJS not found. Please include CryptoJS library.');
            return '';
        }
    }

    // Intercept fetch requests
    const originalFetch = window.fetch;
    window.fetch = function(url, options = {}) {
        if (url.includes('/wp-json/aicommerce/v1/') && apiKey && apiSecret) {
            const method = (options.method || 'GET').toUpperCase();
            const timestamp = Math.floor(Date.now() / 1000).toString();
            
            const urlObj = new URL(url);
            const path = '/wp-json' + urlObj.pathname.replace(/^.*\/wp-json/, '');
            
            let body = '';
            if (options.body) {
                body = typeof options.body === 'string' ? options.body : JSON.stringify(options.body);
            }
            
            const signature = generateSignature(method, path, body, timestamp, apiSecret);
            
            if (!options.headers) {
                options.headers = {};
            }
            
            options.headers['X-API-Key'] = apiKey;
            options.headers['X-API-Signature'] = signature;
            options.headers['X-Request-Timestamp'] = timestamp;
            
            if ((method === 'POST' || method === 'PUT') && body && !options.headers['Content-Type']) {
                options.headers['Content-Type'] = 'application/json';
            }
        }
        
        return originalFetch.apply(this, arguments);
    };

    // Intercept XMLHttpRequest (for older Swagger UI versions)
    const originalOpen = XMLHttpRequest.prototype.open;
    const originalSend = XMLHttpRequest.prototype.send;
    
    XMLHttpRequest.prototype.open = function(method, url, ...args) {
        this._method = method;
        this._url = url;
        return originalOpen.apply(this, [method, url, ...args]);
    };
    
    XMLHttpRequest.prototype.send = function(body) {
        if (this._url && this._url.includes('/wp-json/aicommerce/v1/') && apiKey && apiSecret) {
            const method = (this._method || 'GET').toUpperCase();
            const timestamp = Math.floor(Date.now() / 1000).toString();
            
            const urlObj = new URL(this._url, window.location.origin);
            const path = '/wp-json' + urlObj.pathname.replace(/^.*\/wp-json/, '');
            
            const bodyString = body ? (typeof body === 'string' ? body : JSON.stringify(body)) : '';
            const signature = generateSignature(method, path, bodyString, timestamp, apiSecret);
            
            this.setRequestHeader('X-API-Key', apiKey);
            this.setRequestHeader('X-API-Signature', signature);
            this.setRequestHeader('X-Request-Timestamp', timestamp);
        }
        
        return originalSend.apply(this, arguments);
    };

    // Function to set credentials
    window.setAicommerceCredentials = function(key, secret) {
        apiKey = key || '';
        apiSecret = secret || '';
        
        if (apiKey) {
            localStorage.setItem('aicommerce_api_key', apiKey);
        } else {
            localStorage.removeItem('aicommerce_api_key');
        }
        
        if (apiSecret) {
            localStorage.setItem('aicommerce_api_secret', apiSecret);
        } else {
            localStorage.removeItem('aicommerce_api_secret');
        }
        
        console.log('AICommerce credentials', apiKey ? 'set' : 'cleared');
    };
    
    // Load credentials on initialization
    apiKey = localStorage.getItem('aicommerce_api_key') || '';
    apiSecret = localStorage.getItem('aicommerce_api_secret') || '';

    console.log('AICommerce Swagger Auth Helper loaded');
})();
