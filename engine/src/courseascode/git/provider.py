"""Interfaz ``GitProvider``: abstracción Git del engine (ENG-020).

El engine nunca habla con Git directamente fuera de esta interfaz y sus
implementaciones. ``LocalGitProvider`` (GitPython) cubre repo local; la
estructura permite providers remotos futuros (API de GitHub/GitLab).
"""

from __future__ import annotations

from abc import ABC, abstractmethod
from contextlib import AbstractContextManager
from pathlib import Path

from courseascode.domain import GitRef
from courseascode.git.diff import Diff


class GitProviderError(RuntimeError):
    """Operación Git imposible: ref inexistente, repo inválido, etc."""


class BranchMovedError(GitProviderError):
    """La rama avanzó entre la lectura del HEAD y el commit (CAS fallido)."""


class GitProvider(ABC):
    """Operaciones Git que necesita el engine."""

    @abstractmethod
    def get_ref(self, ref: str) -> GitRef:
        """Resuelve y valida una referencia (tag, rama o commit)."""

    @abstractmethod
    def checkout_ref(self, ref: str) -> AbstractContextManager[Path]:
        """Worktree temporal aislado con ``ref`` (no muta el working tree)."""

    @abstractmethod
    def list_files(self, ref: str, path: str = "") -> list[str]:
        """Archivos (recursivo) de ``path`` en ``ref``, rutas posix relativas."""

    @abstractmethod
    def get_file(self, ref: str, path: str) -> str:
        """Contenido textual de un archivo en ``ref``."""

    @abstractmethod
    def get_commit(self, ref: str) -> str:
        """SHA completo del commit al que apunta ``ref`` (peela tags)."""

    @abstractmethod
    def list_tags(self, pattern: str = "*") -> list[str]:
        """Tags del repo filtrados por patrón glob (p. ej. ``book/ut03-mvc/v*``)."""

    @abstractmethod
    def diff(self, ref_a: str, ref_b: str) -> Diff:
        """Cambios entre dos refs (``ref_b`` respecto a ``ref_a``)."""

    @abstractmethod
    def merge_base(self, ref_a: str, ref_b: str) -> str:
        """SHA del mejor ancestro común de dos refs."""

    @abstractmethod
    def commits_ahead(self, base: str, ref: str) -> int:
        """Commits de ``ref`` que no están en ``base``."""

    @abstractmethod
    def is_ancestor(self, ref_a: str, ref_b: str) -> bool:
        """``True`` si ``ref_a`` es ancestro (o igual) de ``ref_b``."""

    @abstractmethod
    def current_branch(self) -> str:
        """Nombre de la rama actual; error si HEAD está desacoplado."""

    # -- escritura (LIA-003/004: flujo de edición LiaEditor) ----------------------

    @abstractmethod
    def branch_exists(self, branch: str) -> bool:
        """``True`` si existe la rama local ``branch``."""

    @abstractmethod
    def create_branch(self, branch: str, from_ref: str) -> None:
        """Crea la rama ``branch`` apuntando a ``from_ref`` (sin checkout)."""

    @abstractmethod
    def commit_file(
        self,
        branch: str,
        path: str,
        content: str,
        message: str,
        author: str,
    ) -> str:
        """Commit de un único archivo sobre ``branch``; devuelve el nuevo SHA.

        ``author`` es la identidad del autor en formato ``Nombre <email>``;
        el committer es siempre el engine (la cuenta técnica del servidor).
        No muta el working tree ni el índice (plumbing puro). La actualización
        de la rama es compare-and-swap sobre el HEAD leído: si la rama avanzó
        entre la lectura y el commit, lanza ``BranchMovedError``.
        """

    @abstractmethod
    def push_branch(self, branch: str) -> None:
        """Sube ``branch`` a ``origin``; no-op si el repo no tiene remoto."""
