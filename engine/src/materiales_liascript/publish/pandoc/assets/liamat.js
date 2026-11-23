// Comportamiento de la web estática de Materiales Editables.
//
// Da vida al lenguaje intermedio que genera el lector LiaScript (Pandoc
// escribe sus atributos como data-*): quizzes de opción única y múltiple,
// respuesta de texto, matriz y selección en línea, con pistas y solución, y
// encuestas (sin guardar respuestas). Sin dependencias. Si no hay
// JavaScript, el contenido se ve completo con la solución.

(function () {
    'use strict';

    document.documentElement.classList.add('js');

    var contador = 0;

    function crear(tag, atributos, hijos) {
        var el = document.createElement(tag);
        Object.keys(atributos || {}).forEach(function (k) {
            if (k === 'texto') {
                el.textContent = atributos[k];
            } else {
                el.setAttribute(k, atributos[k]);
            }
        });
        (hijos || []).forEach(function (h) {
            el.appendChild(h);
        });
        return el;
    }

    function boton(texto, principal) {
        return crear('button', {type: 'button', class: 'lia-boton' + (principal ? ' lia-boton--principal' : ''), texto: texto});
    }

    function hijos(el, clase) {
        return Array.prototype.filter.call(el.children, function (c) {
            return c.classList.contains(clase);
        });
    }

    function normalizar(s) {
        return String(s).trim().toLowerCase().replace(/\s+/g, ' ');
    }

    // --- Pistas, solución y respuesta ------------------------------------------

    function acciones(quiz, comprobar, mostrar) {
        var zona = crear('div', {class: 'lia-acciones'});
        var feedback = crear('p', {class: 'lia-feedback', role: 'status', 'aria-live': 'polite'});
        var pistas = hijos(quiz, 'pista');
        var solucion = hijos(quiz, 'solucion')[0];

        var comprobarBtn = boton('Comprobar', true);
        comprobarBtn.addEventListener('click', function () {
            var ok = comprobar();
            feedback.className = 'lia-feedback ' + (ok ? 'ok' : 'ko');
            feedback.textContent = ok ? '¡Correcto!' : 'No es correcto. Inténtalo de nuevo.';
            if (ok && solucion) {
                solucion.classList.add('visible');
            }
        });
        zona.appendChild(comprobarBtn);

        if (pistas.length) {
            var siguiente = 0;
            var pistaBtn = boton('Pista (' + pistas.length + ')');
            pistaBtn.addEventListener('click', function () {
                pistas[siguiente].classList.add('visible');
                siguiente++;
                if (siguiente >= pistas.length) {
                    pistaBtn.disabled = true;
                } else {
                    pistaBtn.textContent = 'Otra pista (' + (pistas.length - siguiente) + ')';
                }
            });
            zona.appendChild(pistaBtn);
        }

        var verBtn = boton(solucion ? 'Ver solución' : 'Ver respuesta');
        verBtn.addEventListener('click', function () {
            mostrar();
            if (solucion) {
                solucion.classList.add('visible');
            }
            feedback.className = 'lia-feedback';
            feedback.textContent = '';
        });
        zona.appendChild(verBtn);

        // Antes de las pistas y la solución, que siguen en su sitio.
        var ancla = pistas[0] || solucion || null;
        quiz.insertBefore(zona, ancla);
        quiz.insertBefore(feedback, ancla);
    }

    // --- Opción única y múltiple -----------------------------------------------

    function opciones(quiz, multiple, encuesta) {
        var nombre = 'lia-q' + (++contador);
        var items = hijos(quiz, 'opcion').map(function (op) {
            var input = crear('input', {type: multiple ? 'checkbox' : 'radio', name: nombre});
            var etiqueta = crear('label', {class: 'lia-opcion'}, [input]);
            while (op.firstChild) {
                etiqueta.appendChild(op.firstChild);
            }
            op.appendChild(etiqueta);
            return {op: op, input: input, etiqueta: etiqueta, correcta: op.getAttribute('data-correcta') === 'true'};
        });
        if (encuesta) {
            return enviar(quiz);
        }
        acciones(quiz, function () {
            var ok = true;
            items.forEach(function (it) {
                it.etiqueta.classList.remove('es-correcta', 'es-incorrecta');
                if (it.input.checked !== it.correcta) {
                    ok = false;
                }
                if (it.input.checked) {
                    it.etiqueta.classList.add(it.correcta ? 'es-correcta' : 'es-incorrecta');
                }
            });
            return ok;
        }, function () {
            items.forEach(function (it) {
                it.input.checked = it.correcta;
                it.etiqueta.classList.toggle('es-correcta', it.correcta);
                it.etiqueta.classList.remove('es-incorrecta');
            });
        });
    }

    // --- Respuesta de texto ----------------------------------------------------

    function texto(quiz, encuesta) {
        var input = crear('input', {type: 'text', class: 'lia-respuesta', 'aria-label': 'Tu respuesta'});
        quiz.insertBefore(input, quiz.firstChild);
        if (encuesta) {
            return enviar(quiz);
        }
        var esperada = quiz.getAttribute('data-respuesta') || '';
        acciones(quiz, function () {
            return normalizar(input.value) === normalizar(esperada);
        }, function () {
            input.value = esperada;
        });
    }

    // --- Matriz ----------------------------------------------------------------

    function matriz(quiz) {
        var columnas = (quiz.getAttribute('data-columnas') || '').split('|');
        var tabla = crear('table', {class: 'lia-matriz'});
        var cabecera = crear('tr', {}, [crear('th', {texto: ''})].concat(columnas.map(function (c) {
            return crear('th', {scope: 'col', texto: c});
        })));
        tabla.appendChild(crear('thead', {}, [cabecera]));
        var cuerpo = crear('tbody');
        var filas = hijos(quiz, 'fila').map(function (fila) {
            var nombre = 'lia-q' + (++contador);
            var celda = crear('td');
            while (fila.firstChild) {
                celda.appendChild(fila.firstChild);
            }
            var tr = crear('tr', {}, [celda]);
            var inputs = columnas.map(function (c) {
                var input = crear('input', {type: 'radio', name: nombre, 'aria-label': c});
                tr.appendChild(crear('td', {}, [input]));
                return input;
            });
            cuerpo.appendChild(tr);
            fila.remove();
            return {tr: tr, inputs: inputs, correcta: parseInt(fila.getAttribute('data-correcta'), 10)};
        });
        tabla.appendChild(cuerpo);
        quiz.insertBefore(tabla, quiz.firstChild);
        acciones(quiz, function () {
            var ok = true;
            filas.forEach(function (f) {
                var marcada = f.inputs.findIndex(function (i) {
                    return i.checked;
                });
                f.tr.classList.toggle('es-correcta', marcada === f.correcta);
                f.tr.classList.toggle('es-incorrecta', marcada !== -1 && marcada !== f.correcta);
                if (marcada !== f.correcta) {
                    ok = false;
                }
            });
            return ok;
        }, function () {
            filas.forEach(function (f) {
                f.inputs.forEach(function (i, k) {
                    i.checked = k === f.correcta;
                });
                f.tr.classList.add('es-correcta');
                f.tr.classList.remove('es-incorrecta');
            });
        });
    }

    // --- Selección en línea ----------------------------------------------------

    function seleccion(span) {
        var opciones = (span.getAttribute('data-opciones') || '').split('|');
        var correcta = parseInt(span.getAttribute('data-correcta'), 10);
        var select = crear('select', {class: 'lia-select', 'aria-label': 'Elige la opción correcta'});
        select.appendChild(crear('option', {value: '', texto: '…'}));
        opciones.forEach(function (op, k) {
            select.appendChild(crear('option', {value: String(k), texto: op}));
        });
        select.addEventListener('change', function () {
            var ok = select.value !== '' && parseInt(select.value, 10) === correcta;
            select.classList.toggle('es-correcta', ok);
            select.classList.toggle('es-incorrecta', select.value !== '' && !ok);
        });
        span.replaceWith(select);
    }

    // --- Encuestas (no se guarda nada) -------------------------------------------

    function enviar(encuesta) {
        var zona = crear('div', {class: 'lia-acciones'});
        var aviso = crear('p', {class: 'lia-feedback', role: 'status', 'aria-live': 'polite'});
        var btn = boton('Enviar', true);
        btn.addEventListener('click', function () {
            aviso.className = 'lia-feedback ok';
            aviso.textContent = 'Gracias. Tu respuesta no se guarda: es solo para reflexionar.';
        });
        zona.appendChild(btn);
        encuesta.appendChild(zona);
        encuesta.appendChild(aviso);
    }

    // --- Índice: página actual y menú en pantallas estrechas ----------------------

    function indice() {
        var actual = location.pathname.split('/').pop() || 'index.html';
        document.querySelectorAll('.lia-indice a').forEach(function (a) {
            if ((a.getAttribute('href') || '').split('#')[0] === actual) {
                a.classList.add('actual');
                a.setAttribute('aria-current', 'page');
            }
        });
        var botonMenu = document.querySelector('.lia-menu-boton');
        var nav = document.getElementById('lia-indice');
        if (botonMenu && nav) {
            botonMenu.addEventListener('click', function () {
                var abierto = nav.classList.toggle('abierto');
                botonMenu.setAttribute('aria-expanded', String(abierto));
            });
        }
    }

    function iniciar() {
        document.querySelectorAll('.quiz').forEach(function (q) {
            var tipo = q.getAttribute('data-tipo');
            if (tipo === 'single' || tipo === 'multiple') {
                opciones(q, tipo === 'multiple', false);
            } else if (tipo === 'text') {
                texto(q, false);
            } else if (tipo === 'matrix') {
                matriz(q);
            }
        });
        document.querySelectorAll('.encuesta').forEach(function (e) {
            var tipo = e.getAttribute('data-tipo');
            if (tipo === 'text') {
                texto(e, true);
            } else {
                opciones(e, tipo === 'multiple', true);
            }
        });
        document.querySelectorAll('.quiz-select').forEach(seleccion);
        indice();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
