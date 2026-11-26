"""El paquete de liaex solo lleva el material y sus recursos (imágenes, fase 3)."""

from __future__ import annotations

from pathlib import Path

from materiales_liascript.git import LocalGitProvider
from materiales_liascript.publish.models import PublishRequest
from materiales_liascript.publish.service import (
    ArtifactExecutor,
    PublishService,
    isolate_material,
)


def _write(root: Path, rel: str, text: str = "x") -> None:
    path = root / rel
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(text, encoding="utf-8")


def test_quita_los_otros_md_de_la_carpeta(tmp_path: Path) -> None:
    _write(tmp_path, "materiales/ut03.md", "# UT03\n\n![a](img/a.png)\n")
    _write(tmp_path, "materiales/ut04.md")
    _write(tmp_path, "materiales/sub/apuntes.md")
    _write(tmp_path, "materiales/img/a.png")
    _write(tmp_path, "otra/ut09.md")
    _write(tmp_path, "README.md")

    removed = isolate_material(tmp_path, "materiales/ut03.md")

    assert removed == ["materiales/sub/apuntes.md", "materiales/ut04.md"]
    assert (tmp_path / "materiales/ut03.md").is_file()
    assert (tmp_path / "materiales/img/a.png").is_file()
    # Fuera de la carpeta del material no se toca nada.
    assert (tmp_path / "otra/ut09.md").is_file()
    assert (tmp_path / "README.md").is_file()


def test_conserva_los_md_que_el_material_referencia(tmp_path: Path) -> None:
    _write(
        tmp_path,
        "m/ut05.md",
        "<!--\nimport: plantillas/macros.md\n-->\n\n# UT05\n\n"
        "[Anexo](./anexo.md) y [web](https://example.org/otro.md)\n",
    )
    _write(tmp_path, "m/plantillas/macros.md")
    _write(tmp_path, "m/anexo.md")
    _write(tmp_path, "m/otro.md")
    _write(tmp_path, "m/.oculto/x.md")

    removed = isolate_material(tmp_path, "m/ut05.md")

    assert removed == ["m/otro.md"]
    assert (tmp_path / "m/.oculto/x.md").is_file()


class _SeesFiles(ArtifactExecutor):
    def __init__(self) -> None:
        self.seen: list[str] = []

    def generate(self, checkout: Path, file: str, artifact_format: str) -> bytes:
        self.seen = sorted(
            p.relative_to(checkout).as_posix()
            for p in checkout.rglob("*.md")
            if ".git" not in p.parts
        )
        return b"ok"


def test_publicar_con_liaex_no_ve_los_otros_materiales(git_repo: Path) -> None:
    provider = LocalGitProvider(git_repo)
    provider.commit_files(
        "main",
        {"materiales/ut03.md": "# UT03\n", "materiales/ut04.md": "# UT04\n"},
        "dos materiales",
        "t <t@t.local>",
    )
    sha = provider.get_commit("main")
    executor = _SeesFiles()

    PublishService(provider, executor).publish(
        PublishRequest(file="materiales/ut03.md", sha=sha, format="h5p")
    )

    assert "materiales/ut04.md" not in executor.seen
    assert "materiales/ut03.md" in executor.seen
    # Git no cambia: el otro material sigue en el commit.
    assert provider.get_file(sha, "materiales/ut04.md") == "# UT04\n"
