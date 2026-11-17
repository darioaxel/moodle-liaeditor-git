"""Servicio del flujo de edición LiaEditor (LIA-002/003/004/005).

Toda la lógica Git de la edición vive aquí (D2): ramas ``edicion/*``,
commits con la identidad del profesor y conflictos optimistic-locking por
SHA (D5). El plugin Moodle es una fachada (token, capacidades, registro de
sesión) que delega en estos métodos vía HTTP.
"""

from __future__ import annotations

import re
from collections.abc import Callable
from datetime import datetime

from courseascode.edit.branches import (
    EditError,
    edit_branch_name,
    resolution_branch_name,
    validate_file_path,
)
from courseascode.edit.models import (
    EditContent,
    EditState,
    MaterialFile,
    MaterialList,
    SaveConflict,
    SaveOk,
    SaveRequest,
    SaveResult,
)
from courseascode.git.provider import BranchMovedError, GitProvider, GitProviderError

_SSH_URL_RE = re.compile(r"^git@(?P<host>[^:]+):(?P<path>.+?)(?:\.git)?$")
_HTTPS_URL_RE = re.compile(r"^https?://(?P<creds>[^/@]+@)?(?P<rest>.+?)(?:\.git)?$")
# Cabecera LiaScript: comentario HTML al inicio del documento (con líneas
# en blanco/BOM opcionales delante) y clave ``title:`` en su interior.
_LIASCRIPT_HEADER_RE = re.compile(r"\s*<!--(?P<body>.*?)-->", re.DOTALL)
_LIASCRIPT_TITLE_RE = re.compile(r"^\s*title\s*:\s*(?P<title>.+?)\s*$", re.MULTILINE)


def build_compare_url(repo_url: str | None, base: str, head: str) -> str | None:
    """URL de comparación ``base...head`` en la forja (GitHub/GitLab), si se puede."""
    if not repo_url:
        return None
    match = _SSH_URL_RE.match(repo_url)
    web = f"https://{match['host']}/{match['path']}" if match else None
    if web is None:
        https = _HTTPS_URL_RE.match(repo_url)
        web = f"https://{https['rest']}" if https else None
    return f"{web}/compare/{base}...{head}" if web else None


