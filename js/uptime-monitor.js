jQuery(document).ready(function ($) {
    const refreshIntervalMs = parseInt(uptimeMonitorAjax.refresh_interval_ms, 10) || 30000;
    const autoRefreshStorageKey = 'uptimeMonitorAutoRefresh';
    let autoRefreshTimer = null;
    let dashboardRefreshRequest = null;
    let widgetRefreshTimer = null;
    let widgetRefreshRequest = null;

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
        if (status === 'paused') {
            return uptimeMonitorL10n.status_paused;
        }
        if (status === 'degraded') {
            return uptimeMonitorL10n.status_degraded;
        }

        return uptimeMonitorL10n.status_unknown || uptimeMonitorL10n.status_error;
    }

    function getStatusClass(status) {
        return ['up', 'down', 'error', 'paused', 'degraded', 'unknown'].indexOf(status) !== -1 ? status : 'unknown';
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

    function renderUptimeSummary(uptime) {
        if (!uptime || typeof uptime !== 'object') {
            return '';
        }

        const periods = ['24h', '7d', '30d'];
        const items = periods.map(function (periodKey) {
            const period = uptime[periodKey];
            if (!period || period.percentage === null || typeof period.percentage === 'undefined') {
                return '';
            }

            const percentage = parseFloat(period.percentage);
            if (isNaN(percentage)) {
                return '';
            }

            const percentageDisplay = period.percentage_display || percentage.toFixed(1);

            return `
                <span class="uptime-percentage uptime-percentage-${periodKey}">
                    <span class="uptime-percentage-label">${escapeHtml(period.label)}</span>
                    <span class="uptime-percentage-value">${escapeHtml(percentageDisplay)}%</span>
                </span>
            `;
        }).join('');

        if (!items.trim()) {
            return '';
        }

        return `<div class="uptime-percentage-summary" aria-label="${escapeHtml(uptimeMonitorL10n.uptime)}">${items}</div>`;
    }

    function renderHistory(history, uptime) {
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

        return `${renderUptimeSummary(uptime)}${renderResponseSummary(history)}<ol class="uptime-history-list">${items}</ol>`;
    }

    function getHost(url) {
        try {
            return new URL(url).host;
        } catch (error) {
            return url || '';
        }
    }

    function getDashboard(urlData) {
        return urlData.dashboard && typeof urlData.dashboard === 'object' ? urlData.dashboard : {};
    }

    function renderCurrentCheck(dashboard) {
        const latest = dashboard.latest && typeof dashboard.latest === 'object' ? dashboard.latest : null;
        if (!latest) {
            return `<span class="uptime-chip uptime-chip-muted">${escapeHtml(uptimeMonitorL10n.no_history)}</span>`;
        }

        const statusCode = parseInt(latest.status_code, 10);
        const responseTime = parseInt(latest.response_time_ms, 10);
        const timestamp = latest.timestamp || '';
        const displayTimestamp = latest.display_timestamp || timestamp;

        return `
            ${statusCode ? `<span class="uptime-chip">${escapeHtml(uptimeMonitorL10n.http_status.replace('%d', statusCode))}</span>` : ''}
            ${!isNaN(responseTime) ? `<span class="uptime-chip">${escapeHtml(uptimeMonitorL10n.response_time_ms.replace('%d', responseTime))}</span>` : ''}
            ${timestamp ? `<time class="uptime-chip uptime-chip-muted" datetime="${escapeHtml(timestamp)}">${escapeHtml(displayTimestamp)}</time>` : ''}
        `;
    }

    function renderUrlRow(urlData) {
        const dashboard = getDashboard(urlData);
        const status = getStatusClass(dashboard.status || 'unknown');
        const statusLabel = dashboard.status_label || getStatusLabel(status);
        const notificationsLabel = dashboard.notifications_label || uptimeMonitorL10n.no_alerts;
        const incidentOpen = !!dashboard.incident_open;
        const incidentLabel = dashboard.incident_label || (incidentOpen ? uptimeMonitorL10n.incident_open : uptimeMonitorL10n.no_incident);
        const incidentDuration = dashboard.incident_duration_display || '';
        const checked = urlData.enabled ? 'checked' : '';
        const type = urlData.type || 'http_check';
        const url = urlData.url || '';
        const title = type === 'heartbeat' ? (urlData.name || uptimeMonitorL10n.heartbeat) : getHost(url);
        const identityMeta = type === 'heartbeat'
            ? `<span>${escapeHtml((uptimeMonitorL10n.expected_every_seconds || 'Expected every %d seconds').replace('%d', parseInt(urlData.heartbeat && urlData.heartbeat.expected_interval ? urlData.heartbeat.expected_interval : urlData.expected_interval, 10) || 300))}</span>`
            : `<a href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(url)}</a>`;
        const averageResponse = dashboard.average_response_time_display
            ? `<span class="uptime-response-average">${escapeHtml(dashboard.average_response_time_display)}</span>`
            : '';

        return `
            <article class="uptime-url-row is-${status}" data-id="${escapeHtml(urlData.id)}">
                <div class="uptime-url-status">
                    <span class="uptime-status-light is-${status}" aria-hidden="true"></span>
                    <strong>${escapeHtml(statusLabel)}</strong>
                </div>
                <div class="uptime-url-identity">
                    <strong>${escapeHtml(title)}</strong>
                    <span class="uptime-chip uptime-chip-muted">${escapeHtml(type === 'heartbeat' ? uptimeMonitorL10n.heartbeat : uptimeMonitorL10n.http_check)}</span>
                    ${identityMeta}
                </div>
                <div class="uptime-url-current">
                    ${renderCurrentCheck(dashboard)}
                </div>
                <div class="uptime-url-metrics">
                    ${renderUptimeSummary(urlData.uptime)}
                    ${averageResponse}
                </div>
                <div class="uptime-url-alerts">
                    <span class="uptime-chip${notificationsLabel === uptimeMonitorL10n.no_alerts ? ' is-warning' : ''}">${escapeHtml(notificationsLabel)}</span>
                    <span class="uptime-chip${incidentOpen ? ' is-danger' : ' uptime-chip-muted'}">${escapeHtml(incidentLabel)}</span>
                    ${incidentDuration ? `<span class="uptime-chip is-danger">${escapeHtml(incidentDuration)}</span>` : ''}
                </div>
                <div class="uptime-url-actions">
                    <label class="uptime-toggle"><input type="checkbox" class="toggle-monitoring" data-id="${escapeHtml(urlData.id)}" ${checked}> <span>${escapeHtml(uptimeMonitorL10n.monitoring || 'Monitoring')}</span></label>
                    <button type="button" class="button toggle-history" aria-expanded="false"><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span><span class="uptime-action-label">${escapeHtml(uptimeMonitorL10n.details)}</span></button>
                    <button type="button" class="button delete-url uptime-delete-button" data-id="${escapeHtml(urlData.id)}">${escapeHtml(uptimeMonitorL10n.delete)}</button>
                </div>
                <div class="uptime-url-history" hidden>
                    ${renderHistory(urlData.history, urlData.uptime)}
                </div>
            </article>
        `;
    }

    function updateUrlList(urls) {
        const list = $('.uptime-url-list');
        const expandedIds = [];

        list.find('.uptime-url-row').each(function () {
            const row = $(this);
            const id = row.data('id') || row.find('.toggle-monitoring').data('id');

            if (id && row.find('.toggle-history').attr('aria-expanded') === 'true') {
                expandedIds.push(String(id));
            }
        });

        list.empty();

        if (!Array.isArray(urls) || urls.length === 0) {
            list.append(`<div class="uptime-empty-state">${escapeHtml(uptimeMonitorL10n.no_urls)}</div>`);
            return;
        }

        urls.forEach(function (urlData) {
            list.append(renderUrlRow(urlData));
        });

        expandedIds.forEach(function (id) {
            const row = list.find('.uptime-url-row').filter(function () {
                return String($(this).data('id')) === id;
            }).first();
            const button = row.find('.toggle-history').first();

            if (!row.length || !button.length) {
                return;
            }

            button.attr('aria-expanded', 'true');
            button.addClass('is-expanded');
            button.find('.uptime-action-label').text(uptimeMonitorL10n.hide_details);
            row.find('.uptime-url-history').first().prop('hidden', false);
        });
    }

    function updateDashboard(html) {
        if (!html) {
            return;
        }

        const parsed = $('<div>').html(html);
        const statusbar = parsed.find('.uptime-statusbar');
        const metrics = parsed.find('.uptime-metric-grid');

        if (statusbar.length) {
            $('.uptime-statusbar').replaceWith(statusbar);
        }
        if (metrics.length) {
            $('.uptime-metric-grid').replaceWith(metrics);
        }
    }

    function updateDashboardResponse(data) {
        updateDashboard(data.dashboard_html);
        updateUrlList(data.urls);
    }

    function getErrorMessage(response) {
        const payload = response && response.responseJSON ? response.responseJSON : response;

        if (payload && payload.data && payload.data.message) {
            return uptimeMonitorL10n.error + payload.data.message;
        }

        if (payload && payload.message) {
            return uptimeMonitorL10n.error + payload.message;
        }

        return uptimeMonitorL10n.error_generic;
    }

    function setRefreshState(message) {
        $('.uptime-refresh-state').text(message || '');
    }

    function refreshDashboard(options) {
        const settings = $.extend({ silent: false }, options || {});
        const refreshButton = $('.uptime-refresh-dashboard');

        if (!refreshButton.length || dashboardRefreshRequest) {
            return;
        }

        if (!settings.silent) {
            setRefreshState(uptimeMonitorL10n.refreshing);
        }

        refreshButton.prop('disabled', true);
        dashboardRefreshRequest = $.ajax({
            url: uptimeMonitorAjax.ajax_url,
            type: 'POST',
            data: {
                action: 'refresh_uptime_dashboard',
                nonce: uptimeMonitorAjax.nonce
            },
            success: function (response) {
                if (response.success) {
                    updateDashboardResponse(response.data);
                    setRefreshState(uptimeMonitorL10n.refreshed);
                    return;
                }

                if (!settings.silent) {
                    showNotice(getErrorMessage(response), 'error');
                }
            },
            error: function (jqXHR) {
                if (!settings.silent) {
                    showNotice(getErrorMessage(jqXHR), 'error');
                }
            },
            complete: function () {
                refreshButton.prop('disabled', false);
                dashboardRefreshRequest = null;
            }
        });
    }

    function updateDashboardWidget(html) {
        if (!html) {
            return;
        }

        const parsed = $('<div>').html(html);
        const widget = parsed.find('.uptime-dashboard-widget');

        if (widget.length) {
            $('.uptime-dashboard-widget').replaceWith(widget);
        }
    }

    function refreshDashboardWidget() {
        if (!$('.uptime-dashboard-widget').length || widgetRefreshRequest) {
            return;
        }

        widgetRefreshRequest = $.ajax({
            url: uptimeMonitorAjax.ajax_url,
            type: 'POST',
            data: {
                action: 'refresh_uptime_dashboard',
                nonce: uptimeMonitorAjax.nonce
            },
            success: function (response) {
                if (response.success && response.data) {
                    updateDashboardWidget(response.data.widget_html);
                }
            },
            complete: function () {
                widgetRefreshRequest = null;
            }
        });
    }

    function startDashboardWidgetRefresh() {
        if (!$('.uptime-dashboard-widget').length || $('.uptime-refresh-dashboard').length) {
            return;
        }

        widgetRefreshTimer = window.setInterval(function () {
            if (document.hidden) {
                return;
            }

            refreshDashboardWidget();
        }, refreshIntervalMs);
    }

    function stopAutoRefresh() {
        if (autoRefreshTimer) {
            window.clearInterval(autoRefreshTimer);
            autoRefreshTimer = null;
        }
    }

    function startAutoRefresh() {
        stopAutoRefresh();
        autoRefreshTimer = window.setInterval(function () {
            if (document.hidden) {
                return;
            }

            refreshDashboard({ silent: true });
        }, refreshIntervalMs);
    }

    function setAutoRefresh(enabled) {
        const isEnabled = !!enabled;

        $('.uptime-auto-refresh-input').prop('checked', isEnabled);
        window.localStorage.setItem(autoRefreshStorageKey, isEnabled ? '1' : '0');

        if (isEnabled) {
            startAutoRefresh();
            refreshDashboard({ silent: true });
            return;
        }

        stopAutoRefresh();
    }

    function showNotice(message, type) {
        const noticeType = type === 'error' ? 'error' : 'success';
        let notices = $('.uptime-monitor-notices').first();

        if (!notices.length) {
            notices = $('<div class="uptime-monitor-notices" aria-live="polite" aria-atomic="true"></div>');
            $('.uptime-monitor-dashboard').prepend(notices);
        }

        const notice = $(`
            <div class="notice uptime-monitor-notice notice-${noticeType}">
                <p>${escapeHtml(message)}</p>
                <button type="button" class="notice-dismiss">
                    <span class="screen-reader-text">${escapeHtml(uptimeMonitorL10n.dismiss || 'Dismiss this notice.')}</span>
                </button>
            </div>
        `);

        notices.empty().append(notice);
        window.setTimeout(function () {
            notice.fadeOut(160, function () {
                $(this).remove();
            });
        }, 4500);
    }

    $('.uptime-refresh-dashboard').on('click', function () {
        refreshDashboard();
    });

    $('.uptime-auto-refresh-input').on('change', function () {
        setAutoRefresh($(this).is(':checked'));
    });

    if ($('.uptime-auto-refresh-input').length && window.localStorage.getItem(autoRefreshStorageKey) === '1') {
        setAutoRefresh(true);
    }

    startDashboardWidgetRefresh();

    $('#uptime-monitor-form').on('submit', function (e) {
        e.preventDefault();

        const url = $('#url').val();
        const emailAlert = $('#email_alert').is(':checked') ? 1 : 0;
        const pushoverAlert = $('#pushover_alert').is(':checked') ? 1 : 0;

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
                    updateDashboardResponse(response.data);
                    $('#url').val('');
                    showNotice(uptimeMonitorL10n.add_success, 'success');
                } else {
                    showNotice(getErrorMessage(response), 'error');
                }
            },
            error: function (jqXHR) {
                showNotice(getErrorMessage(jqXHR), 'error');
            }
        });
    });

    $('.uptime-url-list').on('click', '.delete-url', function () {
        const id = $(this).data('id');

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
                    updateDashboardResponse(response.data);
                    showNotice(uptimeMonitorL10n.delete_success, 'success');
                } else {
                    showNotice(getErrorMessage(response), 'error');
                }
            },
            error: function (jqXHR) {
                showNotice(getErrorMessage(jqXHR), 'error');
            }
        });
    });

    $('.uptime-url-list').on('change', '.toggle-monitoring', function () {
        const id = $(this).data('id');
        const enabled = $(this).is(':checked') ? 1 : 0;

        $.post(uptimeMonitorAjax.ajax_url, {
            action: 'toggle_uptime_monitoring',
            id: id,
            enabled: enabled,
            nonce: uptimeMonitorAjax.nonce
        }, function (response) {
            if (response.success) {
                updateDashboardResponse(response.data);
            } else {
                showNotice(getErrorMessage(response), 'error');
            }
        }).fail(function (jqXHR) {
            showNotice(getErrorMessage(jqXHR), 'error');
        });
    });

    $('.uptime-monitor-dashboard').on('click', '.uptime-monitor-notice .notice-dismiss', function () {
        $(this).closest('.uptime-monitor-notice').remove();
    });

    $('.uptime-monitor-dashboard').on('click', '.uptime-copy-endpoint', function () {
        const value = $(this).data('copy-value') || '';
        if (!value) {
            showNotice(uptimeMonitorL10n.copy_failed, 'error');
            return;
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(value).then(function () {
                showNotice(uptimeMonitorL10n.copy_success, 'success');
            }).catch(function () {
                showNotice(uptimeMonitorL10n.copy_failed, 'error');
            });
            return;
        }

        const input = $('<input type="text" class="screen-reader-text">').val(value).appendTo('body');
        input.trigger('select');
        try {
            document.execCommand('copy');
            showNotice(uptimeMonitorL10n.copy_success, 'success');
        } catch (error) {
            showNotice(uptimeMonitorL10n.copy_failed, 'error');
        }
        input.remove();
    });

    $('.uptime-url-list').on('click', '.toggle-history', function () {
        const button = $(this);
        const row = button.closest('.uptime-url-row');
        const history = row.find('.uptime-url-history').first();
        const expanded = button.attr('aria-expanded') === 'true';

        button.attr('aria-expanded', expanded ? 'false' : 'true');
        button.toggleClass('is-expanded', !expanded);
        button.find('.uptime-action-label').text(expanded ? uptimeMonitorL10n.details : uptimeMonitorL10n.hide_details);
        history.prop('hidden', expanded);
    });
});
