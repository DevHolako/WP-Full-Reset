/**
 * WP Full Reset Admin Scripts
 */

(function ($) {
    'use strict';

    function showOverlay(text) {
        if ($('.wpfr-overlay').length === 0) {
            var html = '<div class="wpfr-overlay">' +
                '<div class="wpfr-spinner"></div>' +
                '<div class="wpfr-overlay-text">' + (text || WPFR_Data.strings.reset_in_progress) + '</div>' +
                '</div>';
            $('body').append(html);
        } else {
            $('.wpfr-overlay-text').text(text || WPFR_Data.strings.reset_in_progress);
            $('.wpfr-overlay').show();
        }
    }

    function hideOverlay() {
        $('.wpfr-overlay').fadeOut(200, function () {
            $(this).remove();
        });
    }

    $(document).ready(function () {

        // Select All / Deselect All handlers
        $('.wpfr-btn-select-all').on('click', function (e) {
            e.preventDefault();
            var targetClass = $(this).data('target');
            $('.' + targetClass).prop('checked', true);
        });

        $('.wpfr-btn-deselect-all').on('click', function (e) {
            e.preventDefault();
            var targetClass = $(this).data('target');
            $('.' + targetClass).prop('checked', false);
        });

        // 1. NUCLEAR RESET
        $('#btn-run-nuclear-reset').on('click', function (e) {
            e.preventDefault();

            var confirmInput = $('#nuclear_confirm_text').val().trim().toLowerCase();
            if (confirmInput !== 'reset') {
                alert('Please type "reset" in the confirmation box to proceed.');
                $('#nuclear_confirm_text').focus();
                return;
            }

            if (!confirm(WPFR_Data.strings.confirm_nuclear)) {
                return;
            }

            // Gather selected plugins to delete
            var pluginsToDelete = [];
            $('.wpfr-plugin-cb:checked').each(function () {
                pluginsToDelete.push($(this).val());
            });

            // Gather selected themes to delete
            var themesToDelete = [];
            $('.wpfr-theme-cb:checked').each(function () {
                themesToDelete.push($(this).val());
            });

            var args = {
                create_snapshot: $('#nuclear_create_snapshot').is(':checked'),
                delete_uploads: $('#nuclear_delete_uploads').is(':checked'),
                delete_custom_tables: $('#nuclear_delete_custom_tables').is(':checked'),
                plugins_to_delete: pluginsToDelete,
                themes_to_delete: themesToDelete,
                reactivate_plugins: $('#nuclear_reactivate_kept_plugins').is(':checked'),
                reactivate_theme: $('#nuclear_reactivate_theme').is(':checked'),
            };

            showOverlay(WPFR_Data.strings.reset_in_progress);

            $.post(WPFR_Data.ajax_url, {
                action: 'wpfr_run_reset',
                nonce: WPFR_Data.nonce,
                type: 'nuclear',
                args: args
            }, function (response) {
                if (response.success) {
                    window.location.href = response.data.redirect_url;
                } else {
                    hideOverlay();
                    alert(response.data.message || WPFR_Data.strings.error);
                }
            }).fail(function () {
                hideOverlay();
                alert('An unexpected server error occurred during reset.');
            });
        });

        // 2. SITE RESET (DB ONLY)
        $('#btn-run-site-reset').on('click', function (e) {
            e.preventDefault();

            var confirmInput = $('#site_confirm_text').val().trim().toLowerCase();
            if (confirmInput !== 'reset') {
                alert('Please type "reset" in the confirmation box to proceed.');
                $('#site_confirm_text').focus();
                return;
            }

            if (!confirm(WPFR_Data.strings.confirm_site)) {
                return;
            }

            var args = {
                create_snapshot: $('#site_create_snapshot').is(':checked'),
                reactivate_theme: $('#site_reactivate_theme').is(':checked'),
            };

            showOverlay(WPFR_Data.strings.reset_in_progress);

            $.post(WPFR_Data.ajax_url, {
                action: 'wpfr_run_reset',
                nonce: WPFR_Data.nonce,
                type: 'site',
                args: args
            }, function (response) {
                if (response.success) {
                    window.location.href = response.data.redirect_url;
                } else {
                    hideOverlay();
                    alert(response.data.message || WPFR_Data.strings.error);
                }
            }).fail(function () {
                hideOverlay();
                alert('An unexpected server error occurred during reset.');
            });
        });

        // 3. OPTIONS RESET
        $('#btn-run-options-reset').on('click', function (e) {
            e.preventDefault();

            if (!confirm(WPFR_Data.strings.confirm_options)) {
                return;
            }

            var args = {
                create_snapshot: $('#options_create_snapshot').is(':checked')
            };

            showOverlay('Resetting Options table...');

            $.post(WPFR_Data.ajax_url, {
                action: 'wpfr_run_reset',
                nonce: WPFR_Data.nonce,
                type: 'options',
                args: args
            }, function (response) {
                if (response.success) {
                    window.location.href = response.data.redirect_url;
                } else {
                    hideOverlay();
                    alert(response.data.message || WPFR_Data.strings.error);
                }
            }).fail(function () {
                hideOverlay();
                alert('An unexpected server error occurred.');
            });
        });

        // 4. CLEANUP TOOLS
        $('.wpfr-tool-btn').on('click', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var action = $btn.data('action');

            if (!confirm('Run this tool now?')) {
                return;
            }

            var origText = $btn.text();
            $btn.prop('disabled', true).text('Running...');

            $.post(WPFR_Data.ajax_url, {
                action: action,
                nonce: WPFR_Data.nonce
            }, function (response) {
                $btn.prop('disabled', false).text(origText);
                if (response.success) {
                    alert(response.data.message);
                } else {
                    alert(response.data.message || WPFR_Data.strings.error);
                }
            }).fail(function () {
                $btn.prop('disabled', false).text(origText);
                alert('Tool execution failed.');
            });
        });

        // 5. SNAPSHOTS
        $('#btn-create-snapshot').on('click', function (e) {
            e.preventDefault();
            var desc = prompt('Enter a short description for this snapshot:', 'Manual snapshot before testing');
            if (desc === null) return;

            showOverlay('Creating Database Snapshot...');

            $.post(WPFR_Data.ajax_url, {
                action: 'wpfr_create_snapshot',
                nonce: WPFR_Data.nonce,
                description: desc
            }, function (response) {
                hideOverlay();
                if (response.success) {
                    alert(response.data.message);
                    window.location.reload();
                } else {
                    alert(response.data.message || WPFR_Data.strings.error);
                }
            }).fail(function () {
                hideOverlay();
                alert('Failed to create snapshot.');
            });
        });

        // Restore snapshot
        $(document).on('click', '.btn-restore-snapshot', function (e) {
            e.preventDefault();
            var file = $(this).data('file');

            if (!confirm(WPFR_Data.strings.confirm_restore)) {
                return;
            }

            showOverlay('Restoring snapshot database...');

            $.post(WPFR_Data.ajax_url, {
                action: 'wpfr_restore_snapshot',
                nonce: WPFR_Data.nonce,
                file: file
            }, function (response) {
                hideOverlay();
                if (response.success) {
                    alert(response.data.message);
                    window.location.reload();
                } else {
                    alert(response.data.message || WPFR_Data.strings.error);
                }
            }).fail(function () {
                hideOverlay();
                alert('Snapshot restoration failed.');
            });
        });

        // Delete snapshot
        $(document).on('click', '.btn-delete-snapshot', function (e) {
            e.preventDefault();
            var file = $(this).data('file');
            var $row = $(this).closest('tr');

            if (!confirm(WPFR_Data.strings.confirm_delete)) {
                return;
            }

            $.post(WPFR_Data.ajax_url, {
                action: 'wpfr_delete_snapshot',
                nonce: WPFR_Data.nonce,
                file: file
            }, function (response) {
                if (response.success) {
                    $row.fadeOut(300, function () {
                        $(this).remove();
                    });
                } else {
                    alert(response.data.message || WPFR_Data.strings.error);
                }
            });
        });

    });

})(jQuery);
