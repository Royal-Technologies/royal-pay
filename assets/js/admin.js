/**
 * Royal Pay Admin JavaScript
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        // Copy pairing token or API URL on click
        $('.royalpay-copy-btn').on('click', function(e) {
            e.preventDefault();
            var target = $(this).data('target');
            var text = $(target).text().trim();

            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(function() {
                    alert('Copied to clipboard!');
                });
            }
        });
    });
})(jQuery);
