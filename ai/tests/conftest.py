import pytest
from fastapi.testclient import TestClient

from app.main import app

TOKEN = "test-token"


@pytest.fixture(autouse=True)
def internal_token(monkeypatch):
    monkeypatch.setenv("AI_INTERNAL_TOKEN", TOKEN)
    monkeypatch.delenv("GEMINI_API_KEY", raising=False)
    monkeypatch.delenv("OPENAI_API_KEY", raising=False)


@pytest.fixture
def client():
    return TestClient(app)


@pytest.fixture
def auth():
    return {"X-Internal-Token": TOKEN}
