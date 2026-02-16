const swaggerConfig = {
    url: '/wp-json/aicommerce/v1/swagger.json',
    dom_id: '#swagger-ui',
    presets: [
        SwaggerUIBundle.presets.apis,
        SwaggerUIStandalonePreset
    ],
    plugins: [
        SwaggerUIBundle.plugins.DownloadUrl
    ],
    layout: "StandaloneLayout",
    requestInterceptor: function(request) {
        const apiKey = localStorage.getItem('aicommerce_api_key') || '';
        const apiSecret = localStorage.getItem('aicommerce_api_secret') || '';
        
        if (apiKey && apiSecret && request.url.includes('/wp-json/aicommerce/v1/')) {
            const method = (request.method || 'GET').toUpperCase();
            const timestamp = Math.floor(Date.now() / 1000).toString();
            
            const url = new URL(request.url);
            const path = '/wp-json' + url.pathname.replace(/^.*\/wp-json/, '');
            
            let body = '';
            if (request.body) {
                body = typeof request.body === 'string' ? request.body : JSON.stringify(request.body);
            }
            
            if (typeof CryptoJS !== 'undefined') {
                const signatureString = method + path + body + timestamp;
                const signature = CryptoJS.HmacSHA256(signatureString, apiSecret).toString();
                
                request.headers['X-API-Key'] = apiKey;
                request.headers['X-API-Signature'] = signature;
                request.headers['X-Request-Timestamp'] = timestamp;
            }
        }
        
        return request;
    }
};
