/**
 * AICommerce Iframe JavaScript
 */

(function() {
    'use strict';

    const settings = typeof aicommerceIframe !== 'undefined' ? aicommerceIframe : {};

    // Elements
    const button = document.getElementById('aicommerce-iframe-button');
    const modal = document.getElementById('aicommerce-iframe-modal');
    const closeButton = document.getElementById('aicommerce-iframe-close');
    const overlay = modal ? modal.querySelector('.aicommerce-iframe-modal-overlay') : null;
    const iframe = document.getElementById('aicommerce-iframe');

    if (!button || !modal || !closeButton) {
        return;
    }

    if (settings.position && button) {
        const wrapper = button.closest('.aicommerce-iframe-wrapper');
        if (wrapper) {
            wrapper.className = wrapper.className.replace(/aicommerce-iframe-position-\S+/g, '');
            wrapper.classList.add('aicommerce-iframe-position-' + settings.position);
        }
    }

    if (settings.color && button) {
        button.style.backgroundColor = settings.color;
    }

    /**
     * Generate iframe URL dynamically
     */
    function generateIframeUrl() {
        const params = [];

        const apiKey = settings.api_key || '';

        /** Set iframe base url for staging / production. */
        const baseUrl = apiKey.startsWith('staging#') ? 'https://client.ai.staging.genuineq.com' : 'https://client.ai.genuineq.com';

        const guestToken = typeof getAicommerceGuestToken === 'function'
            ? getAicommerceGuestToken()
            : '';

        const customerId = typeof getAicommerceCustomerId === 'function'
            ? getAicommerceCustomerId()
            : '';

        if (apiKey) {
            params.push('s=' + encodeURIComponent(apiKey));
        }

        if (guestToken) {
            params.push('g=' + encodeURIComponent(guestToken));
        }

        if (customerId) {
            params.push('c=' + encodeURIComponent(customerId));
        }

        if (params.length === 0) {
            return '';
        }

        return baseUrl + '?' + params.join('&');
    }

    /**
     * Open modal
     */
    function openModal() {
        if (modal) {
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
            window.dispatchEvent(new CustomEvent('aicommerce:popup_opened'));

            if (iframe) {
                const url = settings.url || generateIframeUrl();

                if (url) {
                    if (iframe.src !== url) {
                        iframe.src = url;
                    }

                    iframe.style.display = 'block';
                    const placeholder = modal.querySelector('.aicommerce-iframe-placeholder');
                    if (placeholder) {
                        placeholder.style.display = 'none';
                    }
                } else {
                    iframe.style.display = 'none';
                    const placeholder = modal.querySelector('.aicommerce-iframe-placeholder');
                    if (placeholder) {
                        placeholder.style.display = 'block';
                    }
                }

                setTimeout(() => {
                    iframe.focus();
                }, 100);
            }
        }
    }

    /**
     * Close modal
     */
    function closeModal() {
        if (modal) {
            modal.style.display = 'none';
            document.body.style.overflow = '';
            window.dispatchEvent(new CustomEvent('aicommerce:popup_closed'));

            if (button) {
                button.focus();
            }
        }
    }

    /**
     * Handle escape key
     */
    function handleEscape(e) {
        if (e.key === 'Escape' && modal && modal.style.display !== 'none') {
            closeModal();
        }
    }

    if (button) {
        button.addEventListener('click', openModal);
    }

    if (closeButton) {
        closeButton.addEventListener('click', closeModal);
    }

    if (overlay) {
        overlay.addEventListener('click', closeModal);
    }

    document.addEventListener('keydown', handleEscape);

    if (modal) {
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.type === 'attributes' && mutation.attributeName === 'style') {
                    const isOpen = modal.style.display !== 'none';
                    document.body.style.overflow = isOpen ? 'hidden' : '';
                }
            });
        });

        observer.observe(modal, {
            attributes: true,
            attributeFilter: ['style']
        });
    }
})();
