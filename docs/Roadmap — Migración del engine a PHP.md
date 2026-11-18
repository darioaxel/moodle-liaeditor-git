# Roadmap — Migración del engine a PHP (análisis de coste)

**Versión**: 1.0
**Fecha**: 2026-11-18
**Estado**: Documento de análisis — no es una decisión tomada
**Contexto**: surgida la pregunta de si el engine podría vivir dentro del
plugin Moodle (instalación de "un solo paquete"), este documento evalúa
qué supondría revertir la decisión **D2** («la lógica Git vive en el
engine») y reescribir el engine Python en PHP dentro del plugin.
**Documentos relacionados**: `desarrollo-liaeditor-moodle-v1.md` (D1–D10),
`Tasklist — Desarrollo LiaEditor–Moodle v1.md`.

---

## 1. Punto de partida: qué se tendría que reescribir

El engine actual (datos reales del repo):

| Métrica | Valor |
|---|---|
| Código fuente (`engine/src/`) | ~7.400 líneas |
| Tests (`engine/tests/`) | ~5.600 líneas, **343 tests** en verde |
| Endpoints HTTP | 20 (15 GET + 5 POST) |
| Módulos | 11 paquetes |

Desglose por módulo y su destino en un mundo "todo PHP":

| Módulo engine | LOC | Qué hace | Destino en PHP |
|---|---|---|---|
| `edit/` | 388 | Flujo LiaEditor: ramas, save, conflictos, estado, listado | Clases del plugin (`local\edit\*`) — **imprescindible** |
| `git/` | 575 | Provider Git: lectura (worktrees, tags, diffs) y escritura (plumbing puro con CAS) | Cliente Git en PHP — **el punto más crítico** |
| `moodle/` | 918 | Cliente REST del plugin + fake de contrato | **Desaparece**: el plugin ya es Moodle (llamadas internas) |
| `deployment/` | 1.299 | Planner/executor mod_book, estados, export, caché | Legado: o se porta o se retira con el flujo Book |
| `content/` | 657 | Renderer Markdown→HTML + temas + sanitización | Legado Book: LiaScript renderiza en cliente |
| `catalog/` | 246 | Catálogo de Books legacy | Legado Book |
| `manifests/` | 506 | course.yml/book.yml + validación | Legado Book |
| `releases/` | 436 | Severidad, recall, auditoría SQLite | Tablas Moodle (o se retira) |
| `api/` | 473 | Routers FastAPI (deploy, edit, releases, hooks) | Páginas/endpoints del plugin |
| `web/` | 618 | App FastAPI + preview HTML | Páginas del plugin |
| `cli/` | 573 | CLI Typer (validate, deploy, release…) | Scripts `cli/` del plugin (o nada) |

**Lectura clave**: de las ~7.400 líneas, el flujo nuevo LiaEditor solo
necesita `edit/` + `git/` (**~1.000 líneas**). Las otras ~6.400 son el
sistema legacy `mod_book` (que la propia v1 marca como candidato a retirar)
o capas que Moodle ya da (cliente REST de sí mismo, persistencia, cron).

---

## 2. Qué aporta Moodle gratis (y hoy duplicamos)

Portar a PHP no es reescribir el 100%: la plataforma cubre lo que el
engine tuvo que construir:

- **Persistencia**: `mdl_*` + `$DB` sustituyen SQLModel/SQLite
  (`releases/`).
- **Autenticación y ACL**: capabilities y sesiones sustituyen los headers
  y tokens del API REST entre plugin y engine.
- **Colas y cron**: adhoc tasks y scheduled tasks sustituyen a los
  procesos auxiliares (liaex como tarea programada, limpieza de sesiones).
- **Configuración**: `config_plugins` sustituye pydantic-settings.
- **Logging**: sistema de logs de Moodle (o `error_log`) sustituye
  structlog.
- **Desaparece el salto HTTP plugin→engine**: sin CORS, sin
  `curlsecurityblockedhosts`, sin `MOODLE_CONNECT_URL`, sin forwarders.
  Es la ganancia real de la migración.

---

## 3. El coste real: lo que hay que reconstruir

### 3.1 Cliente Git en PHP (el riesgo principal)

El corazón de D2 es un cliente Git serio: worktrees, lectura por
`ls-tree`/blobs, tags, diffs, merge-base, y sobre todo la **escritura por
plumbing puro** (`hash-object` + `mktree` recursivo + `commit-tree` +
`update-ref` con compare-and-swap) que implementamos para no tocar el
working tree.

- No existe una librería PHP madura equivalente a GitPython
  (`czproject/git-php` y similares son wrappers del CLI sin API de
  plumbing ni tests al nivel necesario).
- Camino realista: **wrapper propio del CLI de `git`** (igual que hace
  `LocalGitProvider` para worktrees y escritura). Es portable (git está en
  cualquier servidor Moodle de FPVirtual), pero hay que reescribir y
  retestar ~575 LOC de lectura/escritura con sus ~70 tests, incluida la
  semántica CAS de `update-ref` y el árbol recursivo de `mktree`.
- El repo de contenidos pasaría a vivir en `$CFG->dataroot` gestionado por
  el plugin, con el token de Git en `config_plugins` (justo lo que la spec
  original `mod_liamd` proponía y D2 descartó).

### 3.2 Contrato con el editor (se conserva)

El fork LiaEdit habla con `content.php`/`save.php`/`token.php` —
**URLs del plugin que ya existen**. El editor no notaría la migración: el
contrato `liascript-lms` se mantiene. Es la única parte que no cambia.

### 3.3 Suite de tests

