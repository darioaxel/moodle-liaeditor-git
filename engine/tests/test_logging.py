"""Tests del logging estructurado y el correlation id por petición."""

from __future__ import annotations

from pathlib import Path

from fastapi.testclient import TestClient

from materiales_liascript.app import create_app
from materiales_liascript.logging_config import (
    bound_correlation,
    correlation_id,
    new_correlation_id,
)


def test_bound_correlation_fija_y_resetea_la_contextvar() -> None:
    assert correlation_id.get() is None
    with bound_correlation("abc12345") as cid:
        assert cid == "abc12345"
        assert correlation_id.get() == "abc12345"
    assert correlation_id.get() is None


CID_LENGTH = 8


def test_bound_correlation_genera_id_corto_cuando_no_llega() -> None:
    assert len(new_correlation_id()) == CID_LENGTH
    with bound_correlation() as cid:
        assert len(cid) == CID_LENGTH
        assert correlation_id.get() == cid


def test_health_y_correlation_header(git_repo: Path) -> None:
    client = TestClient(create_app(git_repo))
    response = client.get("/health", headers={"X-Correlation-Id": "abc12345"})
    assert response.status_code == 200  # noqa: PLR2004
    assert response.json()["ok"] is True
    assert response.json()["git"] is True
    assert correlation_id.get() is None  # el middleware resetea al terminar
