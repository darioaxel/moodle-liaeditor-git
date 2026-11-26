"""Tema visual de los contenidos exportados.

Los contenidos publicados (H5P, PDF, ePub) los renderiza LiaScript.
Para que se vean como el editor, el engine añade al exportar una hoja CSS
generada desde los temas del editor (``fpvirtual-crepe-editor``,
``npm run theme:liascript``) y guardada en ``publish/themes/<tema>.css``.

- H5P y PDF: se añade ``link: <hoja>`` a la cabecera del material en la
  copia temporal (nunca en Git) y se copia la hoja junto al material; liaex la
  incrusta (H5P) o la aplica al renderizar (PDF).
- ePub: liaex ignora ``link:``, así que la hoja se añade a las hojas de
  estilo del ePub ya generado.
"""

from __future__ import annotations

import io
import re
import zipfile
from importlib import resources

#: Nombre de la hoja junto al material en la copia temporal.
THEME_FILENAME = "liamat-tema.css"

_HEADER_RE = re.compile(r"^(\s*<!--)(.*?)(-->)", re.DOTALL)


def load_theme_css(name: str) -> str | None:
    """CSS del tema ``name`` empaquetado con el engine; ``None`` si es ``none``.

    Raises:
        ValueError: si el tema no existe.
    """
    if name in ("", "none"):
        return None
    if not re.fullmatch(r"[a-z0-9-]+", name):
        msg = f"nombre de tema no válido: {name!r}"
        raise ValueError(msg)
    resource = resources.files("materiales_liascript.publish.themes").joinpath(f"{name}.css")
    if not resource.is_file():
        msg = f"tema desconocido: {name!r} (no existe publish/themes/{name}.css)"
        raise ValueError(msg)
    return resource.read_text(encoding="utf-8")


def add_stylesheet_link(markdown: str, href: str) -> str:
    """Añade ``link: <href>`` a la cabecera LiaScript (o crea una cabecera)."""
    line = f"link:     {href}\n"
    match = _HEADER_RE.match(markdown)
    if not match:
        return f"<!--\n{line}-->\n\n{markdown}"
    body = match.group(2)
    separator = "" if body.endswith("\n") else "\n"
    return markdown[: match.start(3)] + separator + line + markdown[match.start(3) :]


def append_css_to_epub(epub: bytes, css: str) -> bytes:
    """Añade ``css`` al final de cada hoja de estilo del ePub.

    Conserva el orden y la compresión de cada entrada: el ePub exige que
    ``mimetype`` sea la primera y vaya sin comprimir.
    """
    source = zipfile.ZipFile(io.BytesIO(epub))
    output = io.BytesIO()
    with zipfile.ZipFile(output, "w") as target:
        for info in source.infolist():
            data = source.read(info)
            if info.filename.lower().endswith(".css"):
                data = data + b"\n\n" + css.encode("utf-8")
            target.writestr(info, data)
    return output.getvalue()
