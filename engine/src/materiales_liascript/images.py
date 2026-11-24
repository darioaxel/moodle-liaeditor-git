"""Imágenes de los materiales (roadmap docs/imagenes).

- Tipos admitidos y detección del tipo **real** por los primeros bytes (el
  nombre o el tipo que declare el cliente no cuentan).
- Rutas de imagen de un material: relativas a su ``.md``, normalizadas y
  dentro de su carpeta.
- Conversión a PNG para el PDF de contenido: XeLaTeX no admite WebP ni GIF.

Lectura (servir al editor) admite PNG, JPEG, GIF y WebP; la subida desde el
editor, solo PNG, JPEG y GIF (el H5P de liaex descarta las WebP y el editor
recodifica a JPEG o PNG). SVG nunca: puede llevar JavaScript.
"""

from __future__ import annotations

import posixpath
import re
from pathlib import Path

#: Extensión → tipo MIME de las imágenes que se pueden leer.
READ_TYPES = {
    ".png": "image/png",
    ".jpg": "image/jpeg",
    ".jpeg": "image/jpeg",
    ".gif": "image/gif",
    ".webp": "image/webp",
}
#: Extensiones que se pueden subir desde el editor.
UPLOAD_EXTENSIONS = frozenset({".png", ".jpg", ".jpeg", ".gif"})
#: Tamaño máximo de una imagen (bytes), al leerla o subirla.
MAX_IMAGE_BYTES = 2 * 1024 * 1024
#: Extensiones que XeLaTeX no admite y se convierten a PNG en el PDF.
PDF_CONVERT = frozenset({".webp", ".gif"})

_SAFE_PATH = re.compile(r"^[A-Za-z0-9._/-]+$")


class ImageError(ValueError):
    """Ruta o contenido de imagen no válido (el motivo, para el log)."""


def sniff(data: bytes) -> str | None:
    """Tipo MIME según la firma de los primeros bytes, o ``None``."""
    if data.startswith(b"\x89PNG\r\n\x1a\n"):
        return "image/png"
    if data.startswith(b"\xff\xd8\xff"):
        return "image/jpeg"
    if data[:6] in (b"GIF87a", b"GIF89a"):
        return "image/gif"
    if data[:4] == b"RIFF" and data[8:12] == b"WEBP":
        return "image/webp"
    return None


def resolve(material: str, ref: str) -> str:
    """Ruta en el repo de la imagen ``ref`` (como se escribe en el Markdown de ``material``).

    Solo rutas relativas, con caracteres seguros, sin salir de la carpeta del
    material y con extensión de imagen.

    Raises:
        ImageError: si la referencia no es una imagen local válida.
    """
    ref = ref.split("#", 1)[0].split("?", 1)[0]
    if not ref or ref.startswith("/") or "\\" in ref or "//" in ref or ":" in ref:
        msg = f"ruta de imagen no local: {ref!r}"
        raise ImageError(msg)
    if not _SAFE_PATH.match(ref) or len(ref) > 200:  # noqa: PLR2004 - límite de longitud
        msg = f"ruta de imagen con caracteres no admitidos: {ref!r}"
        raise ImageError(msg)
    base = posixpath.dirname(material)
    path = posixpath.normpath(posixpath.join(base, ref))
    if path.startswith("../") or path == ".." or (base and not path.startswith(base + "/")):
        msg = f"ruta de imagen fuera de la carpeta del material: {ref!r}"
        raise ImageError(msg)
    if posixpath.splitext(path)[1].lower() not in READ_TYPES:
        msg = f"extensión de imagen no admitida: {ref!r}"
        raise ImageError(msg)
    return path


def check(path: str, data: bytes, *, upload: bool = False) -> str:
    """Comprueba tamaño, extensión y firma; devuelve el tipo MIME.

    Raises:
        ImageError: si no es una imagen admitida o no coincide con su extensión.
    """
    ext = posixpath.splitext(path)[1].lower()
    if upload and ext not in UPLOAD_EXTENSIONS:
        msg = f"formato no admitido para subir: {path!r}"
        raise ImageError(msg)
    if len(data) > MAX_IMAGE_BYTES:
        msg = f"imagen demasiado grande ({len(data)} bytes): {path!r}"
        raise ImageError(msg)
    real = sniff(data)
    if real is None or real != READ_TYPES.get(ext):
        msg = f"el contenido no es {READ_TYPES.get(ext, ext)}: {path!r}"
        raise ImageError(msg)
    return real


def png_copies_for_pdf(folder: Path) -> list[Path]:
    """Crea ``<imagen>.png`` junto a cada WebP o GIF de ``folder`` (recursivo).

    El filtro ``contenido.lua`` usa esa copia en el PDF. Las imágenes que no se
    pueden abrir se dejan como están (el PDF mostrará el error de Pandoc).
    """
    from PIL import Image  # noqa: PLC0415 - solo al generar el PDF

    Image.MAX_IMAGE_PIXELS = 40_000_000  # frente a imágenes «bomba»
    created: list[Path] = []
    for src in folder.rglob("*"):
        if src.suffix.lower() not in PDF_CONVERT or any(
            p.startswith(".") for p in src.relative_to(folder).parts
        ):
            continue
        dst = src.with_name(src.name + ".png")
        try:
            with Image.open(src) as image:
                image.seek(0)  # GIF animado: primer fotograma
                image.convert("RGBA").save(dst, "PNG")
        except (OSError, ValueError):
            continue
        created.append(dst)
    return created
