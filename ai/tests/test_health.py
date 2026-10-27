def test_health(client):
    res = client.get("/health")
    assert res.status_code == 200
    assert res.json()["status"] == "ok"


def test_ping_requires_token(client, auth):
    assert client.get("/ping").status_code == 401
    assert client.get("/ping", headers={"X-Internal-Token": "wrong"}).status_code == 401
    assert client.get("/ping", headers=auth).status_code == 200


def test_all_endpoints_reject_missing_token(client):
    assert client.post("/copy/generate", json={"product": "x"}).status_code == 401
    assert client.post("/predict/churn", json={"contacts": []}).status_code == 401
    assert client.post("/cluster", json={"contacts": []}).status_code == 401


def test_rejects_everything_when_token_is_not_configured(client, monkeypatch):
    monkeypatch.delenv("AI_INTERNAL_TOKEN")
    assert client.get("/ping", headers={"X-Internal-Token": ""}).status_code == 401
