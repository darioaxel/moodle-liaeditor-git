"""``LocalGitProvider``: GitPython + plumbing de Git sobre un repo local.

Los worktrees temporales usan el CLI de Git (``git worktree add``): GitPython no
expone worktrees de forma fiable. El resto de operaciones van por la API tipada.

Hilos: FastAPI atiende las rutas síncronas en un pool de hilos y el provider es
único. GitPython lee los objetos por un ``git cat-file --batch`` persistente
compartido, que no admite lecturas simultáneas: con varias imágenes pedidas a la
vez (el editor pide todas al abrir) las respuestas se mezclaban («SHA … could
not be resolved», ramas «no encontradas», un PNG que «no es image/png») y los
hilos acababan bloqueados. Toda lectura con GitPython va bajo ``self._lock``;
las operaciones con subprocesos de ``git`` propios no lo necesitan.
"""

from __future__ import annotations

import os
import re
import subprocess
import tempfile
import threading
from collections.abc import Iterator, Mapping
from contextlib import contextmanager
from pathlib import Path

from git import Repo
from git.exc import BadName, GitCommandError, InvalidGitRepositoryError
from git.objects import Blob, Commit, Tree

from materiales_liascript.git.provider import BranchMovedError, GitProvider, GitProviderError

# Fecha ISO 8601 con zona horaria, como la da `git --format=%cI`.
_ISO_8601 = re.compile(r"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})")

# SHA del árbol vacío (constante bien conocida de Git), para crear
# directorios intermedios al commitear un archivo en una ruta nueva.
_EMPTY_TREE = "4b825dc642cb6eb9a060e54bf8d69288fbee4904"


