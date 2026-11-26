"""Web estática y PDF de contenido con Pandoc (roadmap docs/generacion_web).

Ejecuta Pandoc en Docker (por defecto ``pandoc/extra``, configurable con
``MATERIALES_LIASCRIPT_PANDOC_IMAGE``) con la extensión de
``publish/pandoc/``: el lector LiaScript (``liascript.lua``), el filtro de cada
salida (``web.lua`` / ``contenido.lua``), la plantilla web y sus recursos
(``assets/liamat.css`` y ``liamat.js``).

- **Web**: ``chunkedhtml`` (una página por sección, ``index.html``), más los
  recursos de la web y las imágenes del material; se devuelve como zip.
- **PDF de contenido**: plantilla FP Virtual (``fpvirtual.latex`` +
  ``content-boxes``), sin quizzes ni estructuras dinámicas. Por defecto, la
  copia incluida en ``pandoc/fpvirtual-theme/`` (de ``fpvirtual-markdown-a-pdf``);
  otra con ``MATERIALES_LIASCRIPT_PDF_THEME_DIR``.

Como liaex, trabaja sobre la copia temporal del SHA. La extensión y la
plantilla se copian dentro de esa copia (``.liamat-pandoc/``) para que el
demonio Docker las vea también cuando el engine corre en un contenedor
(docker-in-docker, ``workdir``/``host_workdir``).
"""

from __future__ import annotations

import io
import os
import shutil
import subprocess
import zipfile
from dataclasses import dataclass, field
from datetime import date
from importlib import resources
from pathlib import Path

from materiales_liascript.images import png_copies_for_pdf
from materiales_liascript.publish.models import PublishError

#: Imagen pública con Pandoc ≥ 3 (chunkedhtml), XeLaTeX y pandoc-latex-environment.
_IMAGE = "pandoc/extra:latest"
_TIMEOUT = 180
_WORK = ".liamat-pandoc"
_AVISO = "AVISO-LIASCRIPT"


@dataclass
class PandocResult:
    """Resultado de una conversión: bytes del artefacto y avisos del lector."""

    content: bytes
    avisos: list[str] = field(default_factory=list)


def _extension_dir() -> Path:
    return Path(str(resources.files("materiales_liascript.publish").joinpath("pandoc")))


