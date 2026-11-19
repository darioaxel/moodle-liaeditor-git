"""Tests de lectura del ``LocalGitProvider`` (la escritura se cubre en test_edit_*)."""

from __future__ import annotations

from pathlib import Path

import pytest

from materiales_liascript.git import GitProviderError, LocalGitProvider

SHA_LEN = 40
OTRA_COMMITS_AHEAD = 1
MAIN_COMMITS_AHEAD = 2


@pytest.fixture()
def provider(git_repo: Path) -> LocalGitProvider:
    return LocalGitProvider(git_repo)


def test_repo_invalido(tmp_path: Path) -> None:
    with pytest.raises(GitProviderError, match="No es un repositorio Git"):
        LocalGitProvider(tmp_path)


def test_get_file(provider: LocalGitProvider) -> None:
    assert "Detalle extra." in provider.get_file("main", "materiales/ut03-mvc/02-mvc.md")
    assert "Detalle extra." not in provider.get_file("main~3", "materiales/ut03-mvc/02-mvc.md")


def test_get_file_inexistente(provider: LocalGitProvider) -> None:
    with pytest.raises(GitProviderError, match="no encontrado"):
        provider.get_file("main", "materiales/no-existe.md")


def test_list_files(provider: LocalGitProvider) -> None:
    files = provider.list_files("main", "materiales/ut03-mvc")
    assert "materiales/ut03-mvc/02-mvc.md" in files
    assert "materiales/ut03-mvc/assets/mvc.svg" in files
    assert "materiales/ut03-mvc/99-extra.md" not in files
    assert "materiales/ut03-mvc/99-extra.md" in provider.list_files("otra")


def test_get_commit(provider: LocalGitProvider) -> None:
    sha = provider.get_commit("main")
    assert len(sha) == SHA_LEN
    assert provider.get_commit(sha[:7]) == sha
    with pytest.raises(GitProviderError, match="no encontrada"):
        provider.get_commit("rama-imposible")


def test_commits_ahead(provider: LocalGitProvider) -> None:
    base = provider.get_commit("main~2")  # punto de bifurcación de ``otra``
    assert provider.commits_ahead(base, "otra") == OTRA_COMMITS_AHEAD
    assert provider.commits_ahead(base, "main") == MAIN_COMMITS_AHEAD


def test_is_ancestor(provider: LocalGitProvider) -> None:
    assert provider.is_ancestor("main~1", "main")
    assert not provider.is_ancestor("main", "main~1")
    assert not provider.is_ancestor("otra", "main")


def test_checkout_ref_aislado(provider: LocalGitProvider, git_repo: Path) -> None:
    with provider.checkout_ref("main~3") as worktree:
        assert (worktree / "materiales/ut03-mvc/02-mvc.md").is_file()
        # anidado: dos worktrees simultáneos
        with provider.checkout_ref("otra") as other:
            assert (other / "materiales/ut03-mvc/99-extra.md").is_file()
    # el working tree del usuario no se ha tocado
    assert not (git_repo / "materiales/ut03-mvc/99-extra.md").exists()


def test_checkout_ref_invalido(provider: LocalGitProvider) -> None:
    with pytest.raises(GitProviderError), provider.checkout_ref("ref-imposible"):
        pass