class LocalGitProvider(GitProvider):
    """Implementación sobre un repositorio Git local."""

    def __init__(self, repo_path: Path) -> None:
        try:
            self._repo = Repo(repo_path, search_parent_directories=True)
        except InvalidGitRepositoryError as exc:
            msg = f"No es un repositorio Git: {repo_path}"
            raise GitProviderError(msg) from exc
        self._root = Path(self._repo.working_tree_dir or self._repo.git_dir)
        # Reentrante: is_ancestor → get_commit → _commit.
        self._lock = threading.RLock()

    # -- refs y checkouts -------------------------------------------------------

    @contextmanager
    def checkout_ref(self, ref: str) -> Iterator[Path]:
        tmp = Path(tempfile.mkdtemp(prefix="materiales-liascript-wt-"))
        worktree_path = tmp / "checkout"
        self._git("worktree", "add", "--detach", str(worktree_path), ref)
        try:
            yield worktree_path
        finally:
            subprocess.run(
                ["git", "-C", str(self._root), "worktree", "remove", "--force", str(worktree_path)],
                capture_output=True,
                check=False,
            )
            tmp.rmdir()

    # -- contenido ---------------------------------------------------------------

    def list_files(self, ref: str, path: str = "") -> list[str]:
        with self._lock:
            commit = self._commit(ref)
            tree = commit.tree / path if path else commit.tree
            return sorted(str(item.path) for item in self._walk(tree))

    def get_file(self, ref: str, path: str) -> str:
        return self.get_bytes(ref, path).decode("utf-8")

    def get_bytes(self, ref: str, path: str) -> bytes:
        with self._lock:
            commit = self._commit(ref)
            try:
                entry = commit.tree / path
            except KeyError as exc:
                msg = f"Archivo no encontrado en {ref}: {path}"
                raise GitProviderError(msg) from exc
            if entry.type != "blob":
                msg = f"No es un archivo en {ref}: {path}"
                raise GitProviderError(msg)
            data: bytes = entry.data_stream.read()
            return data

    def get_commit(self, ref: str) -> str:
        with self._lock:
            return str(self._commit(ref).hexsha)

    def commit_date(self, ref: str) -> str | None:
        try:
            value = self._git("show", "-s", "--format=%cI", f"{ref}^{{commit}}")
        except GitProviderError:
            return None
        return value if _ISO_8601.fullmatch(value) else None

    # -- historia --------------------------------------------------------------------

    def commits_ahead(self, base: str, ref: str) -> int:
        try:
            with self._lock:
                return len(list(self._repo.iter_commits(f"{base}..{ref}")))
        except GitCommandError as exc:
            msg = f"Referencia Git inválida: {exc}"
            raise GitProviderError(msg) from exc

    def is_ancestor(self, ref_a: str, ref_b: str) -> bool:
        with self._lock:
            sha_a = self.get_commit(ref_a)
            try:
                bases = self._repo.merge_base(ref_a, ref_b)
            except GitCommandError as exc:
                msg = f"Referencia Git inválida: {exc}"
                raise GitProviderError(msg) from exc
            return bool(bases) and bases[0].hexsha == sha_a

    # -- escritura (LIA-003/004) -------------------------------------------------

    def branch_exists(self, branch: str) -> bool:
        result = subprocess.run(
            [
                "git",
                "-C",
                str(self._root),
                "show-ref",
                "--verify",
                "--quiet",
                f"refs/heads/{branch}",
            ],
            capture_output=True,
            check=False,
        )
        return result.returncode == 0

    def create_branch(self, branch: str, from_ref: str) -> None:
        if self.branch_exists(branch):
            msg = f"La rama ya existe: {branch!r}"
            raise GitProviderError(msg)
        sha = self.get_commit(from_ref)
        self._git("update-ref", f"refs/heads/{branch}", sha)

    def commit_file(
        self,
        branch: str,
        path: str,
        content: str,
        message: str,
        author: str,
    ) -> str:
        return self.commit_files(branch, {path: content}, message, author)

    def commit_files(
        self,
        branch: str,
        files: Mapping[str, str | bytes],
        message: str,
        author: str,
    ) -> str:
        if not self.branch_exists(branch):
            msg = f"La rama no existe: {branch!r}"
            raise GitProviderError(msg)
        if not files:
            msg = "commit sin archivos"
            raise GitProviderError(msg)
        author_name, _, email_part = author.partition("<")
        author_email = email_part.rstrip("> ") or f"{author_name.strip()}@liaeditor.local"
        base = self.get_commit(branch)
        tree = f"{base}^{{tree}}"
        for path, content in files.items():
            data = content.encode("utf-8") if isinstance(content, str) else content
            blob = self._hash_blob(data)
            tree = self._replace_tree_entry(tree, path.split("/"), blob)
        env = {
            "GIT_AUTHOR_NAME": author_name.strip(),
            "GIT_AUTHOR_EMAIL": author_email,
            "GIT_COMMITTER_NAME": "materiales-liascript-git",
            "GIT_COMMITTER_EMAIL": "engine@materiales-liascript.local",
        }
        commit = self._git_in(["commit-tree", tree, "-p", base], message, env=env)
        try:
            self._git("update-ref", f"refs/heads/{branch}", commit, base)
        except GitProviderError as exc:
            paths = ", ".join(files)
            raise BranchMovedError(
                f"la rama {branch!r} avanzó durante el commit de {paths}"
            ) from exc
        return commit

    def _hash_blob(self, data: bytes) -> str:
        """Escribe ``data`` como blob (binario intacto) y devuelve su SHA."""
        result = subprocess.run(
            ["git", "-C", str(self._root), "hash-object", "-w", "--stdin"],
            input=data,
            capture_output=True,
            check=False,
        )
        if result.returncode != 0:
            msg = f"git hash-object falló: {result.stderr.decode(errors='replace').strip()}"
            raise GitProviderError(msg)
        return result.stdout.decode().strip()

    def push_branch(self, branch: str) -> None:
        with self._lock:
            has_origin = any(remote.name == "origin" for remote in self._repo.remotes)
        if not has_origin:
            return
        self._git("push", "origin", f"refs/heads/{branch}:refs/heads/{branch}")

    # -- internos ------------------------------------------------------------------

    def _commit(self, ref: str) -> Commit:
        try:
            with self._lock:
                commit = self._repo.commit(ref)
                _ = commit.tree  # fuerza la resolución: un SHA de 40 hex es lazy
                return commit
        except (BadName, ValueError) as exc:
            msg = f"Referencia Git no encontrada: {ref!r}"
            raise GitProviderError(msg) from exc

    def _git(self, *args: str) -> str:
        return self._git_in(list(args), None)

    def _git_in(self, args: list[str], stdin: str | None, env: dict[str, str] | None = None) -> str:
        """Ejecuta ``git`` en el repo con stdin/env opcionales; error → ``GitProviderError``."""
        full_env = {**os.environ, **env} if env else None
        result = subprocess.run(
            ["git", "-C", str(self._root), *args],
            input=stdin,
            capture_output=True,
            text=True,
            check=False,
            env=full_env,
        )
        if result.returncode != 0:
            msg = f"git {' '.join(args)} falló: {result.stderr.strip()}"
            raise GitProviderError(msg)
        return result.stdout.strip()

    def _replace_tree_entry(self, tree_ref: str, parts: list[str], blob_sha: str) -> str:
        """Árbol nuevo igual que ``tree_ref`` pero con ``blob_sha`` en ``parts``.

        Reescribe recursivamente con ``git mktree`` solo los árboles del camino
        del archivo; crea los directorios intermedios si la ruta es nueva.
        Devuelve el SHA del árbol raíz resultante.
        """
        tree_sha = self._git("rev-parse", tree_ref) if "^{" in tree_ref else tree_ref
        lines = self._git("ls-tree", tree_sha).splitlines()
        head, rest = parts[0], parts[1:]
        entries: list[str] = []
        found = False
        for line in lines:
            meta, name = line.split("\t", 1)
            if name != head:
                entries.append(line)
                continue
            found = True
            if not rest:
                entries.append(f"100644 blob {blob_sha}\t{name}")
                continue
            mode, entry_type, sha = meta.split()
            if entry_type != "tree":
                msg = f"{'/'.join(parts)} no es un directorio en {tree_ref}"
                raise GitProviderError(msg)
            sub = self._replace_tree_entry(sha, rest, blob_sha)
            entries.append(f"{mode} tree {sub}\t{name}")
        if not found:
            if not rest:
                entries.append(f"100644 blob {blob_sha}\t{head}")
            else:
                sub = self._replace_tree_entry(_EMPTY_TREE, rest, blob_sha)
                entries.append(f"040000 tree {sub}\t{head}")
        # mktree exige las entradas ordenadas; los directorios se ordenan
        # como si el nombre acabara en '/' (regla de Git).
        entries.sort(key=lambda e: e.split("\t", 1)[1] + ("/" if " tree " in e else ""))
        return self._git_in(["mktree"], "\n".join(entries) + "\n")

    @staticmethod
    def _walk(tree: Tree) -> Iterator[Blob]:
        for item in tree:
            if isinstance(item, Tree):
                yield from LocalGitProvider._walk(item)
            elif isinstance(item, Blob):
                yield item
