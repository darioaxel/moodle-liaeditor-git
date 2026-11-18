# Tarea: Migrar la capa de edición de mod_liamd de LiveEditor a Crepe/Milkdown

## Objetivo

Sustituir la capa de edición (fork de LiveEditor) por un editor WYSIWYG basado
en **Crepe** (UI completa construida sobre Milkdown: ProseMirror + remark),
manteniendo Markdown LiaScript como formato de persistencia y todo el pipeline
de Git/liaex sin cambios. El profesorado no debe necesitar escribir sintaxis
Markdown para el 90 % del contenido.

## Hechos verificados (2026-11) sobre los candidatos

- Crepe depende de Vue 3 (encaja con el stack del plugin) e incluye de serie:
toolbar flotante, slash commands tipo Notion, tablas, bloques de imagen,
código con resaltado y LaTeX (KaTeX).
- API relevante: `crepe.getMarkdown()` serializa el documento a Markdown;
el listener `markdownUpdated((ctx, markdown, prevMarkdown) => …)` dispara
en cada cambio → hook para el autoguardado a `save.php`.
- Crepe permite activar/desactivar features por configuración (recortar a lo
que el pipeline liaex soporta).
- Crepe ≥7.21 incluye una feature de IA opt-in (paleta de instrucciones,
streaming, panel diff aceptar/rechazar) cuyo provider es un
`AsyncIterable<string>` de Markdown → conectable a un proxy same-origin
contra un LLM local (Ollama/LM Studio).
- Milkdown permite definir nodos de schema personalizados (`$node`, incluidos
nodos atómicos) con su propio parse remark y stringifier → mecanismo para
soportar el dialecto LiaScript sin perder sintaxis en el round-trip.
- Descartados: Toast UI Editor (proyecto sin mantenimiento desde 2026) y
MDXEditor (React-only; el plugin es Vue).

## Requisitos funcionales

1. **Edición visual de prosa**: texto, encabezados, listas, tablas, imágenes
(vía flujo de assets descrito abajo), enlaces, código y LaTeX. Features
de Crepe recortadas a lo compatible con LiaScript (sin HTML embebido libre).
2. **Nodos atómicos LiaScript** (widget visual + serialización exacta):

- Quizzes: `[[?]]` opción única, `[(...)]` opción múltiple, `[[...]]`
respuesta de texto, matrices y variantes con parámetros, con formulario
de edición (enunciado, opciones, correcta, pista).
- Macros de caja: `@nota`, `@aviso`, `@error` → cajas de color editables.
- Mermaid: bloque con editor de código + preview del diagrama.
- Código ejecutable LiaScript: bloque con selector de lenguaje y flags.

3. **Cabecera del curso fuera del editor**: el bloque de comentario
`<!-- import: … , título, autor, idioma, voz TTS -->` se gestiona con un
panel de configuración (formulario) y el editor lo regenera al guardar.
Los imports de templates deben fijar versión (nunca master).
4. **Modo fuente**: toggle a vista Monaco (solo lectura por defecto; editable
para usuarios con permiso avanzado).
5. **Pegado**: HTML pegado (web/Word) → conversión a Markdown razonable.
6. **Integración de guardado**: `markdownUpdated` → debounce → POST a
`save.php` con SHA; conflictos → UI de diff merge existente.
7. **Preview**: iframe con LiaScript renderizando el Markdown actual,
actualizado vía postMessage (canal ya existente); panel permanente
sincronizado por scroll con el editor (ver renderizado por niveles).
8. **Publicar**: sin cambios funcionales; mismo botón y pipeline liaex.
9. **i18n**: interfaz en español.

## Renderizado de macros por niveles (obligatorio)