343 tests pytest → PHPUnit/Moodle testing (PHPUnit + `advanced_testcase`).
Moodle exige su propio harness (BD de pruebas, generadores), más lento de
escribir y de correr. Estimación honesta: rehacer la cobertura del flujo
de edición son ~2–3 semanas de trabajo de test, no de código.

### 3.4 Tipado y tooling

pydantic/mypy-strict → PHP 8 `readonly` DTOs + PHPStan nivel alto. El
proyecto no tiene hoy tooling PHP configurado (phpcs, PHPStan, PHPUnit):
hay que montarlo.

---

## 4. Roadmap estimado (si se decidiera migrar)

Convención: **S** < 1 semana · **M** 1–3 semanas · **L** 3–5 semanas.

### Fase P0 — Decisión y alcance (S)

- Decidir el destino del **legado mod_book**: retirarlo (recomendado si se
  migra: ningún curso de producción lo usa) o mantenerlo congelado en el
  engine Python hasta su muerte. Esta decisión mueve el total de **~2
  meses** a **~3–4 meses**.
- Congelar features en el engine Python durante la migración.

### Fase P1 — Fundaciones PHP (M)

- Tooling del plugin: PHPUnit, PHPStan, phpcs, CI en GitHub Actions.
- `local\git\git_client` (wrapper CLI): lectura completa (refs, worktrees,
  `ls-tree`, blobs, tags, diff, merge-base, ahead/ancestor).
- Repo de contenidos en `dataroot` + token en `config_plugins` + clon
  inicial desde la página de settings.

**DoD**: lectura Git en PHP con tests de contrato equivalentes a los del
engine (fixture Git idéntico).

### Fase P2 — Escritura y flujo de edición (M–L)

- Escritura plumbing: `commit_file` con árbol recursivo `mktree` y CAS de
  `update-ref`; `create_branch`, `push`.
- Clases `local\edit\*`: ramas `edicion/*`, save con conflicto
  `resolucion/*`, estado por SHA, listado de materiales — port directo de
  `edit/service.py` (388 LOC, la parte mejor acotada).
- Re-cablear `content.php`/`save.php`/`token.php` de llamadas HTTP al
  engine → llamadas internas a las clases.

**DoD**: el E2E actual (open → save → push → 409 → state) pasa sin
engine corriendo. Editor sin cambios.

### Fase P3 — Equivalencia funcional (M)

- Publicación server-side (`liaex` como adhoc task llamando a Docker,
  que era LIA-040/041 del engine).
- Sesiones, tokens y panel — ya son PHP; solo cambian su fuente de datos.
- Migración de datos: nada que migrar (los SHAs viven en Git; las tablas
  `ldeploy`/`editsess`/`token` ya son del plugin).

**DoD**: Fase 1 y Fase 2 de la tasklist v1 funcionando sin engine.

### Fase P4 — Retirada del engine (S)

- Apagar el contenedor, archivar `engine/` en el repo (o extraerlo a repo
  histórico), reescribir Guía de operación.
- Marcar D2 como **revertida** con fecha y motivo en la v1.

### Estimación total

| Escenario | Estimación |
|---|---|
| Solo flujo LiaEditor (legado Book retirado) | **6–8 semanas** de una persona |
| Incluyendo port del legado Book completo | **12–16 semanas** |

---

## 5. Qué se gana y qué se pierde

**Se gana:**

- Instalación de un solo paquete: el plugin lo es todo.
- Cero red interna: sin CORS server-to-server, sin bloqueos cURL de
  Moodle, sin contenedor que mantener, sin `MOODLE_CONNECT_URL`.
- Menos superficie operativa: un runtime, un ciclo de despliegue.

**Se pierde:**

- **2+ meses de trabajo nuevo** para volver al punto funcional actual
  (el "80% del año" no se tira: se reescribe).
- La suite de 343 tests y su velocidad (PHPUnit+Moodle es más pesado).
- La independencia del engine: hoy el mismo servicio sirve a varios
  Moodles y se testea sin Moodle (fake + TestClient). En PHP, todo test de
  negocio necesita el harness de Moodle.
- La posibilidad de que terceros usen el motor fuera de Moodle (la API es
  hoy LMS-agnóstica; el fork del editor ya es genérico).
- D2 como barrera de complejidad: el plugin volvería a ser "listo"
  (lógica Git + credenciales + UI), justo lo que la arquitectura quiso
  evitar.

**Riesgos añadidos:**

- Reescritura del plumbing Git en PHP: es el código más delicado del
  sistema (un bug en `mktree`/`update-ref` corrompe el repo de contenidos,
  que es la fuente de verdad).
- `exec()` de git desde PHP: algunos hostings lo deshabilitan; exige
  control sobre el servidor Moodle.
- Ventana de migración con dos sistemas vivos.

---

## 6. Recomendación

1. **A corto plazo (ahora): no migrar.** El sistema está desplegado y el
   E2E verificado; el coste operativo real del engine es bajo (un
   contenedor más en un servidor que ya corre diez). La fricción de
   instalación se resuelve mejor con el script `setup_engine.php` del
   punto anterior y el compose de producción.
2. **Revisar la decisión cuando ocurra alguna de estas dos cosas**:
   - el legado `mod_book` se retire oficialmente (el engine perdería el
     80% de su código y la balanza cambiaría), o
   - el despliegue en producción demuestre costes operativos reales del
     segundo servicio (no los hipotéticos).
3. Si se migra, hacerlo **solo del flujo LiaEditor** (fases P0–P4 sobre
   `edit/` + `git/`), nunca portando el legado Book: 6–8 semanas acotadas
   en vez de un trimestre.
