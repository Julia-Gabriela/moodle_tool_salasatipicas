// This file is part of Moodle - http://moodle.org/

/**
 * Sequential browser-side batch orchestrator.
 *
 * Each row starts one detached PHP CLI worker. This module waits for the current
 * row to finish before starting the next one, avoiding several heavy course copies
 * at the same time. Keep the progress page open until the batch is complete.
 *
 * @module tool_salasatipicas/import_runner
 */

const sleep = (ms) => new Promise((resolve) => window.setTimeout(resolve, ms));

const createResultRow = (result) => {
    const wrapper = document.createElement('div');
    wrapper.className = 'salasat-dep-import-result ' + (result.ok ? 'salasat-dep-import-ok' : 'salasat-dep-import-error');

    const icon = document.createElement('strong');
    icon.textContent = result.ok ? '✓ ' : '✕ ';
    wrapper.appendChild(icon);

    const title = document.createElement('span');
    const process = result.processnumber ? `${result.processnumber} — ` : '';
    title.textContent = `${process}${result.subject || ''}`;
    wrapper.appendChild(title);

    const detail = document.createElement('div');
    detail.className = 'small mt-1';
    detail.textContent = result.message || '';
    wrapper.appendChild(detail);

    return wrapper;
};

const setProgress = (completed, total) => {
    const bar = document.getElementById('salasat-dep-progress-bar');
    const label = document.getElementById('salasat-dep-progress-label');
    if (bar) {
        const percent = total > 0 ? Math.round((completed / total) * 100) : 100;
        bar.style.width = `${percent}%`;
        bar.setAttribute('aria-valuenow', String(completed));
    }
    if (label) {
        label.textContent = `${completed} de ${total} concluídas`;
    }
};

const renderActions = (config) => {
    const actions = document.getElementById('salasat-dep-import-actions');
    if (!actions) {
        return;
    }
    actions.innerHTML = '';

    const history = document.createElement('a');
    history.href = config.historyurl;
    history.className = 'btn btn-primary mr-2';
    history.textContent = config.labels.history;
    actions.appendChild(history);

    const manage = document.createElement('a');
    manage.href = config.manageurl;
    manage.className = 'btn btn-secondary';
    manage.textContent = config.labels.manage;
    actions.appendChild(manage);
};

const post = async(config, params) => {
    const body = new URLSearchParams(params);
    const response = await fetch(config.endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
        body: body.toString(),
    });
    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }
    return response.json();
};

const processRow = (config, index) => post(config, {
    action: 'processrow',
    token: config.token,
    index: String(index),
    sesskey: config.sesskey,
});

const getHistoryStatus = (config, historyid) => post(config, {
    action: 'status',
    token: config.token,
    historyid: String(historyid),
    sesskey: config.sesskey,
});

const waitForHistory = async(config, historyid) => {
    // Up to 45 minutes. Transient HTTP errors are tolerated because a very small
    // PHP-FPM pool can be busy while the background copy occupies one worker.
    const maxAttempts = 900;
    for (let attempt = 0; attempt < maxAttempts; attempt++) {
        await sleep(3000);
        try {
            const data = await getHistoryStatus(config, historyid);
            if (data.complete) {
                return data;
            }
        } catch (error) {
            // Retry instead of failing the whole batch on a temporary gateway or
            // worker-pool delay while the current course is being copied.
        }
    }
    throw new Error('Tempo máximo de acompanhamento excedido para esta sala. Consulte o Histórico.');
};

export const init = async(config) => {
    const status = document.getElementById('salasat-dep-import-status');
    let running = true;
    const leaveWarning = (event) => {
        if (!running) {
            return;
        }
        event.preventDefault();
        event.returnValue = '';
    };
    window.addEventListener('beforeunload', leaveWarning);
    const results = document.getElementById('salasat-dep-import-results');
    const total = config.total || 0;
    let success = 0;
    let failed = 0;

    setProgress(0, total);

    if (total <= 0) {
        if (status) {
            status.className = 'alert alert-warning mt-3';
            status.textContent = config.labels.complete;
        }
        running = false;
        window.removeEventListener('beforeunload', leaveWarning);
        renderActions(config);
        return;
    }

    try {
        for (let index = 0; index < total; index++) {
            if (status) {
                status.className = 'alert alert-info mt-3';
                status.textContent = `${config.labels.processing} (${index + 1}/${total})`;
            }

            const started = await processRow(config, index);
            if (started.fatal) {
                throw new Error(started.message || config.labels.fatal);
            }
            if (!started.historyid) {
                throw new Error(started.message || config.labels.fatal);
            }

            const finished = await waitForHistory(config, started.historyid);
            const result = Object.assign({}, started.result || {}, {
                ok: Boolean(finished.success),
                message: finished.message || started.message || '',
                courseid: finished.courseid || 0,
            });

            if (results) {
                results.appendChild(createResultRow(result));
            }
            if (result.ok) {
                success++;
            } else {
                failed++;
            }
            setProgress(index + 1, total);
        }

        if (status) {
            status.className = failed > 0 ? 'alert alert-warning mt-3' : 'alert alert-success mt-3';
            status.textContent = `${config.labels.complete}: ${success} concluída(s), ${failed} com erro.`;
        }
        running = false;
        window.removeEventListener('beforeunload', leaveWarning);
        renderActions(config);
    } catch (error) {
        if (status) {
            status.className = 'alert alert-danger mt-3';
            status.textContent = `${config.labels.fatal}: ${error.message}`;
        }
        running = false;
        window.removeEventListener('beforeunload', leaveWarning);
        renderActions(config);
    }
};
