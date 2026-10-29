import json

import httpx
import numpy as np
import pytest

from app.llm import embed as embed_module
from app.llm.embed import DIMENSIONS, EmbeddingError, embed_texts, hash_vector
from app.llm.retrieve import Indexed, top_k


def fake_vector(seed: int = 1) -> list[float]:
    return np.random.default_rng(seed).normal(size=DIMENSIONS).tolist()


def gemini_body(count: int):
    return {"embeddings": [{"values": fake_vector(i)} for i in range(count)]}


def openai_body(count: int):
    # 응답 순서가 뒤집혀도 index로 맞춰야 한다.
    return {"data": [{"index": i, "embedding": fake_vector(i)} for i in reversed(range(count))]}


def make_client(gemini=None, openai=None):
    """공급자별 (status, json) 응답을 돌려주는 가짜 HTTP 클라이언트. 호출 기록은 client.calls에 쌓는다."""
    calls = []

    def handler(request):
        host = request.url.host
        calls.append({"host": host, "url": str(request.url), "headers": dict(request.headers), "json": json.loads(request.content)})
        spec = gemini if "googleapis" in host else openai

        if isinstance(spec, Exception):
            raise spec

        status, body = spec
        return httpx.Response(status, json=body)

    client = httpx.Client(transport=httpx.MockTransport(handler))
    client.calls = calls
    return client


@pytest.fixture
def both_keys(monkeypatch):
    monkeypatch.setenv("GEMINI_API_KEY", "gem-secret")
    monkeypatch.setenv("OPENAI_API_KEY", "oai-secret")


def cosine(a, b):
    return float(np.dot(a, b) / (np.linalg.norm(a) * np.linalg.norm(b)))


def test_without_keys_uses_hash_vectors_without_any_call():
    client = make_client()

    vectors, provider = embed_texts(["가을 신상 코트", "겨울 패딩"], http_client=client)

    assert provider == "hash"
    assert len(vectors) == 2 and all(len(v) == DIMENSIONS for v in vectors)
    assert client.calls == []


def test_hash_vector_is_deterministic_and_normalized():
    first, second = hash_vector("가을 신상 코트 할인"), hash_vector("가을 신상 코트 할인")

    assert first == second
    assert np.linalg.norm(first) == pytest.approx(1.0)


def test_hash_vector_ranks_shared_words_higher():
    query = hash_vector("가을 신상 코트 안내")
    related = hash_vector("가을 신상 코트 할인 소식")
    unrelated = hash_vector("정기 구독 서비스 환불 정책")

    assert cosine(query, related) > cosine(query, unrelated) + 0.2


def test_hash_vector_handles_edge_inputs():
    assert len(hash_vector("가")) == DIMENSIONS and np.linalg.norm(hash_vector("가")) == pytest.approx(1.0)
    assert hash_vector("   ") == [0.0] * DIMENSIONS
    assert hash_vector("  Coat \n COAT ") == hash_vector("coat coat")


def test_gemini_is_used_first_with_requested_dimensions(monkeypatch):
    monkeypatch.setenv("GEMINI_API_KEY", "gem-secret")
    client = make_client(gemini=(200, gemini_body(2)))

    vectors, provider = embed_texts(["a", "b"], http_client=client)

    assert provider == "gemini"
    assert [c["host"] for c in client.calls] == ["generativelanguage.googleapis.com"]
    call = client.calls[0]
    assert "gemini-embedding-001:batchEmbedContents" in call["url"]
    assert call["headers"]["x-goog-api-key"] == "gem-secret"
    assert [r["outputDimensionality"] for r in call["json"]["requests"]] == [DIMENSIONS, DIMENSIONS]
    assert np.linalg.norm(vectors[0]) == pytest.approx(1.0)


@pytest.mark.parametrize(
    "failure",
    [
        (429, {"error": "quota"}),
        (503, {"error": "busy"}),
        httpx.ReadTimeout("timeout"),
        (200, {"embeddings": []}),
        (200, {"unexpected": True}),
        (200, {"embeddings": [{"values": [0.1, 0.2]}] * 2}),  # 차원이 맞지 않는 응답
    ],
)
def test_falls_back_to_openai_when_gemini_is_unavailable(both_keys, failure):
    client = make_client(gemini=failure, openai=(200, openai_body(2)))

    vectors, provider = embed_texts(["a", "b"], http_client=client)

    assert provider == "openai"
    assert [c["host"] for c in client.calls] == ["generativelanguage.googleapis.com", "api.openai.com"]
    assert client.calls[1]["headers"]["authorization"] == "Bearer oai-secret"
    assert client.calls[1]["json"]["dimensions"] == DIMENSIONS
    # index 순서대로 되돌려 놓았는지 확인한다.
    assert vectors[0] == pytest.approx(np.asarray(fake_vector(0)) / np.linalg.norm(fake_vector(0)))


def test_falls_back_to_hash_when_every_provider_is_unavailable(both_keys):
    client = make_client(gemini=(500, {}), openai=(429, {}))

    vectors, provider = embed_texts(["가을 코트"], http_client=client)

    assert provider == "hash"
    assert vectors == [hash_vector("가을 코트")]
    assert len(client.calls) == 2


