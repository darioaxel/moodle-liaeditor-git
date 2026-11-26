"""Incorporar main a las ramas de edición (LIA-130): estado, fusión y conflictos."""

from __future__ import annotations

import subprocess
from pathlib import Path

import pytest
from fastapi.testclient import TestClient

from materiales_liascript.app import create_app
from materiales_liascript.edit import EditError, EditService, MergeRequest
from materiales_liascript.edit.merge import diff3_segments, has_conflict_markers
from materiales_liascript.git import LocalGitProvider

FILE = "materiales/ut03-mvc/02-mvc.md"
USER = "ana"
AUTHOR = "coordinacion <c@liaeditor.local>"
ORIGINAL = "# MVC\n\nUno.\n\nDos.\n\nTres.\n\nCuatro.\n\nCinco.\n"
HTTP_OK = 200
HTTP_CONFLICT = 409


@pytest.fixture()
def provider(git_repo: Path) -> LocalGitProvider:
    return LocalGitProvider(git_repo)


@pytest.fixture()
def service(provider: LocalGitProvider) -> EditService:
    return EditService(provider)


@pytest.fixture()
def branch(service: EditService, provider: LocalGitProvider) -> str:
    """Rama de edición de Ana creada desde un main con ``ORIGINAL``."""
    provider.commit_file("main", FILE, ORIGINAL, "material de partida", AUTHOR)
    return service.open(USER, FILE).branch


def _edit(provider: LocalGitProvider, ref: str, old: str, new: str) -> str:
    content = provider.get_file(ref, FILE).replace(old, new)
    return provider.commit_file(ref, FILE, content, f"cambia {old}", AUTHOR)


def _parents(provider: LocalGitProvider, sha: str) -> list[str]:
    out = subprocess.run(
        ["git", "-C", str(provider._root), "rev-list", "--parents", "-n", "1", sha],
        check=True,
        capture_output=True,
        text=True,
    ).stdout.split()
    return out[1:]


def _request(service: EditService, branch: str, content: str | None = None) -> MergeRequest:
    status = service.sync_status(FILE, branch)
    return MergeRequest(
        user=USER,
        file=FILE,
        branch=branch,
        head_sha=status.head_sha,
        main_sha=status.main_sha,
        content=content,
    )


def test_sin_cambios_en_main(service: EditService, branch: str) -> None:
    assert service.sync_status(FILE, branch).status == "up_to_date"


def test_main_cambia_otro_material_no_hay_nada_que_incorporar(
    service: EditService, provider: LocalGitProvider, branch: str
) -> None:
    provider.commit_file("main", "materiales/otro/otro.md", "# Otro\n", "otro", AUTHOR)
    status = service.sync_status(FILE, branch)
    assert status.status == "up_to_date"
    assert status.behind == 1


def test_la_rama_principal_no_aplica(service: EditService) -> None:
    assert service.sync_status(FILE, "main").status == "not_applicable"


def test_fusion_limpia(service: EditService, provider: LocalGitProvider, branch: str) -> None:
    _edit(provider, branch, "Dos.", "Dos, editado por Ana.")
    main_sha = _edit(provider, "main", "Cuatro.", "Cuatro, corregido en main.")
    status = service.sync_status(FILE, branch)
    assert status.status == "clean"
    assert status.behind == 1
    assert status.segments == []
    # Para revisarlo en el editor: la versión de la rama y cómo quedará.
    assert "Dos, editado por Ana." in status.ours and "Cuatro." in status.ours
    assert (
        "Dos, editado por Ana." in status.merged and "Cuatro, corregido en main." in status.merged
    )

    head = status.head_sha
    result = service.merge(_request(service, branch))
    merged = provider.get_file(branch, FILE)
    assert "Dos, editado por Ana." in merged
    assert "Cuatro, corregido en main." in merged
    assert result.new_sha == provider.get_commit(branch)
    assert _parents(provider, result.new_sha) == [head, main_sha]
    # Ya no queda nada pendiente.
    assert service.sync_status(FILE, branch).status == "up_to_date"


