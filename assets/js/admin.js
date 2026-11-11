/**
 * Royal Pay Admin JavaScript — SweetAlert2, DataTables, and Tab Switching
 */
(function($) {
    'use strict';

    $(document).ready(function() {

        // 1. DataTables Initialization
        if ($.fn.DataTable) {
            $('.royalpay-datatable').each(function() {
                if (!$.fn.DataTable.isDataTable(this)) {
                    $(this).DataTable({
                        responsive: true,
                        pageLength: 25,
                        order: [],
                        language: {
                            search: "",
                            searchPlaceholder: "Search records...",
                            lengthMenu: "_MENU_ entries per page",
                            info: "Showing _START_ to _END_ of _TOTAL_ entries",
                            infoEmpty: "No records found",
                            emptyTable: "No data available in table"
                        }
                    });
                }
            });
        }

        // 2. Client-side Instant Tab Switching (Settings & Subpages)
        $('.rp-tab-btn').on('click', function(e) {
            e.preventDefault();
            var targetTab = $(this).data('tab');

            $('.rp-tab-btn').removeClass('active');
            $(this).addClass('active');

            $('.rp-tab-pane').removeClass('active');
            $('#' + targetTab).addClass('active');

            // Persist tab via hash
            if (history.replaceState) {
                history.replaceState(null, null, '#' + targetTab);
            }
        });

        // Activate tab from URL hash if present
        if (window.location.hash) {
            var hash = window.location.hash.substring(1);
            var $targetBtn = $('.rp-tab-btn[data-tab="' + hash + '"]');
            if ($targetBtn.length) {
                $targetBtn.trigger('click');
            }
        }

        // 3. WordPress Color Picker
        if ($.fn.wpColorPicker) {
            $('.rp-color-picker').wpColorPicker();
        }

        // Color preset and palette click handler
        $('.rp-color-preset, .rp-palette-card').on('click', function() {
            var primary = $(this).data('primary');
            var dark = $(this).data('dark');

            $('.rp-color-preset, .rp-palette-card').removeClass('active');
            $(this).addClass('active');

            $('#royalpay_primary_color').val(primary).trigger('change');
            $('#royalpay_primary_dark').val(dark).trigger('change');
        });

        // 4. SweetAlert2 License Activation Handler
        $('#rp-license-form').on('submit', function(e) {
            e.preventDefault();

            var $form = $(this);
            var $btn = $form.find('#rp-activate-btn');
            var $input = $form.find('#royalpay_license_key');
            var licenseKey = $input.val().trim();

            if (!licenseKey) {
                Swal.fire({
                    icon: 'warning',
                    title: 'License Key Required',
                    text: 'Please enter your Royal Pay license key.'
                });
                return;
            }

            // Disable button & make input read-only during activation
            $btn.prop('disabled', true).text('Activating...');
            $input.prop('readonly', true);

            // Show SweetAlert2 loading popup
            Swal.fire({
                title: 'Activating License...',
                text: 'Verifying cryptographic signature with RoyalAuth server...',
                allowOutsideClick: false,
                didOpen: function() {
                    Swal.showLoading();
                }
            });

            // AJAX request to verify license
            $.ajax({
                url: royalpay_vars.ajax_url,
                type: 'POST',
                data: {
                    action: 'royalpay_activate_license',
                    license_key: licenseKey,
                    nonce: royalpay_vars.license_nonce
                },
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'License Activated!',
                            text: response.data.message || 'License verified successfully with RoyalAuth!',
                            timer: 1600,
                            showConfirmButton: false
                        });

                        setTimeout(function() {
                            window.location.href = response.data.redirect || royalpay_vars.dashboard_url;
                        }, 1600);
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Activation Failed',
                            text: response.data.message || 'Invalid license key or domain unauthorized.'
                        });
                        $btn.prop('disabled', false).text('Activate License');
                        $input.prop('readonly', false);
                    }
                },
                error: function() {
                    Swal.fire({
                        icon: 'error',
                        title: 'Connection Error',
                        text: 'Failed to contact the server. Please check your internet connection.'
                    });
                    $btn.prop('disabled', false).text('Activate License');
                    $input.prop('readonly', false);
                }
            });
        });

        // 5. SweetAlert2 License Deactivation Handler
        $('#rp-deactivate-license-btn').on('click', function(e) {
            e.preventDefault();
            var $form = $('#rp-deactivate-form');

            Swal.fire({
                title: 'Deactivate License?',
                text: 'MFS automated payment verification will be disabled on this site.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, Deactivate'
            }).then(function(result) {
                if (result.isConfirmed) {
                    $form.submit();
                }
            });
        });

        // 6. SweetAlert2 Delete Wallet Confirmation
        $(document).on('click', '.rp-delete-wallet-btn', function(e) {
            e.preventDefault();
            var href = $(this).attr('href');

            Swal.fire({
                title: 'Delete MFS Wallet?',
                text: 'Customers will no longer see this number at checkout.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                confirmButtonText: 'Yes, Delete'
            }).then(function(result) {
                if (result.isConfirmed) {
                    window.location.href = href;
                }
            });
        });

        // 7. SweetAlert2 Revoke Device Confirmation
        $(document).on('click', '.rp-revoke-device-btn', function(e) {
            e.preventDefault();
            var href = $(this).attr('href');

            Swal.fire({
                title: 'Revoke Android Device?',
                text: 'This phone will immediately stop syncing incoming SMS.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                confirmButtonText: 'Yes, Revoke Device'
            }).then(function(result) {
                if (result.isConfirmed) {
                    window.location.href = href;
                }
            });
        });

    });
})(jQuery);
