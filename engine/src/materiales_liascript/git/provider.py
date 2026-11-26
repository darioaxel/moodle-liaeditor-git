"""Interfaz ``GitProvider``: abstracción Git del engine.

El engine nunca habla con Git directamente fuera de esta interfaz y sus
implementaciones. ``LocalGitProvider`` (GitPython) cubre repo local; la
estructura permite providers remotos futuros (API de GitHub/GitLab).
"""

from __future__ import annotations

from abc import ABC, abstractmethod
from collections.abc import Mapping
from contextlib import AbstractContextManager
from pathlib import Path


class GitProviderError(RuntimeError):
    """Operación Git imposible: ref inexistente, repo inválido, etc."""


class BranchMovedError(GitProviderError):
    """La rama avanzó entre la lectura del HEAD y el commit (CAS fallido)."""


class GitProvider(ABC):
    """Operaciones Git que necesita el engine."""

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
    def get_bytes(self, ref: str, path: str) -> bytes:
        """Contenido binario de un archivo en ``ref`` (imágenes)."""

    @abstractmethod
    def get_commit(self, ref: str) -> str:
        """SHA completo del commit al que apunta ``ref`` (peela tags)."""

    def commit_date(self, ref: str) -> str | None:
        """Fecha del commit (committer date) en ISO 8601 con zona horaria.

        ``None`` si no se puede obtener: es un dato informativo para la
        interfaz y nunca debe hacer fallar una operación.
        """
        return None

    def last_author(self, ref: str, path: str) -> str | None:
        """Nombre del autor del último commit de ``ref`` que modificó ``path``.

        Los guardados del editor se commitean con el usuario de Moodle como
        autor. ``None`` si no se puede obtener: es informativo y nunca debe
        hacer fallar una operación.
        """
        return None

    @abstractmethod
    def commits_ahead(self, base: str, ref: str) -> int:
        """Commits de ``ref`` que no están en ``base``."""

    @abstractmethod
    def is_ancestor(self, ref_a: str, ref_b: str) -> bool:
        """``True`` si ``ref_a`` es ancestro (o igual) de ``ref_b``."""

    # -- escritura (flujo de edición: ramas edicion/*, D4/D5) ---------------------

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

    def commit_files(
        self,
        branch: str,
        files: Mapping[str, str | bytes | None],
        message: str,
        author: str,
    ) -> str:
        """Un único commit con varios archivos (texto o binario) sobre ``branch``.

        Mismas garantías que ``commit_file`` (plumbing, compare-and-swap).
        Lo usa el guardado con imágenes (docs/imagenes): Markdown e imágenes
        en el mismo commit. ``None`` borra el archivo (las imágenes que el
        material deja de usar); un directorio que se queda vacío desaparece.
        """
        raise NotImplementedError

    @abstractmethod
    def push_branch(self, branch: str) -> None:
        """Sube ``branch`` a ``origin``; no-op si el repo no tiene remoto."""
