from fastapi.testclient import TestClient

from app.main import app

client = TestClient(app)


def test_health():
    res = client.get("/health")
    assert res.status_code == 200
    assert res.json()["status"] == "ok"


def test_ping_requires_token(monkeypatch):
    monkeypatch.setenv("AI_INTERNAL_TOKEN", "secret")
    assert client.get("/ping").status_code == 401
    assert client.get("/ping", headers={"X-Internal-Token": "secret"}).status_code == 200
