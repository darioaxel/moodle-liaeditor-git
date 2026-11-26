# Plantilla FP Virtual para el PDF de contenido

Copia de `fpvirtual-theme/` del repositorio `fpvirtual-markdown-a-pdf`
(commit 2f39291), para que el engine genere el PDF de contenido
sin depender de ese repositorio. **No editar aquí**: los cambios se hacen en
`fpvirtual-markdown-a-pdf` y se vuelven a copiar.

Se puede usar otra copia con `MATERIALES_LIASCRIPT_PDF_THEME_DIR`.

## Cambios locales (Materiales Editables)

Esta copia **sí** tiene cambios propios en `fpvirtual.latex`, que habría que
llevar a `fpvirtual-markdown-a-pdf` (o mantener al volver a copiar):

- Portada: logo grande (85 mm) centrado arriba y, debajo, en un solo bloque
  apilado, el título (módulo), el subtítulo (unidad), el autor y la fecha
  de publicación (`date`). Antes los textos tenían posiciones fijas y un
  título largo se montaba sobre el subtítulo; no había fecha.
- Licencia: macro `\fpvlicencia`; con índice (`toc`) va debajo de él, en su
  misma página; sin índice, donde estaba (tras la portada).

Las cabeceras (unidad a la izquierda, autor a la derecha) no tocan la
plantilla: las pone `contenido.lua` en `header-left`/`header-right`.
