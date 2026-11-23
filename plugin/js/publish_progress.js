// Progreso de la página Publicar (local_liamateriales).
//
// Envía el formulario en segundo plano (fetch) y, mientras el servidor genera
// el fichero con liaex, muestra un spinner con el tiempo transcurrido frente
// a la duración habitual del formato. liaex no informa de su avance, así que
// la barra es aproximada y se dice. Al terminar:
// - Generar y publicar / Subir y publicar: navega a la actividad publicada.
// - Descargar sin publicar: entrega el fichero sin recargar la página.
// - Error: lo muestra encima del formulario sin perder lo elegido.
// Sin JavaScript, el formulario funciona como un POST normal.

(function () {
    'use strict';

    var form = document.getElementById('liamat-publish-form');
    if (!form || !window.fetch || !window.FormData) {
        return;
    }
    var config = JSON.parse(form.getAttribute('data-progress') || '{}');
    var strings = config.strings || {};
    var estimates = config.estimates || {};
    var running = false;

    function t(key, params) {
        var text = strings[key] || key;
        Object.keys(params || {}).forEach(function (name) {
            text = text.split('{' + name + '}').join(String(params[name]));
        });
        return text;
    }

    function clock(seconds) {
        var m = Math.floor(seconds / 60);
        var s = seconds % 60;
        return m + ':' + (s < 10 ? '0' : '') + s;
    }

    // --- Overlay de progreso ---------------------------------------------------

    function buildOverlay(title, estimate) {
        var overlay = document.createElement('div');
        overlay.className = 'liamat-progress';
        overlay.setAttribute('role', 'alertdialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'liamat-progress-title');
        overlay.style.cssText = 'position:fixed;inset:0;z-index:2000;display:flex;align-items:center;' +
            'justify-content:center;background:rgba(0,0,0,.45);padding:16px;';
        overlay.innerHTML =
            '<div class="card shadow" style="max-width:460px;width:100%;" tabindex="-1">' +
            '  <div class="card-body text-center">' +
            '    <div class="spinner-border text-primary mb-3" role="status" aria-hidden="true"></div>' +
            '    <h3 class="h5" id="liamat-progress-title"></h3>' +
            '    <p class="mb-1" data-role="elapsed" aria-live="polite"></p>' +
            '    <p class="text-muted small mb-2" data-role="estimate"></p>' +
            '    <div class="progress mb-2" style="height:6px;" aria-hidden="true">' +
            '      <div class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%"></div>' +
            '    </div>' +
            '    <p class="small mb-0" data-role="status" aria-live="polite"></p>' +
            '  </div>' +
            '</div>';
        overlay.querySelector('#liamat-progress-title').textContent = title;
        overlay.querySelector('[data-role="estimate"]').textContent = t('progress_estimate', {s: estimate});
        overlay.querySelector('[data-role="status"]').textContent = t('progress_keepopen');
        document.body.appendChild(overlay);
        overlay.querySelector('.card').focus();
        return overlay;
    }

    function startProgress(title, estimate) {
        var overlay = buildOverlay(title, estimate);
        var started = Date.now();
        var elapsedEl = overlay.querySelector('[data-role="elapsed"]');
        var statusEl = overlay.querySelector('[data-role="status"]');
        var bar = overlay.querySelector('.progress-bar');
        function tick() {
            var seconds = Math.floor((Date.now() - started) / 1000);
            elapsedEl.textContent = t('progress_elapsed', {time: clock(seconds)});
            // Aproximación: avanza hasta el 90 % en el tiempo habitual y se
            // queda cerca del final si tarda más; nunca llega al 100 % sola.
            var ratio = seconds / estimate;
            var width = ratio < 1 ? ratio * 90 : 90 + 8 * (1 - 1 / ratio);
            bar.style.width = width.toFixed(1) + '%';
            if (seconds > estimate * 1.5) {
                statusEl.textContent = t('progress_slow') + ' ' + t('progress_keepopen');
            }
        }
        tick();
        var timer = window.setInterval(tick, 1000);
        return {
            done: function (message) {
                window.clearInterval(timer);
                bar.style.width = '100%';
                bar.classList.remove('progress-bar-animated');
                overlay.querySelector('.spinner-border').style.visibility = 'hidden';
                statusEl.textContent = message;
            },
            close: function () {
                window.clearInterval(timer);
                overlay.remove();
            }
        };
    }

    // --- Avisos dentro de la página --------------------------------------------

    function showError(message) {
        var box = document.getElementById('liamat-publish-error');
        if (!box) {
            box = document.createElement('div');
            box.id = 'liamat-publish-error';
            box.className = 'alert alert-danger';
            box.setAttribute('role', 'alert');
            form.parentNode.insertBefore(box, form);
        }
        box.textContent = t('progress_error', {message: message});
        box.scrollIntoView({behavior: 'smooth', block: 'center'});
    }

    // Avisos del lector LiaScript (web y PDF de contenido) tras descargar.
    function showAvisos(avisos) {
        var box = document.getElementById('liamat-publish-avisos');
        if (box) {
            box.remove();
        }
        if (!avisos || !avisos.length) {
            return;
        }
        box = document.createElement('div');
        box.id = 'liamat-publish-avisos';
        box.className = 'alert alert-warning';
        box.setAttribute('role', 'status');
        var p = document.createElement('p');
        p.className = 'mb-1';
        p.textContent = t('progress_avisos');
        var list = document.createElement('ul');
        list.className = 'mb-0';
        avisos.forEach(function (aviso) {
            var li = document.createElement('li');
            li.textContent = aviso;
            list.appendChild(li);
        });
        box.appendChild(p);
        box.appendChild(list);
        form.parentNode.insertBefore(box, form);
    }

    function avisosFrom(response) {
        try {
            var list = JSON.parse(response.headers.get('X-Liamat-Avisos') || '[]');
            return Array.isArray(list) ? list : [];
        } catch (e) {
            return [];
        }
    }

    function setBusy(busy) {
        running = busy;
        Array.prototype.forEach.call(form.querySelectorAll('button, input, select'), function (el) {
            el.disabled = busy;
        });
    }

    window.addEventListener('beforeunload', function (event) {
        if (running) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    // --- Envío -----------------------------------------------------------------

    function filenameFrom(response) {
        var header = response.headers.get('Content-Disposition') || '';
        var star = /filename\*=(?:UTF-8'')?([^;]+)/i.exec(header);
        if (star) {
            return decodeURIComponent(star[1].replace(/"/g, ''));
        }
        var plain = /filename="?([^";]+)"?/i.exec(header);
        return plain ? plain[1] : 'material';
    }

    form.addEventListener('submit', function (event) {
        if (running) {
            event.preventDefault();
            return;
        }
        var submitter = event.submitter;
        var mode = submitter && submitter.name === 'mode' ? submitter.value : 'generate';
        var fileInput = form.querySelector('input[type="file"]');
        if (mode === 'upload' && (!fileInput || !fileInput.files.length)) {
            event.preventDefault();
            showError(t('progress_nofile'));
            return;
        }
        event.preventDefault();

        var data = new FormData(form);
        data.set('mode', mode);
        data.set('ajax', '1');
        var format = (form.querySelector('[name="artifact_type"]') || {}).value || '';
        var fmt = t('type_' + format);
        var titleKey = mode === 'download' ? 'progress_download' : (mode === 'upload' ? 'progress_upload' : 'progress_generate');
        var estimate = mode === 'upload' ? (estimates.upload || 6) : (estimates[format] || 20);
        var old = document.getElementById('liamat-publish-error');
        if (old) {
            old.remove();
        }

        var progress = startProgress(t(titleKey, {fmt: fmt}), estimate);
        setBusy(true);

        fetch(form.action, {method: 'POST', body: data, credentials: 'same-origin'})
            .then(function (response) {
                var type = response.headers.get('Content-Type') || '';
                if (type.indexOf('application/json') !== -1) {
                    return response.json().then(function (body) {
                        if (response.ok && body.status === 'published') {
                            progress.done(t('progress_published'));
                            running = false;
                            window.location.href = body.url;
                            return;
                        }
                        throw new Error(body.message || ('HTTP ' + response.status));
                    });
                }
                if (!response.ok || type.indexOf('text/html') !== -1) {
                    throw new Error('HTTP ' + response.status);
                }
                // Descarga: el cuerpo es el fichero generado.
                return response.blob().then(function (blob) {
                    var url = URL.createObjectURL(blob);
                    var link = document.createElement('a');
                    link.href = url;
                    link.download = filenameFrom(response);
                    document.body.appendChild(link);
                    link.click();
                    link.remove();
                    window.setTimeout(function () {
                        URL.revokeObjectURL(url);
                    }, 60000);
                    progress.done(t('progress_downloaded'));
                    showAvisos(avisosFrom(response));
                    setBusy(false);
                    window.setTimeout(progress.close, 2000);
                });
            })
            .catch(function (error) {
                progress.close();
                setBusy(false);
                showError(error.message);
            });
    });
})();
