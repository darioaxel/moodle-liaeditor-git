"""Fusión a tres vías del material para resolver conflictos (LIA-130).

Cuando ``main`` y la rama de edición cambian las mismas líneas del ``.md``,
el editor muestra cada conflicto con las tres versiones (la del profesor, la
original y la oficial) y el profesor elige. Este módulo trocea el resultado
de ``git merge-file --diff3`` en partes comunes y conflictos.
"""

from __future__ import annotations

import re
import subprocess
import tempfile
from pathlib import Path

from materiales_liascript.edit.models import MergeSegment

#: Tamaño de las marcas de conflicto: más largo que cualquier línea de
#: Markdown razonable (``=======`` de 7 es un subrayado de título válido).
MARKER_SIZE = 21
_LABELS = ("tuya", "original", "oficial")
_OURS = "<" * MARKER_SIZE + " " + _LABELS[0]
_BASE = "|" * MARKER_SIZE + " " + _LABELS[1]
_SEP = "=" * MARKER_SIZE
_THEIRS = ">" * MARKER_SIZE + " " + _LABELS[2]
_MARKERS = re.compile(
    rf"^(?:{re.escape(_OURS)}|{re.escape(_BASE)}|{_SEP}|{re.escape(_THEIRS)})\s*$", re.MULTILINE
)


def has_conflict_markers(text: str) -> bool:
    """``True`` si el texto conserva marcas de conflicto sin resolver."""
    return bool(_MARKERS.search(text))


def diff3_segments(base: str, ours: str, theirs: str) -> list[MergeSegment]:
    """Partes comunes y conflictos de fusionar ``ours`` y ``theirs`` desde ``base``.

    Lo que solo cambió en un lado ya viene fusionado en las partes comunes.
    """
    with tempfile.TemporaryDirectory(prefix="materiales-merge-") as tmp:
        paths = []
        for name, text in (("ours", ours), ("base", base), ("theirs", theirs)):
            path = Path(tmp) / name
            path.write_text(text, encoding="utf-8")
            paths.append(str(path))
        result = subprocess.run(
            [
                "git",
                "merge-file",
                "-p",
                "--diff3",
                f"--marker-size={MARKER_SIZE}",
                "-L",
                _LABELS[0],
                "-L",
                _LABELS[1],
                "-L",
                _LABELS[2],
                *paths,
            ],
            capture_output=True,
            text=True,
            check=False,
        )
    if result.returncode < 0:
        msg = f"git merge-file falló: {result.stderr.strip()}"
        raise RuntimeError(msg)
    return _parse(result.stdout)


def _parse(merged: str) -> list[MergeSegment]:
    segments: list[MergeSegment] = []
    same: list[str] = []
    parts: dict[str, list[str]] = {}
    section: str | None = None
    for line in merged.splitlines(keepends=True):
        marker = line.rstrip("\r\n")
        if section is None and marker == _OURS:
            if same:
                segments.append(MergeSegment(kind="same", text="".join(same)))
                same = []
            parts = {"ours": [], "base": [], "theirs": []}
            section = "ours"
        elif section == "ours" and marker == _BASE:
            section = "base"
        elif section in ("ours", "base") and marker == _SEP:
            section = "theirs"
        elif section == "theirs" and marker == _THEIRS:
            segments.append(
                MergeSegment(
                    kind="conflict",
                    ours="".join(parts["ours"]),
                    base="".join(parts["base"]),
                    theirs="".join(parts["theirs"]),
                )
            )
            section = None
        elif section is None:
            same.append(line)
        else:
            parts[section].append(line)
    if same:
        segments.append(MergeSegment(kind="same", text="".join(same)))
    return segments
