/**
 * AICommerce Iframe JavaScript
 *
 * Handles:
 * - Modal open/close behavior
 * - Dynamic iframe URL generation
 * - UI customization (position, color)
 * - Accessibility (ESC key, focus handling)
 * - Resource cleanup
 */
(function() {
    'use strict';

    /** Global settings injected externally. */
    const settings = typeof aicommerceIframe !== 'undefined' ? aicommerceIframe : {};

    /** DOM elements. */
    const button = document.getElementById('aicommerce-iframe-button');
    const modal = document.getElementById('aicommerce-iframe-modal');
    const closeButton = document.getElementById('aicommerce-iframe-close');
    const overlay = modal ? modal.querySelector('.aicommerce-iframe-modal-overlay') : null;
    const iframeContainer = document.getElementById('aicommerce-iframe-container');
    const placeholder = document.getElementById('aicommerce-iframe-placeholder');

    /** Abort initialization if required elements are missing. */
    if (!button || !modal || !closeButton) return;

    /** Apply dynamic button position class. */
    if (settings.position && button) {
        const wrapper = button.closest('.aicommerce-iframe-wrapper');
        if (wrapper) {
            /** Remove existing position classes. */
            wrapper.className = wrapper.className.replace(/aicommerce-iframe-position-\S+/g, '');
            /** Add new position class. */
            wrapper.classList.add('aicommerce-iframe-position-' + settings.position);
        }
    }

    /** Apply dynamic button color. */
    if (settings.color && button) {
        /** Set background color dynamically. */
        button.style.backgroundColor = settings.color;
    }

    /**
     * Generate iframe URL dynamically based on:
     * - Customer ID (preferred)
     * - Guest token (fallback)
     *
     * @returns {string} Fully constructed iframe URL or empty string
     */
    function generateIframeUrl() {
        // URL should be provided by the server (`settings.url`) or in the DOM (`data-src`).
        // This function is retained as a safe fallback but avoids exposing API keys.
        const guestToken = typeof getAicommerceGuestToken === 'function' ? getAicommerceGuestToken() : '';
        const customerId = typeof getAicommerceCustomerId === 'function' ? getAicommerceCustomerId() : '';

        // Without a base URL + signature, we can't safely build the iframe URL here.
        // Return empty string so the placeholder is shown instead of guessing.
        if (!guestToken && !customerId) return '';
        return '';
    }

    /**
     * Open modal and initialize iframe.
     *
     * Behavior:
     * - Displays modal
     * - Locks body scroll
     * - Dispatches open event
     * - Loads iframe lazily
     */
    function openModal() {
        if (!modal) return;

        /** Show modal. */
        modal.style.display = 'flex';

        /** Disable background scroll. */
        document.body.style.overflow = 'hidden';

        /** Notify external listeners. */
        window.dispatchEvent(new CustomEvent('aicommerce:popup_opened'));

        if (!iframeContainer) return;

        /** Resolve iframe URL priority. */
        const serverUrl = iframeContainer.getAttribute('data-src') || '';
        const url = serverUrl || settings.url || generateIframeUrl();

        /** Show placeholder if no valid URL. */
        if (!url) {
            if (placeholder) placeholder.style.display = 'block';
            return;
        }

        /** Hide placeholder. */
        if (placeholder) placeholder.style.display = 'none';

        /** Retrieve or create iframe. */
        let iframe = iframeContainer.querySelector('iframe');

        if (!iframe) {
            iframe = document.createElement('iframe');

            /** Configure iframe. */
            iframe.id = 'aicommerce-iframe';
            iframe.setAttribute('frameborder', '0');
            iframe.setAttribute('allowfullscreen', '');

            /** Append iframe. */
            iframeContainer.appendChild(iframe);
        }

        /** Update iframe source only if changed. */
        if (iframe.src !== url) iframe.src = url;

        /** Focus iframe for accessibility. */
        setTimeout(() => {
            try { iframe.focus(); } catch (e) {}
        }, 100);
    }

    /**
     * Close modal and clean up resources.
     *
     * Behavior:
     * - Hides modal
     * - Restores body scroll
     * - Dispatches close event
     * - Removes iframe
     * - Restores focus
     */
    function closeModal() {
        if (!modal) return;

        /** Hide modal. */
        modal.style.display = 'none';

        /** Restore body scroll. */
        document.body.style.overflow = '';

        /** Notify external listeners. */
        window.dispatchEvent(new CustomEvent('aicommerce:popup_closed'));

        /** Remove iframe to release resources. */
        if (iframeContainer) {
            const iframe = iframeContainer.querySelector('iframe');
            if (iframe) iframe.remove();
        }

        /** Restore focus to trigger button. */
        if (button) button.focus();
    }

    /**
     * Handle ESC key to close modal.
     *
     * @param {KeyboardEvent} e
     */
    function handleEscape(e) {
        /** Close modal on Escape key. */
        if (e.key === 'Escape' && modal && modal.style.display !== 'none') {
            closeModal();
        }
    }

    /** Bind open event. */
    if (button) button.addEventListener('click', openModal);

    /** Bind close button event. */
    if (closeButton) closeButton.addEventListener('click', closeModal);

    /** Bind overlay click event. */
    if (overlay) overlay.addEventListener('click', closeModal);

    /** Bind ESC key listener. */
    document.addEventListener('keydown', handleEscape);

    /** Observe modal visibility changes and sync body scroll. */
    if (modal) {
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                /** Detect style changes. */
                if (mutation.type === 'attributes' && mutation.attributeName === 'style') {
                    /** Sync body scroll state. */
                    const isOpen = modal.style.display !== 'none';
                    document.body.style.overflow = isOpen ? 'hidden' : '';
                }
            });
        });

        /** Start observing modal. */
        observer.observe(modal, {
            attributes: true,
            attributeFilter: ['style']
        });
    }

})();
