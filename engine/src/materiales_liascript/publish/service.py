"""Servicio de publicación: del ``.md`` en Git al artefacto (LIA-040, D7).

El executor es inyectable: en producción es ``LiaexDockerExecutor`` (corre
la imagen ``liascript/exporter`` sobre un checkout aislado del SHA); en
tests, un fake que devuelve bytes prefijados. Así el endpoint y la lógica
se verifican sin Docker.
"""

from __future__ import annotations

import shutil
import subprocess
from abc import ABC, abstractmethod
from pathlib import Path

from materiales_liascript.edit.branches import EditError, validate_file_path
from materiales_liascript.git.provider import GitProvider, GitProviderError
from materiales_liascript.publish.models import (
    ARTIFACT_MEDIA_TYPES,
    PublishError,
    PublishRequest,
    PublishResult,
)

_DOCKER_TIMEOUT = 300  # segundos por formato (spec mod_liamd RF-05)
_DOCKER_IMAGE = "liascript/exporter:latest"


class ArtifactExecutor(ABC):
    """Genera un artefacto desde el checkout de un ref."""

    @abstractmethod
    def generate(self, checkout: Path, file: str, artifact_format: str) -> bytes:
        """Devuelve los bytes del artefacto generado para ``file``."""


class LiaexDockerExecutor(ArtifactExecutor):
    """Ejecuta liaex (``liascript/exporter``) en Docker sobre el checkout.

    Asunciones documentadas (ajustar si el CLI cambia): el binario dentro de
    la imagen es ``liaex`` y acepta ``--input``/``--format``/``--output``.
    PDF necesita Chromium → se ejecuta con ``--shm-size=1g`` (spec §11).

    **Red**: por defecto el contenedor no tiene red (``none``). Los materiales
    con ``import:`` de plantillas LiaScript la necesitan, porque liaex las
    descarga en cada ejecución; en ese caso se configura ``network``
    (p. ej. ``bridge``) con ``MATERIALES_LIASCRIPT_LIAEX_NETWORK``.

    **Docker-in-docker**: cuando el engine corre en un contenedor y usa el
    demonio del host (socket montado), las rutas de ``-v`` las interpreta el
    host. Con ``workdir``/``host_workdir`` se traduce la ruta del checkout
    de la vista del contenedor a la del host (mismo bind mount en ambos,
    p. ej. ``/apps/engine-tmp`` host → ``/work-tmp`` contenedor, con
    ``TMPDIR`` apuntando ahí para que los worktrees caigan en él).
    """

    def __init__(  # noqa: PLR0913 - opciones de despliegue, todas con defecto
        self,
        image: str = _DOCKER_IMAGE,
        *,
        timeout: int = _DOCKER_TIMEOUT,
        docker_binary: str = "docker",
        workdir: str | None = None,
        host_workdir: str | None = None,
        network: str = "none",
    ) -> None:
        self._image = image
        self._network = network
        self._timeout = timeout
        self._docker = docker_binary
        self._workdir = workdir
        self._host_workdir = host_workdir

    def available(self) -> bool:
        """``True`` si hay binario docker usable en el host."""
        return shutil.which(self._docker) is not None

    def _host_path(self, checkout: Path) -> str:
        """Ruta del checkout tal como la ve el demonio Docker (el host)."""
        path = str(checkout)
        if self._workdir and self._host_workdir and path.startswith(self._workdir):
            return self._host_workdir + path[len(self._workdir) :]
        return path

    def generate(self, checkout: Path, file: str, artifact_format: str) -> bytes:
        # Nombre base SIN extensión: liaex añade la del formato. Si se le pasa
        # `artifact.pdf` genera `artifact.pdf.pdf` (igual con h5p; con epub
        # no), y el engine no encontraba el fichero (500 al publicar en PDF/H5P).
        output_base = "artifact"
        args = [
            self._docker,
            "run",
            "--rm",
            "--network",
            self._network,
            "-v",
            f"{self._host_path(checkout)}:/work",
        ]
        if artifact_format == "pdf":
            args += ["--shm-size=1g"]
        args += [
            self._image,
            "liaex",
            "--input",
            f"/work/{file}",
            "--format",
            artifact_format,
            "--output",
            f"/work/{output_base}",
        ]
        result = subprocess.run(
            args, capture_output=True, text=True, check=False, timeout=self._timeout
        )
        if result.returncode != 0:
            msg = f"liaex falló ({artifact_format}): {result.stderr.strip()[:500]}"
            raise PublishError(502, msg)
        produced = _find_artifact(checkout, output_base, artifact_format)
        try:
            if produced is None:
                msg = f"liaex no generó el fichero {artifact_format} esperado"
                raise PublishError(502, msg)
            return produced.read_bytes()
        finally:
            for leftover in checkout.glob(f"{output_base}*"):
                leftover.unlink(missing_ok=True)


def _find_artifact(directory: Path, base: str, artifact_format: str) -> Path | None:
    """Fichero generado por liaex: ``<base>.<formato>`` o, si una versión de
    liaex lo nombra de otra forma, cualquier ``<base>*.<formato>``."""
    expected = directory / f"{base}.{artifact_format}"
    if expected.is_file():
        return expected
    candidates = sorted(directory.glob(f"{base}*.{artifact_format}"))
    return candidates[0] if candidates else None


class PublishService:
    """Orquesta checkout del SHA + generación del artefacto."""

    def __init__(self, git: GitProvider, executor: ArtifactExecutor | None) -> None:
        self._git = git
        self._executor = executor

    def publish(self, request: PublishRequest) -> PublishResult:
        file = validate_file_path(request.file)
        if self._executor is None:
            raise PublishError(503, "publicación no disponible: no hay ejecutor de artefactos")
        try:
            self._git.get_commit(request.sha)
            self._git.get_file(request.sha, file)
        except (GitProviderError, EditError) as exc:
            raise PublishError(404, f"material no encontrado en {request.sha[:8]}: {file}") from exc
        with self._git.checkout_ref(request.sha) as checkout:
            content = self._executor.generate(checkout, file, request.format)
        slug = file.removesuffix(".md").replace("/", "-")
        return PublishResult(
            filename=f"{slug}@{request.sha[:8]}.{request.format}",
            media_type=ARTIFACT_MEDIA_TYPES[request.format],
            content=content,
        )