class PandocExporter:
    """Genera la web estática y el PDF de contenido de un material."""

    def __init__(  # noqa: PLR0913 - opciones de despliegue, todas con defecto
        self,
        image: str = _IMAGE,
        *,
        timeout: int = _TIMEOUT,
        docker_binary: str = "docker",
        theme_dir: Path | None = None,
        workdir: str | None = None,
        host_workdir: str | None = None,
    ) -> None:
        self._image = image
        self._timeout = timeout
        self._docker = docker_binary
        self._theme_dir = theme_dir
        self._workdir = workdir
        self._host_workdir = host_workdir

    # -- API ------------------------------------------------------------------

    def web(self, checkout: Path, file: str, *, author: str | None = None) -> PandocResult:
        """Web estática del material como zip (``index.html`` en la raíz).

        Con ``author``, ese nombre sustituye al ``author:`` de la cabecera.
        """
        work = self._prepare(checkout)
        site = work / "web"
        try:
            avisos = self._pandoc(
                checkout,
                file,
                [
                    "-t",
                    "chunkedhtml",
                    "--split-level=2",
                    "--toc",
                    "--toc-depth=2",
                    f"--template=/work/{_WORK}/ext/web.html",
                    f"--lua-filter=/work/{_WORK}/ext/web.lua",
                    "--math-method=mathml",
                    *_author_args(author),
                    "-o",
                    f"/work/{_WORK}/web",
                ],
            )
            if not (site / "index.html").is_file():
                raise PublishError(502, "Pandoc no generó index.html")
            for asset in (work / "ext" / "assets").iterdir():
                shutil.copy2(asset, site / asset.name)
            _copy_resources(checkout, file, site)
            return PandocResult(_zip_dir(site), avisos)
        finally:
            shutil.rmtree(work, ignore_errors=True)

    def content_pdf(
        self,
        checkout: Path,
        file: str,
        *,
        author: str | None = None,
        module: str | None = None,
        published: date | None = None,
    ) -> PandocResult:
        """PDF solo con el contenido, con la plantilla FP Virtual.

        Portada con el logo de la plantilla, el título del módulo (``module``;
        sin él, el de la unidad), la unidad como subtítulo, el autor y la fecha
        de publicación (``published``, hoy por defecto). Tras la portada, el
        índice y, debajo, la licencia; en la cabecera de cada página, la unidad
        a la izquierda y el autor a la derecha (``contenido.lua``).

        Con ``author``, ese nombre sustituye al ``author:`` de la cabecera.
        """
        work = self._prepare(checkout)
        shutil.copytree(self._theme_dir or work / "ext" / "fpvirtual-theme", work / "theme")
        output = work / "contenido.pdf"
        # XeLaTeX no admite WebP ni GIF: copia PNG que usa contenido.lua.
        copies = png_copies_for_pdf((checkout / file).parent)
        try:
            avisos = self._pandoc(
                checkout,
                file,
                [
                    "-t",
                    "pdf",
                    f"--template=/work/{_WORK}/theme/fpvirtual.latex",
                    "--pdf-engine=xelatex",
                    f"--lua-filter=/work/{_WORK}/ext/contenido.lua",
                    "--filter",
                    "pandoc-latex-environment",
                    f"--metadata-file=/work/{_WORK}/theme/content-boxes.yaml",
                    "-H",
                    f"/work/{_WORK}/theme/content-boxes.tex",
                    "--syntax-highlighting=default",
                    *_author_args(author),
                    *_cover_args(module, published or date.today()),
                    "-o",
                    f"/work/{_WORK}/contenido.pdf",
                ],
                env={"TEXINPUTS": f"/work/{_WORK}/theme:"},
            )
            if not output.is_file():
                raise PublishError(502, "Pandoc no generó el PDF")
            return PandocResult(output.read_bytes(), avisos)
        finally:
            shutil.rmtree(work, ignore_errors=True)
            for copy in copies:
                copy.unlink(missing_ok=True)

    # -- Internos -------------------------------------------------------------

    def _prepare(self, checkout: Path) -> Path:
        work = checkout / _WORK
        shutil.rmtree(work, ignore_errors=True)
        work.mkdir()
        shutil.copytree(_extension_dir(), work / "ext")
        return work

    def _host_path(self, checkout: Path) -> str:
        path = str(checkout)
        if self._workdir and self._host_workdir and path.startswith(self._workdir):
            return self._host_workdir + path[len(self._workdir) :]
        return path

    def _pandoc(
        self, checkout: Path, file: str, args: list[str], env: dict[str, str] | None = None
    ) -> list[str]:
        command = [
            self._docker,
            "run",
            "--rm",
            "--network",
            "none",
            "-v",
            f"{self._host_path(checkout)}:/work",
            "-w",
            f"/work/{Path(file).parent.as_posix()}",
        ]
        if hasattr(os, "getuid"):
            command += ["--user", f"{os.getuid()}:{os.getgid()}"]
        for key, value in (env or {}).items():
            command += ["--env", f"{key}={value}"]
        command += [
            "--entrypoint",
            "pandoc",
            self._image,
            f"/work/{file}",
            "-f",
            f"/work/{_WORK}/ext/liascript.lua",
            f"--resource-path=/work/{Path(file).parent.as_posix()}",
            *args,
        ]
        try:
            result = subprocess.run(
                command, capture_output=True, text=True, check=False, timeout=self._timeout
            )
        except subprocess.TimeoutExpired as exc:
            msg = f"Pandoc superó el tiempo máximo ({self._timeout} s)"
            raise PublishError(504, msg) from exc
        avisos = [
            line.split("\t", 1)[1].replace("\t", " · ")
            for line in result.stderr.splitlines()
            if line.startswith(_AVISO + "\t")
        ]
        if result.returncode != 0:
            detalle = "\n".join(
                line for line in result.stderr.splitlines() if not line.startswith(_AVISO)
            )
            raise PublishError(502, f"Pandoc falló: {detalle.strip()[:500]}")
        return avisos


def _copy_resources(checkout: Path, file: str, site: Path) -> None:
    """Copia al sitio los ficheros del material que no son Markdown (imágenes…)."""
    base = (checkout / file).parent
    for path in base.rglob("*"):
        rel = path.relative_to(base)
        if path.is_dir() or rel.parts[0].startswith(".") or path.suffix.lower() == ".md":
            continue
        target = site / rel
        if not target.exists():
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(path, target)


def _author_args(author: str | None) -> list[str]:
    """``-M author=...``: los metadatos de la línea de órdenes prevalecen
    sobre los que pone el lector LiaScript a partir de la cabecera."""
    return ["-M", f"author={author}"] if author else []


_MESES = (
    "enero",
    "febrero",
    "marzo",
    "abril",
    "mayo",
    "junio",
    "julio",
    "agosto",
    "septiembre",
    "octubre",
    "noviembre",
    "diciembre",
)


def fecha_es(dia: date) -> str:
    """Fecha en español: «26 de noviembre de 2026»."""
    return f"{dia.day} de {_MESES[dia.month - 1]} de {dia.year}"


def _cover_args(module: str | None, published: date) -> list[str]:
    """Metadatos de la portada, el índice y la cabecera del PDF de contenido."""
    args = [
        "-M",
        "titlepage=true",
        "-M",
        "toc=true",
        "-M",
        "toc-depth=2",
        "-M",
        "toc-own-page=true",
        "-M",
        f"date={fecha_es(published)}",
    ]
    if module:
        args += ["-M", f"liamat-modulo={module}"]
    return args


def _zip_dir(directory: Path) -> bytes:
    buffer = io.BytesIO()
    with zipfile.ZipFile(buffer, "w", zipfile.ZIP_DEFLATED) as zf:
        for path in sorted(directory.rglob("*")):
            if path.is_file():
                zf.write(path, path.relative_to(directory).as_posix())
    return buffer.getvalue()
