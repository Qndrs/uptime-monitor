jQuery(document).ready(function ($) {
    function escapeHtml(value) {
        return $('<div>').text(value || '').html();
    }

    function getStatusLabel(status) {
        if (status === 'up') {
            return uptimeMonitorL10n.status_up;
        }
        if (status === 'down') {
            return uptimeMonitorL10n.status_down;
        }

        return uptimeMonitorL10n.status_error;
    }

    function getStatusClass(status) {
        return ['up', 'down', 'error'].indexOf(status) !== -1 ? status : 'error';
    }

    function renderStatusCode(statusCode) {
        const parsedCode = parseInt(statusCode, 10);
        if (!parsedCode) {
            return '';
        }

        return `<span class="uptime-history-code">${escapeHtml(uptimeMonitorL10n.http_status.replace('%d', parsedCode))}</span>`;
    }

    function getResponseTimes(history) {
        if (!Array.isArray(history)) {
            return [];
        }

        return history.map(function (entry) {
            return parseInt(entry.response_time_ms, 10);
        }).filter(function (responseTime) {
            return !isNaN(responseTime);
        });
    }

    function getAverageResponseTime(history) {
        const responseTimes = getResponseTimes(history);
        if (responseTimes.length === 0) {
            return null;
        }

        return Math.round(responseTimes.reduce(function (total, responseTime) {
            return total + responseTime;
        }, 0) / responseTimes.length);
    }

    function getResponseTimeTrend(history) {
        const responseTimes = getResponseTimes(history);
        if (responseTimes.length < 2) {
            return null;
        }

        const latest = responseTimes.pop();
        const previousAverage = responseTimes.reduce(function (total, responseTime) {
            return total + responseTime;
        }, 0) / responseTimes.length;
        const threshold = Math.max(50, previousAverage * 0.1);

        if (latest > previousAverage + threshold) {
            return 'slower';
        }
        if (latest < previousAverage - threshold) {
            return 'faster';
        }

        return 'stable';
    }

    function getResponseTimeTrendLabel(trend) {
        if (trend === 'faster') {
            return uptimeMonitorL10n.trend_faster;
        }
        if (trend === 'slower') {
            return uptimeMonitorL10n.trend_slower;
        }

        return uptimeMonitorL10n.trend_stable;
    }

    function renderResponseTime(responseTime) {
        const parsedResponseTime = parseInt(responseTime, 10);
        if (isNaN(parsedResponseTime)) {
            return '';
        }

        return `<span class="uptime-history-response-time">${escapeHtml(uptimeMonitorL10n.response_time_ms.replace('%d', parsedResponseTime))}</span>`;
    }

    function renderResponseSummary(history) {
        const averageResponseTime = getAverageResponseTime(history);
        if (averageResponseTime === null) {
            return '';
        }

        const trend = getResponseTimeTrend(history);
        const trendMarkup = trend ? `<span class="uptime-response-trend uptime-response-trend-${trend}">${escapeHtml(getResponseTimeTrendLabel(trend))}</span>` : '';

        return `
            <div class="uptime-response-summary">
                <span class="uptime-response-average">${escapeHtml(uptimeMonitorL10n.average_response_time_ms.replace('%d', averageResponseTime))}</span>
                ${trendMarkup}
            </div>
        `;
    }

    function renderHistory(history) {
        if (!Array.isArray(history) || history.length === 0) {
            return `<span class="uptime-history-empty">${escapeHtml(uptimeMonitorL10n.no_history)}</span>`;
        }

        const items = history.slice(-5).reverse().map(function (entry) {
            const status = getStatusClass(entry.status);
            const timestamp = entry.timestamp || '';
            const displayTimestamp = entry.display_timestamp || timestamp;
            const message = entry.message || '';

            return `
                <li class="uptime-history-item">
                    <span class="uptime-status-badge uptime-status-${status}">${escapeHtml(getStatusLabel(status))}</span>
                    ${renderStatusCode(entry.status_code)}
                    ${renderResponseTime(entry.response_time_ms)}
                    ${timestamp ? `<time class="uptime-history-time" datetime="${escapeHtml(timestamp)}">${escapeHtml(displayTimestamp)}</time>` : ''}
                    ${message ? `<span class="uptime-history-message">${escapeHtml(message)}</span>` : ''}
                </li>
            `;
        }).join('');

        return `${renderResponseSummary(history)}<ol class="uptime-history-list">${items}</ol>`;
    }

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
            tableBody.append(`<tr><td colspan="6">${uptimeMonitorL10n.no_urls}</td></tr>`);
        } else {
            urls.forEach(function (urlData) {
                const checked = urlData.enabled ? 'checked' : '';
                tableBody.append(`
                    <tr>
                        <td>${escapeHtml(urlData.url)}</td>
                        <td>${urlData.email ? uptimeMonitorL10n.enabled : uptimeMonitorL10n.disabled}</td>
                        <td>${urlData.pushover ? uptimeMonitorL10n.enabled : uptimeMonitorL10n.disabled}</td>
                        <td><input type="checkbox" class="toggle-monitoring" data-id="${escapeHtml(urlData.id)}" ${checked}></td>
                        <td>${renderHistory(urlData.history)}</td>
                        <td><button class="button delete-url" data-id="${escapeHtml(urlData.id)}">${uptimeMonitorL10n.delete}</button></td>
                    </tr>
                `);
            });
        }
    }

    $('.uptime-monitor-table').on('change', '.toggle-monitoring', function () {
        const id = $(this).data('id');
        const enabled = $(this).is(':checked') ? 1 : 0;

        $.post(uptimeMonitorAjax.ajax_url, {
            action: 'toggle_uptime_monitoring',
            id: id,
            enabled: enabled,
            nonce: uptimeMonitorAjax.nonce
        }, function (response) {
            if (response.success) {
                updateTable(response.data.urls);
            } else {
                alert(uptimeMonitorL10n.error + response.data.message);
            }
        }).fail(function () {
            alert(uptimeMonitorL10n.error_generic);
        });
    });
});