def test_auth_error_does_not_fall_back(both_keys):
    client = make_client(gemini=(403, {"error": "bad key"}), openai=(200, openai_body(1)))

    with pytest.raises(EmbeddingError):
        embed_texts(["a"], http_client=client)

    assert [c["host"] for c in client.calls] == ["generativelanguage.googleapis.com"]


def test_openai_only_when_gemini_key_is_absent(monkeypatch):
    monkeypatch.setenv("OPENAI_API_KEY", "oai-secret")
    client = make_client(openai=(200, openai_body(1)))

    assert embed_texts(["a"], http_client=client)[1] == "openai"


def test_openai_is_skipped_when_not_allowed(both_keys):
    client = make_client(gemini=(429, {}), openai=(200, openai_body(1)))

    _, provider = embed_texts(["a"], allow_openai=False, http_client=client)

    assert provider == "hash"
    assert [c["host"] for c in client.calls] == ["generativelanguage.googleapis.com"]


def test_external_calls_are_skipped_when_not_allowed(both_keys):
    client = make_client(gemini=(200, gemini_body(1)), openai=(200, openai_body(1)))

    _, provider = embed_texts(["a"], allow_external=False, http_client=client)

    assert provider == "hash"
    assert client.calls == []


def test_endpoint_returns_hash_vectors_in_mock_mode(client, auth):
    res = client.post("/embed", headers=auth, json={"texts": ["가을 코트", "겨울 패딩"]})

    assert res.status_code == 200
    data = res.json()
    assert data["provider"] == "hash" and data["dimensions"] == DIMENSIONS
    assert len(data["vectors"]) == 2 and len(data["vectors"][0]) == DIMENSIONS


@pytest.mark.parametrize(
    "payload",
    [{}, {"texts": []}, {"texts": [""]}, {"texts": ["  "]}, {"texts": ["x" * 4001]}, {"texts": ["x"] * 65}, {"texts": [1]}],
)
def test_endpoint_validates_input(client, auth, payload):
    assert client.post("/embed", headers=auth, json=payload).status_code == 422


def test_endpoint_requires_token(client):
    assert client.post("/embed", json={"texts": ["x"]}).status_code == 401


def test_endpoint_failure_returns_502_without_leaking_secrets(client, auth, monkeypatch):
    monkeypatch.setenv("GEMINI_API_KEY", "gem-secret")
    monkeypatch.setattr(embed_module, "_call_gemini", lambda *a, **k: (_ for _ in ()).throw(embed_module.ProviderError("http 403", False)))

    res = client.post("/embed", headers=auth, json={"texts": ["코트"]})

    assert res.status_code == 502
    assert "gem-secret" not in res.text


def test_top_k_orders_by_similarity_and_keeps_input_order_on_ties():
    query = [1.0, 0.0, 0.0]
    candidates = [
        Indexed("far", [0.0, 1.0, 0.0], "gemini"),
        Indexed("close", [0.9, 0.1, 0.0], "gemini"),
        Indexed("tie-a", [0.5, 0.5, 0.0], "gemini"),
        Indexed("tie-b", [1.0, 1.0, 0.0], "gemini"),
    ]

    result = top_k(query, "gemini", candidates, k=3)

    assert [item for item, _ in result] == ["close", "tie-a", "tie-b"]
    assert result[1][1] == pytest.approx(result[2][1])


def test_top_k_only_compares_same_provider_and_dimension():
    query = [1.0, 0.0]
    candidates = [
        Indexed("other-provider", [1.0, 0.0], "openai"),
        Indexed("other-dimension", [1.0, 0.0, 0.0], "gemini"),
        Indexed("match", [0.8, 0.2], "gemini"),
    ]

    assert [item for item, _ in top_k(query, "gemini", candidates, k=5)] == ["match"]


def test_top_k_drops_non_positive_scores_and_handles_empty_input():
    candidates = [Indexed("opposite", [-1.0, 0.0], "hash"), Indexed("zero", [0.0, 0.0], "hash")]

    assert top_k([1.0, 0.0], "hash", candidates, k=3) == []
    assert top_k([1.0, 0.0], "hash", [], k=3) == []
    assert top_k([0.0, 0.0], "hash", candidates, k=3) == []
    assert top_k([1.0, 0.0], "hash", [Indexed("a", [1.0, 0.0], "hash")], k=0) == []
    assert top_k([1.0, 0.0], "hash", [Indexed("a", [0.3, 0.9], "hash")], k=3, min_score=0.5) == []


def test_top_k_with_hash_vectors_finds_the_related_text():
    corpus = {"coat": "가을 신상 코트 할인 소식", "sub": "정기 구독 서비스 환불 정책", "mail": "이메일 뉴스레터 구독 안내"}
    candidates = [Indexed(key, hash_vector(text), "hash") for key, text in corpus.items()]

    result = top_k(hash_vector("가을 코트 신상품"), "hash", candidates, k=1)

    assert result[0][0] == "coat"
