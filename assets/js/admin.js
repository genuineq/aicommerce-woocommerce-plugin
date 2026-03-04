/**
 * AICommerce Admin JavaScript
 */

(function() {
    'use strict';

    function initPositionSelector() {
        const selector = document.getElementById('aicommerce-position-selector');
        const hiddenInput = document.getElementById('aicommerce_iframe_position');
        
        if (!selector || !hiddenInput) {
            return;
        }

        const cells = selector.querySelectorAll('.aicommerce-position-cell');
        const currentPosition = hiddenInput.value;

        cells.forEach(function(cell) {
            const position = cell.getAttribute('data-position');
            if (position === currentPosition) {
                cell.classList.add('active');
            }

            cell.addEventListener('click', function() {
                const position = this.getAttribute('data-position');
                
                cells.forEach(function(c) {
                    c.classList.remove('active');
                });
                
                this.classList.add('active');
                
                hiddenInput.value = position;
            });
        });
    }

    function initColorPicker() {
        const colorInput = document.getElementById('aicommerce_iframe_button_color');
        const colorCircle = document.querySelector('.aicommerce-color-picker-circle');
        
        if (!colorInput || !colorCircle) {
            return;
        }

        colorInput.addEventListener('change', function() {
            colorCircle.style.backgroundColor = this.value;
        });

        colorInput.addEventListener('input', function() {
            colorCircle.style.backgroundColor = this.value;
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            initPositionSelector();
            initColorPicker();
        });
    } else {
        initPositionSelector();
        initColorPicker();
    }
})();