def test_conflicto_con_sus_trozos_y_su_resolucion(
    service: EditService, provider: LocalGitProvider, branch: str
) -> None:
    _edit(provider, branch, "Tres.", "Tres, versión de Ana.")
    _edit(provider, "main", "Tres.", "Tres, versión oficial.")
    _edit(provider, "main", "Cinco.", "Cinco, sin conflicto.")
    status = service.sync_status(FILE, branch)
    assert status.status == "conflict"
    assert status.conflicts == [FILE]
    conflicts = [s for s in status.segments if s.kind == "conflict"]
    assert len(conflicts) == 1
    assert conflicts[0].ours == "Tres, versión de Ana.\n"
    assert conflicts[0].base == "Tres.\n"
    assert conflicts[0].theirs == "Tres, versión oficial.\n"
    # Lo que solo cambió main ya viene fusionado en las partes comunes.
    assert "Cinco, sin conflicto." in "".join(s.text for s in status.segments)

    with pytest.raises(EditError) as sin_resolver:
        service.merge(_request(service, branch))
    assert sin_resolver.value.status_code == HTTP_CONFLICT

    resolved = "".join(s.text if s.kind == "same" else "Tres, acordado.\n" for s in status.segments)
    with pytest.raises(EditError) as con_marcas:
        service.merge(_request(service, branch, "<" * 21 + " tuya\nx\n" + "=" * 21 + "\n"))
    assert con_marcas.value.status_code == 400  # noqa: PLR2004

    result = service.merge(_request(service, branch, resolved))
    assert provider.get_file(branch, FILE) == resolved
    assert len(_parents(provider, result.new_sha)) == 2  # noqa: PLR2004 - commit de fusión
    assert service.sync_status(FILE, branch).status == "up_to_date"


def test_rama_o_main_movidas_dan_409(
    service: EditService, provider: LocalGitProvider, branch: str
) -> None:
    _edit(provider, "main", "Cuatro.", "Cuatro, en main.")
    stale = _request(service, branch)
    _edit(provider, branch, "Dos.", "Dos, guardado en otra pestaña.")
    with pytest.raises(EditError) as rama:
        service.merge(stale)
    assert rama.value.status_code == HTTP_CONFLICT

    stale = _request(service, branch)
    _edit(provider, "main", "Cinco.", "Cinco, otro cambio en main.")
    with pytest.raises(EditError) as oficial:
        service.merge(stale)
    assert oficial.value.status_code == HTTP_CONFLICT


def test_material_borrado_en_main_es_para_coordinacion(
    service: EditService, provider: LocalGitProvider, branch: str
) -> None:
    provider.commit_files("main", {FILE: None}, "retira el material", AUTHOR)
    status = service.sync_status(FILE, branch)
    assert status.status == "conflict_other"
    assert status.conflicts == [FILE]


def test_diff3_segmentos_y_marcas() -> None:
    segments = diff3_segments("a\nb\nc\n", "a\nB\nc\n", "a\nβ\nc\n")
    assert [s.kind for s in segments] == ["same", "conflict", "same"]
    assert (segments[1].ours, segments[1].base, segments[1].theirs) == ("B\n", "b\n", "β\n")
    # Un subrayado Markdown de 7 «=» no es una marca de conflicto.
    assert not has_conflict_markers("Título\n=======\n")


def test_api_sync_y_merge(git_repo: Path, provider: LocalGitProvider, branch: str) -> None:
    client = TestClient(create_app(git_repo, provider=provider))
    _edit(provider, "main", "Cuatro.", "Cuatro, en main.")
    response = client.get("/edit/sync", params={"file": FILE, "branch": branch})
    assert response.status_code == HTTP_OK
    body = response.json()
    assert body["status"] == "clean"
    payload = {
        "user": USER,
        "file": FILE,
        "branch": branch,
        "head_sha": body["head_sha"],
        "main_sha": body["main_sha"],
    }
    merge = client.post("/edit/merge", json=payload)
    assert merge.status_code == HTTP_OK
    assert merge.json()["new_sha"] == provider.get_commit(branch)
    # Repetir con los mismos SHA: la rama ya no está donde se revisó.
    assert client.post("/edit/merge", json=payload).status_code == HTTP_CONFLICT
