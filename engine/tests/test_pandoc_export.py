"""Generación web y PDF de contenido con Pandoc (roadmap docs/generacion_web, Fase 0).

Tests de integración: necesitan Docker y la imagen ``fpvirtual-pandoc`` (y,
para la conformidad, ``liascript/exporter``; para el PDF, la plantilla FP
Virtual en ``MATERIALES_LIASCRIPT_PDF_THEME_DIR``). Se saltan si faltan.

Criterio de la Fase 0: los quizzes que detecta el lector LiaScript coinciden,
sección a sección y por tipo, con los que reconoce el parser real de LiaScript
(``liaex -f fullJson``).
"""

from __future__ import annotations

import io
import json
import os
import re
import shutil
import subprocess
import zipfile
from collections import Counter
from pathlib import Path

import pytest

from materiales_liascript.publish.pandoc_export import PandocExporter, _extension_dir

FIXTURES = Path(__file__).parent / "fixtures" / "liascript"
MATERIALES = sorted(p.name for p in FIXTURES.glob("*.md"))
PANDOC_IMAGE = "fpvirtual-pandoc:latest"
LIAEX_IMAGE = "liascript/exporter:latest"
LIAEX_TIPOS = {
    "SingleChoice": "single",
    "MultipleChoice": "multiple",
    "Text": "text",
    "Matrix": "matrix",
    "Multi": "select",
}


def _imagen(nombre: str) -> bool:
    if shutil.which("docker") is None:
        return False
    return (
        subprocess.run(
            ["docker", "image", "inspect", nombre], capture_output=True, check=False
        ).returncode
        == 0
    )


requiere_pandoc = pytest.mark.skipif(
    not _imagen(PANDOC_IMAGE), reason="sin Docker o sin imagen Pandoc"
)
requiere_liaex = pytest.mark.skipif(not _imagen(LIAEX_IMAGE), reason="sin imagen liaex")


@pytest.fixture()
def material(tmp_path: Path) -> Path:
    destino = tmp_path / "materiales"
    shutil.copytree(FIXTURES, destino)
    return tmp_path


def _ast(checkout: Path, fichero: str) -> dict[str, object]:
    ext = checkout / ".ext"
    shutil.copytree(_extension_dir(), ext)
    salida = subprocess.run(
        [
            "docker",
            "run",
            "--rm",
            "--network",
            "none",
            "--user",
            f"{os.getuid()}:{os.getgid()}",
            "-v",
            f"{checkout}:/work",
            "--entrypoint",
            "pandoc",
            PANDOC_IMAGE,
            f"/work/{fichero}",
            "-f",
            "/work/.ext/liascript.lua",
            "-t",
            "json",
        ],
        capture_output=True,
        text=True,
        check=True,
    )
    return json.loads(salida.stdout)  # type: ignore[no-any-return]


def _secciones_lector(ast: dict[str, object]) -> list[Counter[str]]:
    """Por cada título (sección de LiaScript), quizzes por tipo y encuestas."""
    secciones: list[Counter[str]] = []

    def recorrer(nodo: object) -> None:
        if isinstance(nodo, dict):
            if nodo.get("t") == "Header":
                secciones.append(Counter())
            elif nodo.get("t") == "Div" and secciones:
                _, clases, attrs = nodo["c"][0]
                atributos = dict(attrs)
                if "quiz" in clases:
                    secciones[-1][atributos.get("tipo", "?")] += 1
                elif "encuesta" in clases:
                    secciones[-1]["encuesta"] += 1
            elif nodo.get("t") == "Para" and secciones and '"quiz-select"' in json.dumps(nodo):
                secciones[-1]["select"] += 1
            for valor in nodo.values():
                recorrer(valor)
        elif isinstance(nodo, list):
            for valor in nodo:
                recorrer(valor)

    recorrer(ast["blocks"])
    return secciones


def _secciones_liaex(checkout: Path, fichero: str) -> list[Counter[str]]:
    base = Path(fichero).stem
    subprocess.run(
        [
            "docker",
            "run",
            "--rm",
            "--network",
            "none",
            "--user",
            f"{os.getuid()}:{os.getgid()}",
            "-v",
            f"{checkout}:/work",
            LIAEX_IMAGE,
            "liaex",
            "-i",
            f"/work/{fichero}",
            "-f",
            "fullJson",
            "-o",
            f"/work/{base}",
        ],
        capture_output=True,
        check=True,
    )
    datos = json.loads((checkout / f"{base}.json").read_text(encoding="utf-8"))
    secciones = []
    for quizzes, encuestas in zip(datos["quiz"], datos["survey"], strict=True):
        cuenta: Counter[str] = Counter(
            LIAEX_TIPOS.get(next(iter(q["state"])), "?") for q in quizzes
        )
        if encuestas:
            cuenta["encuesta"] += len(encuestas)
        secciones.append(cuenta)
    return secciones


@requiere_pandoc
@requiere_liaex
@pytest.mark.parametrize("nombre", MATERIALES)
def test_conformidad_con_liascript(material: Path, nombre: str) -> None:
    """Mismas secciones y mismos quizzes por tipo que el parser real de LiaScript."""
    fichero = f"materiales/{nombre}"
    nuestras = _secciones_lector(_ast(material, fichero))
    reales = _secciones_liaex(material, fichero)
    assert len(nuestras) == len(reales), "número de secciones distinto"
    assert nuestras == reales


@requiere_pandoc
@pytest.mark.parametrize("nombre", MATERIALES)
def test_web_sin_restos_de_liascript(material: Path, nombre: str) -> None:
    resultado = PandocExporter().web(material, f"materiales/{nombre}")
    paquete = zipfile.ZipFile(io.BytesIO(resultado.content))
    nombres = paquete.namelist()
    assert {"index.html", "liamat.js", "liamat.css"} <= set(nombres)
    assert all(n.isascii() for n in nombres), "nombres de página con caracteres no ASCII"
    texto = "\n".join(
        re.sub(r"<(code|pre)[^>]*>.*?</\1>", "", paquete.read(n).decode("utf-8"), flags=re.S)
        for n in nombres
        if n.endswith(".html")
    )
    for resto in ("[[", "[(", "{{", "--{{", "@nota(", "@aviso(", "@error("):
        assert resto not in texto, f"resto de sintaxis LiaScript en la web: {resto}"
    assert resultado.avisos == []
    assert not (material / "materiales" / ".liamat-pandoc").exists()


@requiere_pandoc
@pytest.mark.skipif(
    not os.environ.get("MATERIALES_LIASCRIPT_PDF_THEME_DIR"),
    reason="sin plantilla FP Virtual (MATERIALES_LIASCRIPT_PDF_THEME_DIR)",
)
@pytest.mark.parametrize("nombre", MATERIALES)
def test_pdf_de_contenido_sin_preguntas(material: Path, nombre: str) -> None:
    exportador = PandocExporter(theme_dir=Path(os.environ["MATERIALES_LIASCRIPT_PDF_THEME_DIR"]))
    resultado = exportador.content_pdf(material, f"materiales/{nombre}")
    assert resultado.content[:4] == b"%PDF"
    pdf = material / "salida.pdf"
    pdf.write_bytes(resultado.content)
    if shutil.which("pdftotext"):
        texto = subprocess.run(
            ["pdftotext", str(pdf), "-"], capture_output=True, text=True, check=True
        ).stdout
        # Sin «[[» ni «{{»: un material puede explicar la sintaxis en código en línea.
        for resto in ("--{{", "@nota(", "@aviso(", "Comprobar", "Autoevaluación"):
            assert resto not in texto, f"en el PDF de contenido no debe aparecer: {resto}"