- **Nivel A (presentacionales: cajas, Mermaid, LaTeX)**: renderizado fiel en
el editor, con el mismo aspecto que tendrán en el curso.
- **Nivel B (paramétricas: todos los tipos de quiz, macros con parámetros)**:
tarjeta resumen + formulario de edición; sin ejecución en el editor.
- **Nivel C (ejecutables: SQLite/Pyodide, código, Vega-Lite, llamadas a LLM)**:
tarjeta placeholder de solo lectura con icono del tipo y parámetros clave;
la ejecución ocurre SOLO en el iframe de preview LiaScript (botón
"Ver en preview").
- **Regla de fallback**: toda macro no reconocida se muestra como bloque
atómico de solo lectura con su fuente Markdown intacta; nunca se reescribe
ni se descarta. El editor debe tolerar templates LiaScript futuros sin
actualización.
- **PROHIBIDO** reimplementar el intérprete LiaScript dentro del editor.

## Gestión de imágenes y assets

- Referencias en el Markdown SIEMPRE relativas al repo (`assets/<hash>.ext`);
prohibidas URLs absolutas de Moodle (el SCORM debe ser autocontenido: liaex
clona el repo al exportar).
- Flujo: `onUpload` de Crepe → `upload.php` → área temporal de Moodle (draft
file area) → al guardar (`save.php`), mover las imágenes referenciadas a
`assets/` en el repo + reescribir URLs temporales a rutas relativas →
commit único con `.md` + assets (nunca un commit por imagen).
- Nombre de fichero por hash de contenido (deduplicación, sin colisiones).
- Limpieza: imágenes temporales huérfanas nunca se commitean (cron de purga);
assets del repo que pierden su última referencia se marcan para borrado en
el siguiente commit.
- `upload.php`: límite de tamaño, MIME allowlist, redimensionado (máx 1920px)
y recompresión automática.
- La preview LiaScript resuelve las rutas relativas contra el endpoint del
plugin que sirve la copia de trabajo, de modo que las imágenes se ven igual
en edición, preview y SCORM final.

## Requisitos no funcionales y restricciones

- **Fidelidad de round-trip**: para todo `.md` LiaScript válido,
parse → editar → serializar debe producir Markdown que `liaex` acepte y que
renderice igual. Suite de tests con fixtures reales (fixtures del repo
actual de cursos) y diff automático.
- **Validación pre-commit**: antes del commit a Git, parsear con LiaScript;
si falla, no tocar el repo y mostrar el error al usuario.
- El editor sigue en iframe sandboxed; comunicación solo por postMessage.
- Sin dependencias sin mantenimiento. Evaluar tamaño del bundle.
- Rendimiento aceptable con documentos largos (temas de 50+ páginas).
- El proveedor de IA (si se activa) debe ir contra un proxy same-origin del
servidor (evitar CORS/mixed-content https→http://localhost), nunca fetch
directo desde el navegador.

## Entregables esperados

1. **Análisis de impacto**: qué partes de la spec v0.2 cambian y cuáles se
mantienen (persistencia, ramas, publicación, preview).
2. **Diseño técnico**: arquitectura de componentes Vue, schema de nodos
personalizados (remark parse + ProseMirror node + stringifier) por cada
constructo LiaScript, clasificación nivel A/B/C de las macros existentes,
y estrategia para el bloque fallback.
3. **Diseño del flujo de assets**: endpoints (`upload.php`, cambios en
`save.php`), layout de `assets/` en el repo, política de limpieza.
4. **Estrategia de tests de round-trip** con fixtures.
5. **Plan por fases** con hitos verificables.
6. **PoC inicial**: Crepe mínimo + un nodo atómico de quiz (`[[?]]`) +
verificación automática de que el Markdown serializado lo acepta `liaex`
sin errores y renderiza igual que el original.
7. **Registro de riesgos** con mitigaciones (pérdida de sintaxis, comentarios
HTML invisibles, tamaño de bundle, mantenimiento del stringifier propio,
basura de assets en el repo).

## Fuera de alcance

Administración de Moodle (usuarios, calificaciones), cambios en el sidecar
liaex, y la integración de IA en los cursos publicados (la IA de esta tarea
es solo de autoría, y es opcional en fase posterior).