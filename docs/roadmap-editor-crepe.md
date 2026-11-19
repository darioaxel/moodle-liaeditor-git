# Roadmap — Editor Crepe/Milkdown sustituyendo a LiaEdit (LiveEditor)

**Versión**: 0.3.1-draft
**Fecha**: 2026-11-18
**Estado**: Fase 0 (PoC) hecha el 2026-11-19 — **go condicionado** (ver §9)
**Documentos base**:

- `docs/prompts/cambio_milkdown-crepe.md` (briefing de la migración;
  sus afirmaciones sobre el sistema existente se toman como hipótesis
  a verificar, no como hechos)
- `docs/desarrollo-liaeditor-moodle-v1.md` (decisiones D1–D10, vigentes)
- `docs/roadmap-liveeditor-fork.md` (fork actual, que este roadmap retira)

**Decisiones tomadas (2026-11-18):**

| # | Decisión |
|---|----------|
| R1 | Alcance: **solo la capa de presentación-edición**. Se mantiene Markdown LiaScript como formato y todo el backend (plugin, engine, Git, liaex) |
| R2 | Formatos de exportación: **solo `h5p`, `epub`, `pdf`** (los ya soportados por el engine). SCORM queda descartado |
| R3 | El editor se desarrolla en un **repo propio, [`FPVirtual/fpvirtual-crepe-editor`](https://github.com/FPVirtual/fpvirtual-crepe-editor)** (prompt de desarrollo: `docs/prompts/desarrollo-crepe-editor.md`); este repo mantiene plugin, engine y docs, y sus cambios para el editor van en la rama `feature/editor-crepe` |
| R4 | **Sin preview LiaScript completa**: la vista WYSIWYG del editor es la única preview. Los constructos propios de LiaScript (quizzes, ejecutables) se muestran como tarjetas/placeholders y su comportamiento real solo se comprueba en el artefacto publicado |
| R5 | Colaboración multi-autor: **fuera de alcance** (el modelo de ramas por usuario D4 ya cubre el aislamiento) |
| R6 | Exportador en navegador: el **LiaScript-Exporter es un proyecto independiente del editor** y sigue disponible como flujo manual de respaldo (y como forma opcional de "ver como el alumno" antes de publicar), aunque se retire LiveEditor |
| R7 | Validación LiaScript: **síncrona al guardar** — el botón Guardar "prueba en servidor" (valida con liaex) y solo entonces se permite el commit; con indicador de espera en la UI. Es la única verificación de nivel LiaScript antes de publicar (ver R4) |

---

## 1. Alcance y principio rector

**Se sustituye la capa de presentación-edición** (fork
`FPVirtual/LiveEditor`) por un editor propio basado en
**Crepe/Milkdown**, servido como aplicación web online (subdominio
propio, como hoy LiaEdit self-hosted).

**No se sustituye nada más**: la persistencia sigue siendo Markdown
LiaScript en Git, el pipeline de exportación sigue siendo `liaex`
(engine, Docker) y las decisiones D1–D10 siguen vigentes; solo cambia
D8/D10 (el editor ya no es un fork de LiveEditor, sino una app propia
en el repo `fpvirtual-crepe-editor`).

## 2. Qué se conserva, qué se reutiliza y qué se retira

**Se conserva sin cambios funcionales:**

- Plugin Moodle: `content.php` (proxy + token + bloque `liascript-lms`),
  `save.php` (fachada D3/D5), `token.php` (renovación), `publish.php` /
  `publish_api.php`, tablas de sesiones y despliegues, capacidades.
- Engine: `/edit/open`, `/edit/save` (ramas D4, conflictos D5), cliente
  Git, catálogo, `/publish` con `liaex` (ya genera `h5p`, `epub`, `pdf`).
- Contrato editor↔plugin ya verificado (body de save, 200/409, refresh
  de token): el editor nuevo es un **cliente más del mismo contrato**.

**Se reutiliza como diseño (no como código):**

- `LmsBridge`, modal de conflicto 409, renovación de token, bloque
  `liascript-lms`: la lógica se porta al nuevo editor; el código Vue del
  fork se descarta con él.

**Se retira:**

- Fork `FPVirtual/LiveEditor` (C1–C10) y su CI/deploy. Plan de retirada
  en Fase 5: archivar repo, mantener desplegada la última versión hasta
  completar la migración, redirigir `editor_url` del plugin.
- El flujo "exportar en navegador desde LiveEditor": se sustituye por
  el LiaScript-Exporter standalone (R6) como respaldo manual, y por la
  publicación server-side (ya existente) como vía principal.

**Se crea (en el repo propio `crepe-editor`; en este repo solo los cambios de backend):**

- `crepe-editor`: app Vue 3 + Vite + TypeScript con Crepe/Milkdown,
  desplegada como estático tras Nginx/HTTPS en subdominio propio
  (desarrollo según `docs/prompts/desarrollo-crepe-editor.md`).
- Plugin: `upload.php` (assets) y `asset.php` (servir imágenes del
  working copy al editor), nuevos.
- Engine: endpoint de validación LiaScript (nuevo, R7).

## 3. Arquitectura resultante

```
┌──────────────────────────── MOODLE ────────────────────────────┐
│  plugin local_liamateriales (sin cambios funcionales)          │
│  edit.php → content.php · save.php · token.php · publish.php   │
│  + upload.php (nuevo, assets)  + asset.php (imágenes, nuevo)   │
└──────────────────────────────┬─────────────────────────────────┘
                               │ server-to-server (existente)
┌──────────────────────────────▼─────────────────────────────────┐
│  ENGINE: /edit/* · /publish (liaex Docker: h5p/epub/pdf)       │
│  + /edit/validate (nuevo: validación LiaScript síncrona        │
│    pre-commit, R7)                                             │
└──────────────────────────────┬─────────────────────────────────┘
                               │ HTTPS API Git
┌──────────────────────────────▼─────────────────────────────────┐
│  GIT — única fuente de verdad                                  │
└────────────────────────────────────────────────────────────────┘
                               ▲ token de un solo uso (D9)
┌──────────────────────────────┴─────────────────────────────────┐
│  EDITOR CREPE (repo fpvirtual-crepe-editor, app estática)      │
│  Crepe/Milkdown (ProseMirror + remark) · nodos LiaScript       │
│  LmsBridge (save/409/token) · panel de cabecera · modo fuente  │
│  Sin preview LiaScript: WYSIWYG + tarjetas/placeholders (R4)   │
└────────────────────────────────────────────────────────────────┘
```

**Política de visualización en el editor (R4):**

- **Presentacionales** (cajas `@nota/@aviso/@error`, Mermaid, LaTeX,
  imágenes): renderizados por el propio editor (Crepe ya trae KaTeX,
  código resaltado, bloques de imagen; Mermaid y cajas como nodeViews
  propios). Aspecto aproximado al final, sin runtime LiaScript.
- **Paramétricos** (quizzes de todos los tipos, macros con parámetros):
  tarjeta resumen + formulario de edición. No se ejecutan nunca en el
  editor.
- **Ejecutables** (SQLite/Pyodide, código con flags, Vega-Lite, llamadas
  a LLM): tarjeta placeholder de solo lectura con icono y parámetros
  clave.
- **Fallback**: macro no reconocida → bloque atómico de solo lectura
  con su fuente Markdown intacta; nunca se reescribe ni se descarta.
- El comportamiento real de quizzes y ejecutables **solo se comprueba
  en el artefacto publicado** (o, opcionalmente, generando el artefacto
  con el exporter standalone, R6). La validación síncrona al guardar
  (R7) garantiza que lo que entra en Git siempre compila con LiaScript.

## 4. Roadmap por fases

### Fase 0 — PoC y decisión go/no-go

Objetivo: demostrar que el round-trip del dialecto LiaScript es viable
antes de invertir en el editor completo.

1. Scaffold del repo `crepe-editor` (Vue 3 + Vite + TS), Crepe mínimo,
   bundle medido.
2. Un nodo atómico de quiz (`[[?]]`) con remark parse + ProseMirror
   node + stringifier propios, mostrado como tarjeta (R4).
3. Bloque fallback genérico (macro desconocida → solo lectura, fuente
   intacta).
4. Corpus de fixtures: `.md` reales del repo de cursos (incluir casos
   extremos: quizzes con parámetros, `{{n}}` efectos, cajas, imports,
   comentarios TTS). Harness: `parse → serializar → diff` y, para el
   subconjunto ejecutable, `liaex` sobre antes/después comparando el
   render.
5. Spike del validador: coste real de invocar liaex para validar un
   documento (frío vs. contenedor warm), que condiciona la UX de R7.

**Criterios de salida (go/no-go, cuantitativos):**

- ≥ 95 % de los fixtures sobreviven `parse → serializar` sin diff
  semántico (y 100 % de los constructos cubiertos por nodos propios o
  fallback; nada se pierde silenciosamente).
- `liaex` acepta el markdown serializado de todos los fixtures y el
  render es equivalente.
- Validación con liaex < ~3 s por documento típico (viable para R7).
- Bundle inicial y tiempo de carga aceptables con un tema real de 50+
  páginas (medir, no estimar).

Si no se cumplen: no seguir; el fork actual sigue siendo el editor.

### Fase 1 — Core de edición (MVP guardable)

1. Edición de prosa con Crepe recortada a lo compatible con LiaScript
   (texto, encabezados, listas, tablas, enlaces, código, LaTeX; HTML
   embebido libre desactivado).
2. Panel de cabecera (formulario) que regenera el bloque
   `<!-- import: …, título, autor, idioma, voz -->` al guardar; imports
   con versión fijada.
3. Port de `LmsBridge` (save/200/409/refresh token), modal de conflicto
   y flujo de guardado con validación síncrona (R7): Guardar → spinner
   → validación en engine → commit o error con línea.
4. Modo fuente (Monaco), solo lectura por defecto; editable con
   capacidad avanzada; validación al volver al modo visual.
5. Recuperación ante cierre/crash: snapshot local (IndexedDB) con
   aviso de "borrador no guardado".
6. Plugin: setting `editor_url` apuntando al servicio nuevo y flag por
   instancia para elegir editor durante la transición.

**Criterio de salida**: ciclo completo editar → guardar (con validación
pre-commit) → conflicto → resolver, contra Moodle de pruebas, sin tocar
sintaxis LiaScript avanzada (las macros se ven como bloques fallback).

### Fase 2 — Nodos atómicos LiaScript

1. Quizzes: `[[?]]`, `[(...)]`, `[[...]]`, matrices y variantes con
   parámetros — tarjeta resumen + formulario de edición.
2. Cajas `@nota/@aviso/@error` renderizadas en el editor. Mermaid con
   editor de código + render del diagrama.
3. Bloques ejecutables (SQLite/Pyodide, código con flags, Vega-Lite,
   llamadas a LLM): tarjeta placeholder de solo lectura.
4. Clasificación de todas las macros de los templates en uso
   (presentacional / paramétrica / ejecutable), documentada; regla de
   fallback verificada por tests.

**Criterio de salida**: un profesor crea una unidad con quiz, caja y
diagrama sin escribir sintaxis; el artefacto publicado ejecuta los
quizzes correctamente.

### Fase 3 — Gestión de assets

1. `upload.php` (draft file area, MIME allowlist, límite de tamaño,
   redimensionado a 1920 px, recompresión).
2. `save.php`: mover imágenes referenciadas a `assets/<hash>.<ext>`,
   reescribir URLs temporales a rutas relativas, commit único `.md` +
   assets (cambio en `/edit/save` del engine: aceptar manifiesto de
   assets y commitear binarios).
3. `asset.php` (o endpoint del engine) para que el editor resuelva las
   rutas relativas `assets/...` contra la copia de trabajo, con token
   (las imágenes se ven en la edición, no solo tras publicar).
4. Cron de purga de temporales huérfanos; marcado de assets sin
   referencia para borrado en el siguiente commit.

**Criterio de salida**: pegar/subir una imagen, guardar, verla en el
editor y en el artefacto exportado; `git log` muestra un único commit
por guardado.

### Fase 4 — Publicación

1. Botón Publicar en el editor (equivale a LIV-6, que nunca se hizo en
   el fork): `publish.php {file, sha, format, token}` → engine →
   `liaex` → artefacto → creación del módulo y registro de despliegue.
   Solo `h5p`, `epub`, `pdf` (R2), que el engine ya soporta.
2. Verificar que el H5P del exporter encaja con `mod_h5pactivity`
   (cuestión abierta #3 de la v1).
3. Flujo manual de respaldo con el LiaScript-Exporter standalone (R6):
   enlace desde la página Publicar del plugin para generar el artefacto
   en el navegador (sirve también como verificación "como el alumno"
   de los quizzes antes de publicar, dado R4) y subirlo.
4. i18n ES completo, revisión de accesibilidad (teclado, lector de
   pantalla), pruebas de rendimiento con temas largos.

**Criterio de salida**: publicación en un clic a `mod_h5pactivity` y
recursos `epub`/`pdf` con registro curso↔SHA↔cmid, desde un documento
creado íntegramente en el editor nuevo.

### Fase 5 — Producción y retirada del fork

- Monitorización, limpieza de sesiones (ya existe en el plugin),
  documentación de autoría actualizada (la Guía de autor deja de
  exigir sintaxis Markdown; se documenta que la comprobación de
  quizzes/ejecutables se hace en el artefacto, R4).
- Archivo del repo del fork, retirada del despliegue de LiaEdit,
  `editor_url` definitivo.
- Merge de los cambios de backend de la rama de trabajo a `main` (el
  repo `crepe-editor` sigue su ciclo propio de releases y despliegue).
- (Opcional, posterior) IA de autoría de Crepe contra proxy same-origin
  del engine hacia LLM local — nunca fetch directo del navegador.

## 5. Dependencias con el trabajo ya hecho

| Necesidad | Estado | Impacto |
|---|---|---|
| `content.php` + token + bloque `liascript-lms` | ✅ hecho | Ninguno: el editor nuevo lo consume igual |
| `save.php` + `/edit/save` + conflictos D5 | ✅ hecho | Ampliar para assets (Fase 3) y validación (R7, Fase 1) |
| `token.php` renovación | ✅ hecho | Ninguno |
| `publish.php` + `/publish` (liaex h5p/epub/pdf) | ✅ hecho | Ninguno: los formatos pedidos (R2) ya están |
| Fork LiveEditor LIV-1…LIV-5 | ✅ hecho | Se retira en Fase 5; su lógica LMS se porta |
| LIV-6 (botón Publicar) | ⬜ pendiente | Se absorbe en Fase 4 del nuevo editor |

## 6. Problemas previstos y mitigaciones

- **P1 — Fidelidad de round-trip (riesgo principal).** LiaScript no es
  CommonMark: quizzes, macros `@x(p)`, efectos `{{n}}`, comentarios de
  narración, cabecera con imports. Todo lo no mapeado a un nodo propio
  depende del fallback; y aun con fallback, el stringifier de remark
  puede normalizar listas, encabezados o tablas generando diffs ruidosos
  en Git. *Mitigación*: corpus de fixtures reales + tests de
  idempotencia (`serialize(parse(x)) == x` en docs no editados) +
  comparación de render con liaex en CI (Fase 0). Presupuestar
  mantenimiento continuo de los stringifiers.
- **P2 — La cabecera es un comentario HTML.** remark la parsea como
  nodo `html`; hay que interceptarla antes del parse, mantenerla fuera
  del documento ProseMirror y regenerarla al guardar. Si se escapa,
  aparece como HTML embebido (prohibido por el brief).
- **P3 — Errores de quizzes/ejecutables se descubren tarde (R4).** Sin
  preview LiaScript, el primer render real de un quiz ocurre en el
  artefacto. *Mitigación*: la validación síncrona R7 asegura que todo
  lo commiteado compila; el formulario de edición de quizzes valida
  campos obligatorios (enunciado, opciones, correcta) en el cliente;
  y el exporter standalone (R6) queda como vía opcional de inspección
  visual antes de publicar. Se acepta el riesgo residual de errores
  "de comportamiento" (no de sintaxis) descubiertos al publicar.
- **P4 — Latencia de la validación síncrona (R7).** El botón Guardar
  valida con liaex antes del commit; el coste de una invocación Docker
  fría son segundos. *Mitigación*: contenedor validador warm en el
  engine (proceso persistente que acepta documentos), límite de
  tamaño, timeout con mensaje claro y, como degradación, opción
  "guardar sin validar" para usuarios con capacidad avanzada. El spike
  de Fase 0 mide si es viable (< ~3 s).
- **P5 — Recuperación ante crash.** La app nueva necesita su propio
  snapshot local (IndexedDB) y flujo de "recuperar borrador"; el
  LiveEditor lo daba gratis vía proyectos Yjs.
- **P6 — Bundle y rendimiento.** Crepe + KaTeX + Mermaid + resaltado +
  nodeViews atómicos por cada macro: medir bundle y tiempo con temas
  de 50+ páginas en la PoC; prever lazy-loading de features (Mermaid,
  KaTeX) y evaluar virtualización si los nodeViews degradan.
- **P7 — Deuda de mantenimiento del dialecto.** Los nodos
  remark/ProseMirror propios quedan acoplados a versiones de
  Milkdown/Crepe (proyecto joven, API móvil). El fork actual también
  exigía rebase, pero eran 3 puntos de contacto; aquí es toda la capa
  de edición. *Mitigación*: fijar versiones, suite de round-trip como
  red de seguridad en cada upgrade, fallback genérico que garantiza
  "nunca perder sintaxis".
- **P8 — Seguridad.** Editor en iframe sandboxed, CSP del servicio
  nuevo, y assets servidos con token (mismo patrón D9 que
  `content.php`).

## 7. Exportaciones a los diversos formatos

**Formatos en alcance (R2): `h5p`, `epub`, `pdf`.** El mecanismo no
cambia: todo lo produce `liaex` (LiaScript-Exporter) server-side en el
engine, sobre el checkout Git del SHA publicado:

| Formato | Vía | Estado en el engine | Destino en Moodle |
|---|---|---|---|
| H5P | `liaex --format h5p` | ✅ soportado | `mod_h5pactivity` (verificar tracking, cuestión abierta #3) |
| ePub | `liaex --format epub` | ✅ soportado | recurso archivo |
| PDF | `liaex --format pdf` (Chromium) | ✅ soportado | recurso archivo (contenedor con `--shm-size=1g`, ya documentado) |

Flujo principal (un clic): botón Publicar del editor →
`publish.php {file, sha, format, token}` → engine clona/checkout del SHA
→ liaex en Docker → artefacto → el plugin crea/actualiza el módulo y
registra `courseid ↔ file ↔ sha ↔ cmid`. La trazabilidad D6 se mantiene
idéntica.

Flujo manual de respaldo (R6): el **LiaScript-Exporter es un proyecto
independiente del LiveEditor** (`github.com/LiaScript/LiaScript-Exporter`,
también `@liascript/exporter` en npm). Se le puede entregar el markdown
(del repo vía `content.php` o descargado) y genera el artefacto en el
navegador del profesor, que luego lo sube en la página Publicar del
plugin (flujo ya implementado en Fase 1 de la v1). Este respaldo
**sobrevive a la retirada del fork** porque no depende del editor, y al
no haber preview LiaScript en el editor (R4) dobla como vía opcional de
inspección visual de quizzes y ejecutables antes de publicar.

## 8. Registro de riesgos (resumen)

| Riesgo | Prob. | Impacto | Mitigación |
|---|---|---|---|
| Pérdida/alteración de sintaxis en round-trip | Alta | Alto | Fase 0 con umbrales cuantitativos; fallback; CI de idempotencia |
| Comentarios HTML invisibles (cabecera, narración) | Media | Alto | Interceptar en parse; tests específicos |
| Errores de quizzes/ejecutables descubiertos al publicar (R4) | Media | Medio | Validación R7; formularios con campos obligatorios; exporter standalone (R6) |
| Latencia de validación síncrona al guardar | Media | Medio | Validador warm; spike en Fase 0; degradación controlada |
| Bundle/rendimiento en docs largos | Media | Medio | Medición en PoC; lazy-loading; virtualización |
| Basura de assets en el repo | Media | Medio | Hash por contenido, GC cron, commit único (Fase 3) |
| Acoplamiento a API móvil de Crepe/Milkdown | Media | Alto | Versiones fijadas + suite de round-trip como red |

## 9. Estado — Fase 0 (2026-11-19)

PoC en `fpvirtual-crepe-editor`, rama `poc/m0`. Informe completo en ese
repo: `docs/informe-m0.md`. Recomendación: **go condicionado**.

| Criterio de salida | Resultado |
| --- | --- |
| ≥ 95 % de fixtures sin diff semántico | 8/8 (100 %); además guardar sin editar es idempotente byte a byte |
| 100 % de constructos cubiertos (nada se pierde) | ✅ todo bloque LiaScript llega literal, también tras editar |
| liaex acepta el serializado; render equivalente | 8/8 según la huella `liaex -f fullJson`, en el peor caso (todo re-serializado) |
| Validación con liaex < ~3 s | 0,85–1,3 s en frío, **pero liaex no valida** (ver abajo) |
| Bundle y carga con tema de 50+ páginas | 1,50 MB (472 kB gzip) inicial; tema de 3107 líneas: apertura 1,6 s y guardado ≈ 70 ms (jsdom) |

**Condiciones del go:**

1. **Corpus real.** Hoy hay 3 materiales reales cortos y 5 casos escritos a
   mano. Antes de M1 hacen falta materiales de producción (sobre todo los
   que usan el template de cajas `@nota/@aviso/@error`) y un tema real de
   50+ páginas.
2. **Redefinir R7.** El parser de LiaScript es tolerante: `liaex` termina
   con código 0 incluso con la cabecera sin cerrar (que pierde en silencio),
   quizzes rotos o vallas sin cerrar. No hay errores con línea que devolver
   en un 422. Propuesta: una **comprobación estructural** en el engine
   (~1 s) que compare la huella `fullJson` (secciones, quizzes y su tipo,
   encuestas, tareas, cabecera) con la de la versión anterior. Avisaría de
   desapariciones o cambios de tipo, y bloquearía solo si la cabecera queda
   vacía cuando la fuente empieza por `<!--`.

**Hallazgos para el backend:**

- **liaex necesita red con `import:`**: descarga los templates en cada
  ejecución y falla con `--network none`, que es como lo lanza hoy el
  engine. Publicar un material con imports fallará. Decisión pendiente:
  salida HTTP con lista de dominios permitidos, o resolver los imports antes
  de lanzar liaex.
- **Inyección del bloque `liascript-lms` reversible** (hecho en
  `feature/editor-crepe`): el bloque va justo antes de `-->` sin `rtrim` de
  la cabecera, con `substr_replace` (corrige también el fallo de `$1`/`\`
  en `preg_replace`).
- **Apertura**: el plugin genera `?/open/file/<url>` (la ruta del fork); el
  editor nuevo acepta esa forma y `?url=`, así que basta con cambiar
  `liaeditor_url` para probarlo.
- **Contenido**: en `demo-liascript.md`, «opción única» (`[[( )]]`) se
  interpreta como dos quizzes de texto y «Encuestas» (`[( )]`) es un quiz
  puntuable de opción única.

