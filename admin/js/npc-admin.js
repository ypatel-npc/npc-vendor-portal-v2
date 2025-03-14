/**
 * Admin JavaScript for NPC
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        // File input validation
        $('#csv_file').on('change', function() {
            var fileName = $(this).val();
            if (fileName && !fileName.toLowerCase().endsWith('.csv')) {
                alert('Please select a valid CSV file.');
                $(this).val('');
            }
        });

        // Toggle sample data visibility
        $('.toggle-sample-data').on('click', function(e) {
            e.preventDefault();
            $(this).closest('tr').find('.sample-data').toggle();
            $(this).text(function(i, text) {
                return text === 'Show Samples' ? 'Hide Samples' : 'Show Samples';
            });
        });
    });

})(jQuery);
