"""Tests del servicio de edición (LIA-002/003/004/005) sobre el fixture Git."""

from __future__ import annotations

import subprocess
from datetime import UTC, datetime
from pathlib import Path

import pytest

from materiales_liascript.edit import EditError, EditService, SaveConflict, SaveOk, SaveRequest
from materiales_liascript.git import GitProviderError, LocalGitProvider

FILE = "materiales/ut03-mvc/02-mvc.md"
USER = "ana"
BRANCH = "edicion/ana/materiales-ut03-mvc-02-mvc"
NOW = datetime(2026, 11, 17, 12, 0, 0, tzinfo=UTC)


@pytest.fixture()
def provider(git_repo: Path) -> LocalGitProvider:
    return LocalGitProvider(git_repo)


@pytest.fixture()
def service(provider: LocalGitProvider) -> EditService:
    return EditService(provider, now=lambda: NOW)


def _save(user: str, branch: str, sha: str, content: str) -> SaveRequest:
    return SaveRequest(user=user, file=FILE, branch=branch, original_sha=sha, content=content)


# -- open (LIA-003) -----------------------------------------------------------


def test_open_crea_la_rama_desde_main(service: EditService, provider: LocalGitProvider) -> None:
    result = service.open(USER, FILE)

    assert result.branch == BRANCH
    assert result.stale is False
    assert result.sha == provider.get_commit("main")
    assert "Detalle extra." in result.content
    assert provider.branch_exists(BRANCH)


def test_open_reutiliza_la_rama_existente(service: EditService, provider: LocalGitProvider) -> None:
    service.open(USER, FILE)
    provider.commit_file(BRANCH, FILE, "# Propio\n", "ana: cambio", "ana <ana@test.local>")

    result = service.open(USER, FILE)

    assert result.content == "# Propio\n"  # no resetea el trabajo previo


def test_open_fichero_inexistente_en_main(service: EditService) -> None:
    with pytest.raises(EditError) as excinfo:
        service.open(USER, "materiales/ut03-mvc/no-existe.md")
    assert excinfo.value.status_code == 404  # noqa: PLR2004


def test_content_rama_inexistente(service: EditService) -> None:
    with pytest.raises(EditError) as excinfo:
        service.content(FILE, "edicion/nadie/nada")
    assert excinfo.value.status_code == 404  # noqa: PLR2004


def test_open_detecta_rama_stale(service: EditService, provider: LocalGitProvider) -> None:
    # La rama de edición ya existía cuando el fichero se borró de main.
    provider.create_branch(BRANCH, "main")
    provider.commit_file(
        "main", "README.md", "# renombrado\n", "main: otro cambio", "t <t@t.local>"
    )
    # Simula el borrado del fichero en main con un commit plumbing inverso:
    # commit sobre main sin el archivo (vía worktree CLI para no tocar el índice
    # del provider, que es read-only en el test).
    subprocess.run(
        ["git", "-C", str(provider._root), "rm", "-q", FILE],
        check=True,
        capture_output=True,
    )
    subprocess.run(
        ["git", "-C", str(provider._root), "commit", "-qm", "main: borra el fichero"],
        check=True,
        capture_output=True,
    )

    result = service.open(USER, FILE)

    assert result.stale is True
    assert result.branch == BRANCH  # se sigue sirviendo el contenido de la rama


# -- save (LIA-004) -----------------------------------------------------------


def test_save_ok_commitea_en_la_rama(service: EditService, provider: LocalGitProvider) -> None:
    opened = service.open(USER, FILE)

    result = service.save(_save(USER, BRANCH, opened.sha, "# MVC v2\n"))

    assert isinstance(result, SaveOk)
    assert result.branch == BRANCH
    assert result.new_sha != opened.sha
    assert provider.get_commit(BRANCH) == result.new_sha
    assert provider.get_file(BRANCH, FILE) == "# MVC v2\n"
    # main queda intacto.
    assert "Detalle extra." in provider.get_file("main", FILE)


def test_save_mensaje_por_defecto(service: EditService, provider: LocalGitProvider) -> None:
    opened = service.open(USER, FILE)
    result = service.save(_save(USER, BRANCH, opened.sha, "# v2\n"))
    assert isinstance(result, SaveOk)
    subject = subprocess.run(
        ["git", "-C", str(provider._root), "log", "-1", "--format=%s", result.new_sha],
        check=True,
        capture_output=True,
        text=True,
    ).stdout.strip()
    assert subject == f"Editado desde Moodle por {USER}"


def test_save_conflicto_crea_rama_resolucion(
    service: EditService, provider: LocalGitProvider
) -> None:
    opened = service.open(USER, FILE)
    # Otro escritor avanza la rama entre la apertura y el guardado.
    provider.commit_file(BRANCH, FILE, "# Ajeno\n", "otro: cambio", "otro <otro@test.local>")

    result = service.save(_save(USER, BRANCH, opened.sha, "# Mío\n"))

    assert isinstance(result, SaveConflict)
    assert (
        result.resolution_branch == f"resolucion/{USER}/20261117-120000-materiales-ut03-mvc-02-mvc"
    )
    assert result.current_sha == provider.get_commit(BRANCH)
    # El trabajo del profesor no se pierde: queda en la rama de resolución.
    assert provider.get_file(result.resolution_branch, FILE) == "# Mío\n"
    # La rama de edición conserva el contenido del otro escritor.
    assert provider.get_file(BRANCH, FILE) == "# Ajeno\n"


