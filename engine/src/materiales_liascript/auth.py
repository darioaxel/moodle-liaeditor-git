"""Autenticación de las peticiones del plugin al engine (LIA-060).

El plugin y el engine comparten un secreto (ajuste del plugin y
``MATERIALES_LIASCRIPT_SHARED_SECRET``). El plugin firma cada petición con
HMAC-SHA256 sobre el texto canónico::

    MÉTODO \\n RUTA[?QUERY] \\n MARCA_DE_TIEMPO \\n SHA256(CUERPO) en hexadecimal

y lo envía en las cabeceras ``X-Liamat-Timestamp`` (segundos Unix) y
``X-Liamat-Signature`` (hexadecimal). El engine:

- rechaza con 401 las peticiones sin firma, con firma errónea, con una marca
  de tiempo a más de 60 s de su reloj o con una firma ya usada (réplica);
- compara en tiempo constante (``hmac.compare_digest``);
- deja pasar sin firma solo ``GET /health`` (healthcheck del contenedor).

Es un middleware ASGI puro: lee el cuerpo para firmarlo y se lo vuelve a
entregar intacto a la aplicación.
"""

from __future__ import annotations

import hashlib
import hmac
import json
import threading
import time
from collections.abc import Awaitable, Callable, MutableMapping
from typing import Any

import structlog

Scope = MutableMapping[str, Any]
Message = MutableMapping[str, Any]
Receive = Callable[[], Awaitable[Message]]
Send = Callable[[Message], Awaitable[None]]
ASGIApp = Callable[[Scope, Receive, Send], Awaitable[None]]

TIMESTAMP_HEADER = "x-liamat-timestamp"
SIGNATURE_HEADER = "x-liamat-signature"
#: Diferencia máxima entre la marca de tiempo de la petición y el reloj del engine.
MAX_SKEW = 60
#: Rutas sin firma (método, ruta).
PUBLIC = frozenset({("GET", "/health")})

_log = structlog.get_logger(__name__)


def canonical(method: str, target: str, timestamp: str, body: bytes) -> bytes:
    """Texto que se firma (mismo formato que ``engine_client`` del plugin)."""
    digest = hashlib.sha256(body).hexdigest()
    return f"{method.upper()}\n{target}\n{timestamp}\n{digest}".encode()


def sign(secret: str, method: str, target: str, timestamp: str, body: bytes = b"") -> str:
    """Firma HMAC-SHA256 en hexadecimal."""
    return hmac.new(
        secret.encode(), canonical(method, target, timestamp, body), hashlib.sha256
    ).hexdigest()


class _SeenSignatures:
    """Firmas aceptadas en la ventana de validez (para rechazar réplicas)."""

    def __init__(self) -> None:
        self._seen: dict[str, float] = {}
        self._lock = threading.Lock()

    def add(self, signature: str, now: float) -> bool:
        """Registra la firma; ``False`` si ya se había usado."""
        with self._lock:
            for old, at in list(self._seen.items()):
                if now - at > 2 * MAX_SKEW:
                    del self._seen[old]
            if signature in self._seen:
                return False
            self._seen[signature] = now
            return True


class SignatureMiddleware:
    """Exige la firma del plugin en todas las rutas salvo las públicas."""

    def __init__(self, app: ASGIApp, secret: str, clock: Callable[[], float] = time.time) -> None:
        if not secret:
            msg = "el secreto compartido no puede estar vacío"
            raise ValueError(msg)
        self._app = app
        self._secret = secret
        self._clock = clock
        self._seen = _SeenSignatures()

    async def __call__(self, scope: Scope, receive: Receive, send: Send) -> None:
        if scope["type"] != "http" or (scope["method"], scope["path"]) in PUBLIC:
            await self._app(scope, receive, send)
            return

        body = b""
        more = True
        while more:
            message = await receive()
            body += message.get("body", b"")
            more = bool(message.get("more_body"))

        motivo = self._check(scope, body)
        if motivo:
            _log.warning("peticion_rechazada", motivo=motivo, path=scope["path"])
            await _reject(send)
            return

        entregado = False

        async def replay() -> Message:
            nonlocal entregado
            if entregado:
                return await receive()
            entregado = True
            return {"type": "http.request", "body": body, "more_body": False}

        await self._app(scope, replay, send)

    def _check(self, scope: Scope, body: bytes) -> str | None:
        headers = {k.decode("latin-1").lower(): v.decode("latin-1") for k, v in scope["headers"]}
        timestamp = headers.get(TIMESTAMP_HEADER, "")
        signature = headers.get(SIGNATURE_HEADER, "")
        if not timestamp or not signature:
            return "sin firma"
        if not timestamp.isdigit():
            return "marca de tiempo inválida"
        now = self._clock()
        if abs(now - int(timestamp)) > MAX_SKEW:
            return "marca de tiempo fuera de plazo"
        query = scope.get("query_string", b"").decode("latin-1")
        target = scope.get("raw_path", scope["path"].encode()).decode("latin-1").split("?", 1)[0]
        target += f"?{query}" if query else ""
        expected = sign(self._secret, scope["method"], target, timestamp, body)
        if not hmac.compare_digest(expected, signature.lower()):
            return "firma errónea"
        if not self._seen.add(expected, now):
            return "firma repetida"
        return None


async def _reject(send: Send) -> None:
    body = json.dumps({"detail": "petición no autorizada"}).encode()
    await send(
        {
            "type": "http.response.start",
            "status": 401,
            "headers": [
                (b"content-type", b"application/json"),
                (b"content-length", str(len(body)).encode()),
            ],
        }
    )
    await send({"type": "http.response.body", "body": body})
