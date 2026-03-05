/**
 * Tixomat – FAQ Accordion
 */
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.tix-faq-question').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var item    = btn.closest('.tix-faq-item');
                var answer  = item.querySelector('.tix-faq-answer');
                var isOpen  = btn.getAttribute('aria-expanded') === 'true';

                if (isOpen) {
                    // Close
                    btn.setAttribute('aria-expanded', 'false');
                    answer.style.maxHeight = answer.scrollHeight + 'px';
                    // Force reflow
                    answer.offsetHeight;
                    answer.style.maxHeight = '0';
                    answer.classList.remove('tix-faq-open');
                    item.classList.remove('tix-faq-active');
                    setTimeout(function() {
                        answer.setAttribute('hidden', '');
                    }, 300);
                } else {
                    // Open
                    btn.setAttribute('aria-expanded', 'true');
                    answer.removeAttribute('hidden');
                    answer.classList.add('tix-faq-open');
                    item.classList.add('tix-faq-active');
                    answer.style.maxHeight = answer.scrollHeight + 'px';
                    setTimeout(function() {
                        answer.style.maxHeight = 'none';
                    }, 300);
                }
            });
        });
    });
})();