def test_save_conflicto_segundo_intento_sufija_sha(
    service: EditService, provider: LocalGitProvider
) -> None:
    opened = service.open(USER, FILE)
    provider.commit_file(BRANCH, FILE, "# Ajeno\n", "otro", "otro <otro@test.local>")
    first = service.save(_save(USER, BRANCH, opened.sha, "# Mío v1\n"))
    assert isinstance(first, SaveConflict)

    second = service.save(_save(USER, BRANCH, opened.sha, "# Mío v2\n"))

    assert isinstance(second, SaveConflict)
    assert second.resolution_branch != first.resolution_branch
    assert second.resolution_branch.startswith("resolucion/ana/20261117-120000-")


def test_save_push_a_origin(
    service: EditService, provider: LocalGitProvider, tmp_path: Path
) -> None:
    origin = tmp_path / "origin.git"
    subprocess.run(["git", "init", "-q", "--bare", str(origin)], check=True, capture_output=True)
    subprocess.run(
        ["git", "-C", str(provider._root), "remote", "add", "origin", str(origin)],
        check=True,
        capture_output=True,
    )
    opened = service.open(USER, FILE)

    result = service.save(_save(USER, BRANCH, opened.sha, "# Publicado\n"))

    assert isinstance(result, SaveOk)
    remote_sha = subprocess.run(
        ["git", "-C", str(origin), "rev-parse", f"refs/heads/{BRANCH}"],
        check=True,
        capture_output=True,
        text=True,
    ).stdout.strip()
    assert remote_sha == result.new_sha


def test_save_rama_inexistente(service: EditService) -> None:
    with pytest.raises(EditError) as excinfo:
        service.save(_save(USER, "edicion/nadie/nada", "a" * 40, "x"))
    assert excinfo.value.status_code == 404  # noqa: PLR2004


# -- state (LIA-005) ----------------------------------------------------------


def test_state_up_to_date(service: EditService, provider: LocalGitProvider) -> None:
    head = provider.get_commit("main")
    result = service.state(FILE, head)
    assert result.state == "up_to_date"
    assert result.head_sha == head
    assert result.commits_ahead == 0


def test_state_update_available(service: EditService, provider: LocalGitProvider) -> None:
    published = provider.get_commit("main~1")
    result = service.state(FILE, published)
    assert result.state == "update_available"
    assert result.commits_ahead is not None and result.commits_ahead > 0


def test_state_sha_no_ancestro(service: EditService, provider: LocalGitProvider) -> None:
    result = service.state(FILE, "0" * 40)
    assert result.state == "update_available"
    assert result.commits_ahead is None  # no se puede calcular sin ancestro común


def test_state_rama_inexistente(service: EditService) -> None:
    with pytest.raises(EditError):
        service.state(FILE, "a" * 40, branch="no-existe")


# -- CAS de bajo nivel ---------------------------------------------------------


def test_update_ref_cas_falla_si_la_rama_avanzo(provider: LocalGitProvider) -> None:
    """El compare-and-swap de update-ref (base del BranchMovedError) es fiable."""
    provider.create_branch("edicion/test/cas", "main")
    base = provider.get_commit("edicion/test/cas")
    provider.commit_file("edicion/test/cas", "README.md", "# movido\n", "x", "t <t@t.local>")

    with pytest.raises(GitProviderError):
        provider._git("update-ref", "refs/heads/edicion/test/cas", base, base)


# -- committed_at (fecha del commit para el editor) ---------------------------------


def _log_date(repo: Path, sha: str) -> str:
    out = subprocess.run(
        ["git", "-C", str(repo), "log", "-1", "--format=%cI", sha],
        check=True,
        capture_output=True,
        text=True,
    )
    return out.stdout.strip()


def test_content_incluye_fecha_del_commit(service: EditService, git_repo: Path) -> None:
    result = service.content(FILE, "main")
    assert result.committed_at == _log_date(git_repo, result.sha)
    assert result.committed_at is not None
    assert datetime.fromisoformat(result.committed_at).tzinfo is not None


def test_save_ok_incluye_fecha_del_nuevo_commit(service: EditService, git_repo: Path) -> None:
    opened = service.open(USER, FILE)
    result = service.save(_save(USER, BRANCH, opened.sha, "# Nuevo\n"))
    assert isinstance(result, SaveOk)
    assert result.committed_at == _log_date(git_repo, result.new_sha)


class _SinFecha(LocalGitProvider):
    """Provider cuyo Git no da la fecha: el campo se omite sin error."""

    def commit_date(self, ref: str) -> str | None:
        return None


def test_sin_fecha_el_contenido_y_el_guardado_siguen_funcionando(git_repo: Path) -> None:
    service = EditService(_SinFecha(git_repo), now=lambda: NOW)
    opened = service.open(USER, FILE)
    assert opened.committed_at is None
    result = service.save(_save(USER, BRANCH, opened.sha, "# Nuevo\n"))
    assert isinstance(result, SaveOk)
    assert result.committed_at is None
