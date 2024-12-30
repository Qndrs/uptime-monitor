jQuery(document).ready(function ($) {
    // Add URL via AJAX
    $('#uptime-monitor-form').on('submit', function (e) {
        e.preventDefault();

        let url = $('#url').val();
        let emailAlert = $('#email_alert').is(':checked') ? 1 : 0;
        let pushoverAlert = $('#pushover_alert').is(':checked') ? 1 : 0;

        $.ajax({
            url: uptimeMonitorAjax.ajax_url,
            type: 'POST',
            data: {
                action: 'add_uptime_url',
                nonce: uptimeMonitorAjax.nonce,
                url: url,
                email_alert: emailAlert,
                pushover_alert: pushoverAlert
            },
            success: function (response) {
                if (response.success) {
                    updateTable(response.data.urls);
                    alert(uptimeMonitorL10n.add_success);
                } else {
                    alert(uptimeMonitorL10n.error + response.data.message);
                }
            },
            error: function () {
                alert(uptimeMonitorL10n.error_generic);
            }
        });
    });

    // Delete URL via AJAX
    $('.uptime-monitor-table').on('click', '.delete-url', function () {
        let id = $(this).data('id');

        $.ajax({
            url: uptimeMonitorAjax.ajax_url,
            type: 'POST',
            data: {
                action: 'delete_uptime_url',
                nonce: uptimeMonitorAjax.nonce,
                id: id
            },
            success: function (response) {
                if (response.success) {
                    updateTable(response.data.urls);
                    alert(uptimeMonitorL10n.delete_success);
                } else {
                    alert(uptimeMonitorL10n.error + response.data.message);
                }
            },
            error: function () {
                alert(uptimeMonitorL10n.error_generic);
            }
        });
    });

    // Update the table with new data
    function updateTable(urls) {
        let tableBody = $('.uptime-monitor-table tbody');
        tableBody.empty();

        if (urls.length === 0) {
            tableBody.append(`<tr><td colspan="4">${uptimeMonitorL10n.no_urls}</td></tr>`);
        } else {
            urls.forEach(function (urlData) {
                tableBody.append(`
                    <tr>
                        <td>${urlData.url}</td>
                        <td>${urlData.email ? uptimeMonitorL10n.enabled : uptimeMonitorL10n.disabled}</td>
                        <td>${urlData.pushover ? uptimeMonitorL10n.enabled : uptimeMonitorL10n.disabled}</td>
                        <td><button class="button delete-url" data-id="${urlData.id}">${uptimeMonitorL10n.delete}</button></td>
                    </tr>
                `);
            });
        }
    }
});
