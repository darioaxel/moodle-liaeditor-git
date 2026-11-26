"""Servicio del flujo de edición LiaEditor (LIA-002/003/004/005).

Toda la lógica Git de la edición vive aquí (D2): ramas ``edicion/*``,
commits con la identidad del profesor y conflictos optimistic-locking por
SHA (D5). El plugin Moodle es una fachada (token, capacidades, registro de
sesión) que delega en estos métodos vía HTTP.
"""

from __future__ import annotations

import base64
import binascii
import posixpath
import re
from collections.abc import Callable
from datetime import datetime

import structlog

from materiales_liascript import images
from materiales_liascript.edit.branches import (
    EditError,
    edit_branch_name,
    resolution_branch_name,
    validate_file_path,
)
from materiales_liascript.edit.merge import diff3_segments, has_conflict_markers
from materiales_liascript.edit.models import (
    EditContent,
    EditState,
    MaterialFile,
    MaterialList,
    MergeRequest,
    SaveConflict,
    SaveOk,
    SaveRequest,
    SaveResult,
    SyncStatus,
)
from materiales_liascript.git.provider import BranchMovedError, GitProvider, GitProviderError

_log = structlog.get_logger(__name__)

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
        return EditContent(
            file=file,
            branch=branch,
            sha=sha,
            content=content,
            stale=stale,
            committed_at=self._git.commit_date(sha),
            last_author=self._git.last_author(sha, file),
        )

    def save(self, request: SaveRequest) -> SaveResult:
        """Guarda el contenido en la rama (D5: conflicto → rama ``resolucion/...``).

        Si el HEAD sigue siendo ``original_sha`` el commit va directo a la
        rama; si avanzó, el contenido se commitea en una rama de resolución
        creada desde el HEAD actual y el cliente decide cómo reconciliar.
        """
        file = validate_file_path(request.file)
        if not self._git.branch_exists(request.branch):
            raise EditError(404, f"la rama no existe: {request.branch!r}")
        uploads = self._uploads(file, request)
        files: dict[str, str | bytes | None] = {file: request.content, **uploads}
        current = self._git.get_commit(request.branch)
        # Imágenes que el material ha dejado de usar: se borran en el mismo commit.
        for path in self._dropped_images(file, request, current, set(uploads)):
            files[path] = None
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
            # Markdown e imágenes nuevas en un único commit (docs/imagenes, D2).
            new_sha = self._git.commit_files(
                target,
                files,
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
        return SaveOk(branch=target, new_sha=new_sha, committed_at=self._git.commit_date(new_sha))

    def _dropped_images(
        self, file: str, request: SaveRequest, head: str, uploads: set[str]
    ) -> list[str]:
        """Imágenes que el material usaba en ``original_sha`` y ya no usa.

        Solo se borran si existen en ``head`` y ningún otro ``.md`` del
        repositorio las referencia (dos materiales pueden compartir una
        imagen). Las que el material no referenciaba antes (añadidas a mano
        en Git) nunca se tocan.
        """
        try:
            before = self._git.get_file(request.original_sha, file)
        except GitProviderError:
            return []  # material nuevo o SHA desconocido: nada que comparar
        dropped = images.references(file, before) - images.references(file, request.content)
        dropped -= uploads
        if not dropped:
            return []
        present = set(self._git.list_files(head))
        dropped &= present
        for other in present:
            if not dropped:
                break
            if other == file or not other.lower().endswith(".md"):
                continue
            try:
                text = self._git.get_file(head, other)
            except GitProviderError:
                continue
            dropped -= images.references(other, text, confined=False)
        if dropped:
            _log.info("imagenes_borradas", file=file, imagenes=sorted(dropped))
        return sorted(dropped)

    @staticmethod
    def _uploads(file: str, request: SaveRequest) -> dict[str, bytes]:
        """Imágenes del guardado, validadas: ruta en la carpeta del material,
        formato que se puede subir, firma de bytes y tamaño (400 si no)."""
        uploads: dict[str, bytes] = {}
        total = 0
        for image in request.images:
            try:
                path = images.resolve(file, image.path)
                data = base64.b64decode(image.content_base64, validate=True)
                images.check(path, data, upload=True)
            except (images.ImageError, binascii.Error, ValueError) as exc:
                _log.info("imagen_subida_rechazada", file=file, ref=image.path, motivo=str(exc))
                raise EditError(400, f"imagen no válida: {image.path}") from exc
            total += len(data)
            if total > images.MAX_SAVE_BYTES:
                raise EditError(400, "las imágenes del guardado superan el tamaño máximo")
            uploads[path] = data
        return uploads

    def asset(self, file: str, branch: str, ref: str) -> tuple[bytes, str]:
        """Imagen ``ref`` (ruta del Markdown de ``file``) en ``branch``: bytes y tipo MIME.

        Solo imágenes de la carpeta del material, con extensión y firma de
        imagen (``materiales_liascript.images``). Cualquier fallo es 404 sin
        detalle, para no revelar qué existe en el repositorio.
        """
        file = validate_file_path(file)
        if not self._git.branch_exists(branch):
            raise EditError(404, "imagen no encontrada")
        try:
            path = images.resolve(file, ref)
            data = self._git.get_bytes(branch, path)
            mime = images.check(path, data)
        except (images.ImageError, GitProviderError) as exc:
            _log.info("imagen_rechazada", file=file, ref=ref, motivo=str(exc))
            raise EditError(404, "imagen no encontrada") from exc
        return data, mime

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
        """Estado de lo publicado (§5.3 de la v1): UP_TO_DATE / UPDATE_AVAILABLE.

        Hay actualización si la **carpeta del material** (su ``.md`` y sus
        imágenes: lo que entra en lo generado) cambió entre ``published_sha``
        y el HEAD de la rama. Un commit en ``main`` que solo toca otros
        materiales no deja desactualizado lo publicado de este.
        """
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
        changed = head != published_sha
        if changed:
            try:
                changed = self._git.paths_differ(published_sha, head, posixpath.dirname(file))
            except GitProviderError:
                changed = True  # SHA desconocido (p. ej. de una rama borrada): ante la duda, sí
        return EditState(
            file=file,
            branch=branch,
            published_sha=published_sha,
            head_sha=head,
            state="update_available" if changed else "up_to_date",
            commits_ahead=ahead,
            content_changed=changed,
        )

    # -- Incorporar main a la rama de edición (LIA-130) -------------------------

    def sync_status(self, file: str, branch: str) -> SyncStatus:  # noqa: PLR0911 - uno por estado
        """¿Trae ``main`` cambios del material que ``branch`` no tiene?

        Se fusiona en memoria (``git merge-tree``), sin tocar la rama. Solo
        cuentan los cambios de ``main`` en la carpeta del material: si main
        solo cambió otros materiales, no hay nada que incorporar.
        """
        file = validate_file_path(file)
        main = self._default_branch
        if not self._git.branch_exists(branch):
            raise EditError(404, f"la rama no existe: {branch!r}")
        if not self._git.branch_exists(main):
            raise EditError(503, f"no existe la rama principal: {main!r}")
        head = self._git.get_commit(branch)
        main_sha = self._git.get_commit(main)

        def status(state: str, **extra: object) -> SyncStatus:
            return SyncStatus.model_validate(
                {
                    "file": file,
                    "branch": branch,
                    "status": state,
                    "head_sha": head,
                    "main_sha": main_sha,
                    **extra,
                }
            )

        if branch == main:
            return status("not_applicable")
        base = self._git.merge_base(head, main_sha)
        if base == main_sha:
            return status("up_to_date")
        behind = self._git.commits_ahead(head, main_sha) if base else 0
        if base and not self._git.paths_differ(base, main_sha, posixpath.dirname(file)):
            return status("up_to_date", behind=behind)
        if not self._file_exists(main_sha, file):
            return status("conflict_other", behind=behind, conflicts=[file])
        merged = self._git.merge_tree(head, main_sha)
        ours = self._git.get_file(head, file)
        if not merged.conflicts:
            result = self._git.tree_file(merged.tree, file).decode("utf-8")
            return status("clean", behind=behind, ours=ours, merged=result)
        if merged.conflicts != (file,):
            return status("conflict_other", behind=behind, conflicts=list(merged.conflicts))
        original = self._git.get_file(base, file) if base and self._file_exists(base, file) else ""
        segments = diff3_segments(original, ours, self._git.get_file(main_sha, file))
        return status("conflict", behind=behind, conflicts=[file], segments=segments, ours=ours)

    def merge(self, request: MergeRequest) -> SaveOk:
        """Incorpora ``main`` a la rama de edición con un commit de fusión.

        Exige que la rama y ``main`` sigan donde los vio el editor (409 si
        no). Si hay conflicto en el material, ``content`` es su versión
        resuelta (sin marcas); si chocan otros ficheros, 409 (coordinación).
        """
        file = validate_file_path(request.file)
        main = self._default_branch
        if request.branch == main:
            raise EditError(400, "la rama principal no se fusiona consigo misma")
        if not self._git.branch_exists(request.branch):
            raise EditError(404, f"la rama no existe: {request.branch!r}")
        head = self._git.get_commit(request.branch)
        if head != request.head_sha:
            raise EditError(
                409, "la rama ha cambiado desde que se revisó: vuelve a cargar el material"
            )
        main_sha = self._git.get_commit(main)
        if main_sha != request.main_sha:
            raise EditError(
                409, "la versión oficial ha vuelto a cambiar: revisa de nuevo los cambios"
            )
        merged = self._git.merge_tree(head, main_sha)
        files: dict[str, str | bytes | None] = {}
        if merged.conflicts:
            if merged.conflicts != (file,) or request.content is None:
                raise EditError(409, "hay conflictos sin resolver")
            if has_conflict_markers(request.content):
                raise EditError(400, "el material conserva marcas de conflicto sin resolver")
            files[file] = request.content
        try:
            new_sha = self._git.commit_merge(
                request.branch,
                expected_head=head,
                theirs=main_sha,
                tree=merged.tree,
                files=files,
                message=f"Incorpora la versión oficial (main) a {request.branch}",
                author=f"{request.user} <{request.user}@liaeditor.local>",
            )
        except BranchMovedError as exc:
            raise EditError(
                409, "la rama ha cambiado durante la fusión: vuelve a cargar el material"
            ) from exc
        self._git.push_branch(request.branch)
        _log.info("main_incorporada", file=file, branch=request.branch, main=main_sha, sha=new_sha)
        return SaveOk(
            branch=request.branch, new_sha=new_sha, committed_at=self._git.commit_date(new_sha)
        )

    def _file_exists(self, ref: str, file: str) -> bool:
        """``True`` si el fichero existe en ``ref`` (sin leer su contenido)."""
        parent = file.rpartition("/")[0]
        try:
            return file in self._git.list_files(ref, parent)
        except (GitProviderError, KeyError):
            return False
