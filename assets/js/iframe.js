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
    let lockedScrollY = 0;
    let isPageScrollLocked = false;
    let previousScrollStyles = null;

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
     * Generate iframe URL dynamically based on guest token fallback only.
     *
     * @returns {string} Fully constructed iframe URL or empty string
     */
    function generateIframeUrl() {
        // URL should be provided by the server (`settings.url`) or in the DOM (`data-src`).
        // This function is retained as a safe fallback but avoids exposing API keys.
        const guestToken = typeof getAicommerceGuestToken === 'function' ? getAicommerceGuestToken() : '';

        // Without a base URL + signature, we can't safely build the iframe URL here.
        // Return empty string so the placeholder is shown instead of guessing.
        if (!guestToken) return '';
        return '';
    }

    /**
     * Keep the iframe identity aligned with the active storefront guest token.
     *
     * The server renders data-src from the cookie, while guest-token.js can recover
     * a token from localStorage on cached pages. Normalize the iframe URL at open
     * time so the chat app and cart sync API use the same identifier.
     *
     * @param {string} url Iframe URL rendered by PHP.
     * @returns {string} URL with synchronized guest token parameters.
     */
    function syncGuestTokenInIframeUrl(url) {
        if (!url) return url;

        try {
            const parsed = new URL(url, window.location.href);

            if (settings.logged_in && settings.user_id) {
                parsed.searchParams.set('c', String(settings.user_id));
                parsed.searchParams.set('user_id', String(settings.user_id));
                if (settings.cart_token) {
                    parsed.searchParams.set('cart_token', String(settings.cart_token));
                    parsed.searchParams.set('t', String(settings.cart_token));
                }
                parsed.searchParams.delete('g');
                parsed.searchParams.delete('guest_token');
                return parsed.toString();
            }

            if (parsed.searchParams.get('c') || parsed.searchParams.get('user_id')) {
                return parsed.toString();
            }

            if (typeof getAicommerceGuestToken !== 'function') return url;

            const guestToken = getAicommerceGuestToken();
            if (!guestToken) return url;

            parsed.searchParams.set('g', guestToken);
            parsed.searchParams.set('guest_token', guestToken);

            return parsed.toString();
        } catch (e) {
            return url;
        }
    }

    /**
     * Lock the storefront scroll while the iframe modal is open.
     */
    function lockPageScroll() {
        if (isPageScrollLocked) return;

        lockedScrollY = window.scrollY || document.documentElement.scrollTop || 0;
        isPageScrollLocked = true;
        previousScrollStyles = {
            htmlOverflow: document.documentElement.style.overflow,
            bodyOverflow: document.body.style.overflow,
            bodyPosition: document.body.style.position,
            bodyTop: document.body.style.top,
            bodyLeft: document.body.style.left,
            bodyRight: document.body.style.right,
            bodyWidth: document.body.style.width
        };

        document.documentElement.style.overflow = 'hidden';
        document.body.style.overflow = 'hidden';
        document.body.style.position = 'fixed';
        document.body.style.top = '-' + lockedScrollY + 'px';
        document.body.style.left = '0';
        document.body.style.right = '0';
        document.body.style.width = '100%';
    }

    /**
     * Restore the storefront scroll state after the iframe modal closes.
     */
    function unlockPageScroll() {
        if (!isPageScrollLocked) return;

        isPageScrollLocked = false;

        document.documentElement.style.overflow = previousScrollStyles ? previousScrollStyles.htmlOverflow : '';
        document.body.style.overflow = previousScrollStyles ? previousScrollStyles.bodyOverflow : '';
        document.body.style.position = previousScrollStyles ? previousScrollStyles.bodyPosition : '';
        document.body.style.top = previousScrollStyles ? previousScrollStyles.bodyTop : '';
        document.body.style.left = previousScrollStyles ? previousScrollStyles.bodyLeft : '';
        document.body.style.right = previousScrollStyles ? previousScrollStyles.bodyRight : '';
        document.body.style.width = previousScrollStyles ? previousScrollStyles.bodyWidth : '';
        previousScrollStyles = null;

        window.scrollTo(0, lockedScrollY);
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
        lockPageScroll();

        /** Notify external listeners. */
        window.dispatchEvent(new CustomEvent('aicommerce:popup_opened'));

        if (!iframeContainer) return;

        /** Resolve iframe URL priority. */
        const serverUrl = iframeContainer.getAttribute('data-src') || '';
        const url = syncGuestTokenInIframeUrl(serverUrl || settings.url || generateIframeUrl());

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
        unlockPageScroll();

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
                    if (isOpen) {
                        lockPageScroll();
                    } else {
                        unlockPageScroll();
                    }
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