class EditService:
    """Operaciones de edición sobre el repo de contenidos."""

    def __init__(
        self,
        git: GitProvider,
        repo_url: str | None = None,
        default_branch: str = "main",
        now: Callable[[], datetime] | None = None,
    ) -> None:
        self._git = git
        self._repo_url = repo_url
        self._default_branch = default_branch
        self._now = now

    def open(self, user: str, file: str) -> EditContent:
        """Asegura la rama de edición del usuario y devuelve el contenido.

        Si la rama no existe se crea desde ``main`` (D4). Si el fichero ya
        no existe en ``main`` (renombrado/borrado) la rama se marca *stale*:
        se avisa pero no se bloquea la edición.
        """
        file = validate_file_path(file)
        if not self._git.branch_exists(self._default_branch):
            raise EditError(503, f"no existe la rama principal: {self._default_branch!r}")
        stale = not self._file_exists(self._default_branch, file)
        branch = edit_branch_name(user, file)
        if not self._git.branch_exists(branch):
            if stale:
                raise EditError(404, f"el fichero no existe en main: {file!r}")
            self._git.create_branch(branch, self._default_branch)
            self._git.push_branch(branch)
        return self.content(file, branch, stale=stale)

    def content(self, file: str, branch: str, stale: bool = False) -> EditContent:
        """Contenido del fichero en la rama dada, con el SHA del HEAD."""
        file = validate_file_path(file)
        if not self._git.branch_exists(branch):
            raise EditError(404, f"la rama no existe: {branch!r}")
        sha = self._git.get_commit(branch)
        try:
            content = self._git.get_file(branch, file)
        except GitProviderError as exc:
            raise EditError(404, str(exc)) from exc
        return EditContent(file=file, branch=branch, sha=sha, content=content, stale=stale)

    def save(self, request: SaveRequest) -> SaveResult:
        """Guarda el contenido en la rama (D5: conflicto → rama ``resolucion/...``).

        Si el HEAD sigue siendo ``original_sha`` el commit va directo a la
        rama; si avanzó, el contenido se commitea en una rama de resolución
        creada desde el HEAD actual y el cliente decide cómo reconciliar.
        """
        file = validate_file_path(request.file)
        if not self._git.branch_exists(request.branch):
            raise EditError(404, f"la rama no existe: {request.branch!r}")
        current = self._git.get_commit(request.branch)
        message = request.message or f"Editado desde Moodle por {request.user}"
        target = request.branch
        conflict = current != request.original_sha
        if conflict:
            target = resolution_branch_name(request.user, file, self._now() if self._now else None)
            try:
                self._git.create_branch(target, current)
            except GitProviderError:
                target = f"{target}-{current[:8]}"
                self._git.create_branch(target, current)
        try:
            new_sha = self._git.commit_file(
                target,
                file,
                request.content,
                message,
                author=f"{request.user} <{request.user}@liaeditor.local>",
            )
        except BranchMovedError:
            return self.save(request)  # carrera: releer HEAD y redecidir
        self._git.push_branch(target)
        if conflict:
            return SaveConflict(
                resolution_branch=target,
                current_sha=current,
                diff_url=build_compare_url(self._repo_url, request.original_sha, target),
            )
        return SaveOk(branch=target, new_sha=new_sha)

    def list_materials(self, branch: str | None = None) -> MaterialList:
        """Materiales ``.md`` de la rama, con título de la cabecera LiaScript.

        Alimenta el catálogo master del plugin (LIA-016): todos los ``.md``
        versionados salvo los ficheros de meta del repo (``README.md`` en
        raíz) se consideran materiales editables.
        """
        branch = branch or self._default_branch
        if not self._git.branch_exists(branch):
            raise EditError(404, f"la rama no existe: {branch!r}")
        head = self._git.get_commit(branch)
        files = [
            MaterialFile(path=path, title=self._material_title(branch, path))
            for path in self._git.list_files(branch)
            if path.lower().endswith(".md") and path != "README.md"
        ]
        return MaterialList(branch=branch, head=head, files=files)

    def _material_title(self, branch: str, path: str) -> str:
        """Título del material: ``title:`` de la cabecera LiaScript o el nombre."""
        fallback = path.rsplit("/", 1)[-1].removesuffix(".md")
        try:
            content = self._git.get_file(branch, path)
        except GitProviderError:
            return fallback
        header = _LIASCRIPT_HEADER_RE.match(content)
        if header:
            title = _LIASCRIPT_TITLE_RE.search(header.group(1))
            if title and title.group(1).strip():
                return title.group(1).strip().strip("'\"")
        return fallback

    def state(self, file: str, published_sha: str, branch: str | None = None) -> EditState:
        """Estado por comparación de SHAs (§5.3 de la v1): UP_TO_DATE / UPDATE_AVAILABLE."""
        branch = branch or self._default_branch
        file = validate_file_path(file)
        if not self._git.branch_exists(branch):
            raise EditError(404, f"la rama no existe: {branch!r}")
        head = self._git.get_commit(branch)
        ahead: int | None = None
        try:
            if self._git.is_ancestor(published_sha, branch):
                ahead = self._git.commits_ahead(published_sha, branch)
        except GitProviderError:
            ahead = None
        return EditState(
            file=file,
            branch=branch,
            published_sha=published_sha,
            head_sha=head,
            state="up_to_date" if head == published_sha else "update_available",
            commits_ahead=ahead,
        )

    def _file_exists(self, ref: str, file: str) -> bool:
        """``True`` si el fichero existe en ``ref`` (sin leer su contenido)."""
        parent = file.rpartition("/")[0]
        try:
            return file in self._git.list_files(ref, parent)
        except (GitProviderError, KeyError):
            return False
