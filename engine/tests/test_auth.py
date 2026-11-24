"""Firma HMAC entre plugin y engine (LIA-060)."""

from __future__ import annotations

import json
import time
from pathlib import Path

import pytest
from fastapi.testclient import TestClient

from materiales_liascript import serve
from materiales_liascript.app import create_app
from materiales_liascript.auth import canonical, sign

SECRET = "secreto-de-pruebas"
HTTP_OK = 200
HTTP_UNAUTHORIZED = 401


def firmado(
    method: str, target: str, body: bytes = b"", *, ts: int | None = None
) -> dict[str, str]:
    timestamp = str(int(time.time()) if ts is None else ts)
    return {
        "X-Liamat-Timestamp": timestamp,
        "X-Liamat-Signature": sign(SECRET, method, target, timestamp, body),
    }


@pytest.fixture()
def client(git_repo: Path) -> TestClient:
    return TestClient(create_app(git_repo, shared_secret=SECRET))


def test_texto_canonico_estable() -> None:
    # Mismo formato que engine_client del plugin (tests/engine_client_test.php).
    texto = canonical("post", "/edit/save", "1700000000", b'{"a":1}')
    assert texto == (
        b"POST\n/edit/save\n1700000000\n"
        b"015abd7f5cc57a2dd94b7590f04ad8084273905ee33ec5cebeae62276a97f862"
    )


def test_health_sin_firma(client: TestClient) -> None:
    assert client.get("/health").status_code == HTTP_OK


def test_sin_firma_401(client: TestClient) -> None:
    respuesta = client.get("/edit/files", params={"branch": "main"})
    assert respuesta.status_code == HTTP_UNAUTHORIZED
    assert respuesta.json() == {"detail": "petición no autorizada"}


def test_get_firmado_con_query(client: TestClient) -> None:
    target = "/edit/files?branch=main"
    respuesta = client.get(target, headers=firmado("GET", target))
    assert respuesta.status_code == HTTP_OK


def test_firma_erronea_401(client: TestClient) -> None:
    target = "/edit/files?branch=main"
    cabeceras = firmado("GET", target)
    cabeceras["X-Liamat-Signature"] = "0" * 64
    assert client.get(target, headers=cabeceras).status_code == HTTP_UNAUTHORIZED


def test_firma_de_otra_ruta_401(client: TestClient) -> None:
    cabeceras = firmado("GET", "/edit/files?branch=main")
    assert client.get("/edit/files?branch=otra", headers=cabeceras).status_code == HTTP_UNAUTHORIZED


def test_marca_de_tiempo_caducada_401(client: TestClient) -> None:
    target = "/edit/files?branch=main"
    cabeceras = firmado("GET", target, ts=int(time.time()) - 61)
    assert client.get(target, headers=cabeceras).status_code == HTTP_UNAUTHORIZED


def test_replica_401(client: TestClient) -> None:
    target = "/edit/files?branch=main"
    cabeceras = firmado("GET", target)
    assert client.get(target, headers=cabeceras).status_code == HTTP_OK
    assert client.get(target, headers=cabeceras).status_code == HTTP_UNAUTHORIZED


def test_post_firma_el_cuerpo(client: TestClient) -> None:
    body = json.dumps({"user": "ana", "file": "materiales/ut03-mvc/02-mvc.md"}).encode()
    cabeceras = {"Content-Type": "application/json", **firmado("POST", "/edit/open", body)}
    # Un cuerpo alterado con la firma del original no pasa (y no gasta la firma)…
    otro = body.replace(b"ana", b"eva")
    rechazada = client.post("/edit/open", content=otro, headers=cabeceras)
    assert rechazada.status_code == HTTP_UNAUTHORIZED
    # …y el original sí.
    assert client.post("/edit/open", content=body, headers=cabeceras).status_code == HTTP_OK


def test_sin_secreto_no_exige_firma(git_repo: Path) -> None:
    client = TestClient(create_app(git_repo))
    assert client.get("/edit/files", params={"branch": "main"}).status_code == HTTP_OK


def test_serve_exige_secreto(monkeypatch: pytest.MonkeyPatch, git_repo: Path) -> None:
    monkeypatch.setenv("CONTENT_REPO", str(git_repo))
    monkeypatch.delenv("MATERIALES_LIASCRIPT_SHARED_SECRET", raising=False)
    monkeypatch.delenv("MATERIALES_LIASCRIPT_ALLOW_UNSIGNED", raising=False)
    with pytest.raises(SystemExit, match="SHARED_SECRET"):
        serve.build_app()
    monkeypatch.setenv("MATERIALES_LIASCRIPT_ALLOW_UNSIGNED", "1")
    assert serve.build_app() is not None
