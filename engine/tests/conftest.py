"""Fixtures compartidos de tests."""

from __future__ import annotations

import shutil
import subprocess
from pathlib import Path

import pytest

FIXTURES = Path(__file__).parent / "fixtures" / "contenidos"


def _git(repo: Path, *args: str) -> None:
    subprocess.run(["git", "-C", str(repo), *args], check=True, capture_output=True, text=True)


def _commit(repo: Path, message: str) -> None:
    _git(repo, "add", "-A")
    _git(repo, "commit", "-qm", message)


@pytest.fixture()
def git_repo(tmp_path: Path) -> Path:
    """Repo de contenidos con historia en ``main`` y una rama ajena.

    Historia::

        main:  base → mod 02-mvc ─┬─ main: mod README → mod 01-introduccion
                                  └─ otra: +99-extra.md
    """
    repo = tmp_path / "contenidos"
    shutil.copytree(FIXTURES, repo)
    (repo / "README.md").write_text("# contenidos\n", encoding="utf-8")

    _git(repo, "init", "-q", "-b", "main")
    _git(repo, "config", "user.email", "test@example.com")
    _git(repo, "config", "user.name", "Test")
    _commit(repo, "base")

    materiales = repo / "materiales" / "ut03-mvc"
    mvc = materiales / "02-mvc.md"
    mvc.write_text(mvc.read_text(encoding="utf-8") + "\nDetalle extra.\n", encoding="utf-8")
    _commit(repo, "update mvc")

    _git(repo, "checkout", "-qb", "otra")
    (materiales / "99-extra.md").write_text("# Extra\n", encoding="utf-8")
    _commit(repo, "otra: material propio")

    _git(repo, "checkout", "-q", "main")
    (repo / "README.md").write_text("# contenidos\n\nCurso DWES.\n", encoding="utf-8")
    _commit(repo, "main: readme")
    intro = materiales / "01-introduccion.md"
    intro.write_text(intro.read_text(encoding="utf-8") + "\nAclaración.\n", encoding="utf-8")
    _commit(repo, "main: aclaración en intro")

    return repo
