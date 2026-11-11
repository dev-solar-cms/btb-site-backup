/**
 * BTB Sauvegarde — page d'administration.
 * Lancement d'une sauvegarde sans rechargement, barre de progression (AJAX), message de fin,
 * confirmations des actions sensibles.
 *
 * Configuration fournie par wp_localize_script : window.BTBBackup = { ajax, page, nonce, busy, i18n }.
 */
(function () {
    'use strict';

    var cfg = window.BTBBackup;
    if (!cfg) {
        return;
    }
    var t = cfg.i18n || {};
    var POLL_MS = 1500;
    var RETRY_MS = 15000;
    var RELOAD_MS = 4000;

    function $(id) {
        return document.getElementById(id);
    }

    var ui = {
        wrap: $('btb-pwrap'),
        fill: $('btb-pfill'),
        pct: $('btb-ppct'),
        kind: $('btb-pkind'),
        step: $('btb-pstep'),
        detail: $('btb-pdetail'),
        done: $('btb-done'),
        form: $('btb-run-form'),
        btn: $('btb-run-btn')
    };

    /** Affiche l'avancement : pourcentage, « Étape x/y — libellé », détail. */
    function showProgress(p) {
        if (!ui.wrap) {
            return;
        }
        ui.wrap.hidden = false;
        ui.kind.textContent = (p.kind || '') + ' ' + (t.running || '');
        ui.fill.style.width = p.percent + '%';
        ui.pct.textContent = p.percent + ' %';
        ui.step.textContent = p.steps ? (t.step + ' ' + p.step + '/' + p.steps + ' — ' + p.label) : (t.starting || '');
        ui.detail.textContent = p.detail || '';
    }

    /** Fin de l'opération : barre à 100 %, message coloré, puis rafraîchissement de la liste. */
    function showDone(d) {
        var failed = d.status === 'error';
        ui.fill.style.width = '100%';
        ui.pct.textContent = '100 %';
        ui.kind.textContent = failed ? t.failed : t.done;
        ui.step.textContent = '';
        ui.detail.textContent = '';

        var cls = d.status === 'ok' ? 'notice-success' : (failed ? 'notice-error' : 'notice-warning');
        ui.done.className = 'notice ' + cls;
        ui.done.querySelector('strong').textContent = (failed ? '✖ ' : '✔ ') + d.title;
        ui.done.querySelector('span').textContent = d.msg || '';
        ui.done.hidden = false;
        window.scrollTo({top: 0, behavior: 'smooth'});

        setTimeout(function () {
            window.location.replace(cfg.page + '&btb_done=1');
        }, RELOAD_MS);
    }

    function post(data) {
        var body = new FormData();
        Object.keys(data).forEach(function (k) {
            body.append(k, data[k]);
        });
        return fetch(cfg.ajax, {method: 'POST', body: body, credentials: 'same-origin'}).then(function (r) {
            return r.json();
        });
    }

    /** Interroge le serveur jusqu'à la fin de l'opération. */
    function poll() {
        post({action: 'btb_backup_status', _ajax_nonce: cfg.nonce})
            .then(function (r) {
                if (!r || !r.success) {
                    ui.detail.textContent = t.expired;
                    return setTimeout(poll, RETRY_MS);
                }
                if (!r.data.running) {
                    return showDone(r.data);
                }
                showProgress(r.data.progress);
                setTimeout(poll, POLL_MS);
            })
            .catch(function () {
                ui.detail.textContent = t.offline;
                setTimeout(poll, RETRY_MS);
            });
    }

    /** « Lancer la sauvegarde » : démarrage en AJAX (repli : envoi classique du formulaire). */
    function onRunSubmit(e) {
        e.preventDefault();
        ui.btn.disabled = true;
        ui.done.hidden = true;
        showProgress({kind: 'Sauvegarde', percent: 0, steps: 0, detail: t.prepare});

        var data = {action: 'btb_backup_start', _ajax_nonce: ui.form.querySelector('[name=_wpnonce]').value};
        ui.form.querySelectorAll('input[name=label], input[name=locked]:checked').forEach(function (input) {
            data[input.name] = input.value;
        });
        post(data)
            .then(function (r) {
                if (!r || !r.success) {
                    ui.wrap.hidden = true;
                    ui.btn.disabled = false;
                    window.alert((r && r.data && r.data.message) || t.cantStart);
                    return;
                }
                showProgress(r.data.progress);
                setTimeout(poll, 1000);
            })
            .catch(function () {
                ui.form.submit();
            });
    }

    /** Demande de confirmation pour les formulaires marqués data-confirm. */
    function onConfirmSubmit(e) {
        var form = e.target;
        if (form.dataset && form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
            e.preventDefault();
        }
    }

    document.addEventListener('submit', onConfirmSubmit, true);
    if (ui.form && ui.btn) {
        ui.form.addEventListener('submit', onRunSubmit);
    }
    if (cfg.busy) {
        setTimeout(poll, 1000);
    }
})();
