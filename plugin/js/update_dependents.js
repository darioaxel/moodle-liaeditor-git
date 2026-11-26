// «Actualizar todos los contenidos dependientes de esta versión» (LIA-129).
//
// Intercepta los formularios .liamat-update-dependents de materials.php y,
// en lugar de un POST que lo actualiza todo de una vez (puede tardar
// minutos), pide a update_dependents.php cada contenido por separado
// (deploy=<id>&ajax=1) y muestra el avance en una lista. Al terminar,
// vuelve a cargar la lista de materiales. Sin JavaScript, el formulario
// funciona igual (todos en una petición y redirección con el resumen).

(function () {
    'use strict';

    var holder = document.getElementById('liamat-dependents');
    if (!holder || !window.fetch || !window.FormData) {
        return;
    }
    var config = JSON.parse(holder.getAttribute('data-config') || '{}');
    var strings = config.strings || {};
    var items = config.items || [];
    var running = false;

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined) {
            node.textContent = text;
        }
        return node;
    }

    function buildOverlay() {
        var overlay = el('div', 'liamat-progress');
        overlay.setAttribute('role', 'alertdialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'liamat-dependents-title');
        overlay.style.cssText = 'position:fixed;inset:0;z-index:2000;display:flex;align-items:center;' +
            'justify-content:center;background:rgba(0,0,0,.45);padding:16px;';
        var card = el('div', 'card shadow');
        card.style.cssText = 'max-width:560px;width:100%;max-height:90vh;overflow:auto;';
        card.tabIndex = -1;
        var body = el('div', 'card-body');
        var title = el('h3', 'h5', strings.title);
        title.id = 'liamat-dependents-title';
        var note = el('p', 'small text-muted', strings.keepopen);
        var list = el('ul', 'list-unstyled mb-3');
        list.setAttribute('aria-live', 'polite');
        var rows = items.map(function (item) {
            var li = el('li', 'd-flex justify-content-between gap-2 py-1 border-bottom');
            var label = el('span', '', item.label);
            var state = el('span', 'badge bg-secondary', strings.pending);
            state.setAttribute('data-role', 'state');
            li.appendChild(label);
            li.appendChild(state);
            list.appendChild(li);
            return { item: item, li: li, state: state };
        });
        var footer = el('div', 'd-flex justify-content-between align-items-center');
        var summary = el('p', 'mb-0 small', '');
        summary.setAttribute('data-role', 'summary');
        var close = el('button', 'btn btn-primary', strings.close);
        close.type = 'button';
        close.hidden = true;
        close.addEventListener('click', function () {
            window.location.href = config.returnurl;
        });
        footer.appendChild(summary);
        footer.appendChild(close);
        body.appendChild(title);
        body.appendChild(note);
        body.appendChild(list);
        body.appendChild(footer);
        card.appendChild(body);
        overlay.appendChild(card);
        document.body.appendChild(overlay);
        card.focus();
        return { rows: rows, summary: summary, close: close, note: note };
    }

    function setState(row, cls, text, detail) {
        row.state.className = 'badge bg-' + cls;
        row.state.textContent = text;
        if (detail) {
            row.state.title = detail;
            var info = el('div', 'small text-danger w-100', detail);
            row.li.classList.add('flex-wrap');
            row.li.appendChild(info);
        }
    }

    function updateOne(form, row) {
        var data = new FormData(form);
        data.append('deploy', String(row.item.id));
        data.append('ajax', '1');
        setState(row, 'info', strings.running);
        return fetch(form.action, { method: 'POST', body: data, credentials: 'same-origin' })
            .then(function (response) {
                return response.json().catch(function () {
                    return { status: 'error', message: 'HTTP ' + response.status };
                });
            })
            .then(function (json) {
                if (json.status === 'updated') {
                    setState(row, 'success', strings.updated);
                    return undefined;
                }
                if (json.status === 'skipped') {
                    setState(row, 'secondary', strings.skipped);
                    return undefined;
                }
                setState(row, 'danger', strings.error, json.message || '');
                return undefined;
            })
            .catch(function (error) {
                setState(row, 'danger', strings.error, String(error));
                return undefined;
            });
    }

    function run(form) {
        running = true;
        var ui = buildOverlay();
        var index = 0;
        function next() {
            if (index >= ui.rows.length) {
                ui.note.hidden = true;
                ui.summary.textContent = strings.done;
                ui.close.hidden = false;
                ui.close.focus();
                running = false;
                return undefined;
            }
            var row = ui.rows[index++];
            return updateOne(form, row).then(next);
        }
        next();
    }

    Array.prototype.forEach.call(document.querySelectorAll('form.liamat-update-dependents'), function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            if (running || items.length === 0) {
                return;
            }
            if (!window.confirm(strings.confirm)) {
                return;
            }
            run(form);
        });
    });
})();
