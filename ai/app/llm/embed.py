"""텍스트 임베딩. Gemini(무료), OpenAI(유료) 순서로 시도하고, 모두 쓸 수 없으면 로컬 해시 벡터를 쓴다."""

import hashlib
import logging
import os
import re
from collections.abc import Callable

import httpx
import numpy as np

from app.llm.http import ProviderError, post_json

logger = logging.getLogger(__name__)

DIMENSIONS = 256
GEMINI_URL = "https://generativelanguage.googleapis.com/v1beta/models/{model}:batchEmbedContents"
OPENAI_URL = "https://api.openai.com/v1/embeddings"
DEFAULT_GEMINI_MODEL = "gemini-embedding-001"
DEFAULT_OPENAI_MODEL = "text-embedding-3-small"
REQUEST_TIMEOUT = 25.0
HASH_NGRAMS = (2, 3)


class EmbeddingError(Exception):
    """임베딩을 만들 수 없을 때 발생한다(인증 오류 등 다음 공급자로 넘기면 안 되는 경우)."""


def embed_texts(
    texts: list[str],
    *,
    allow_openai: bool = True,
    allow_external: bool = True,
    http_client: httpx.Client | None = None,
) -> tuple[list[list[float]], str]:
    """(벡터 목록, 공급자)를 돌려준다. Gemini -> OpenAI -> 해시 순서이며 키가 있는 공급자만 쓴다.

    allow_external이 False이면 외부 호출 없이 해시 벡터를 만든다(일일 상한 도달 시 Laravel이 지정).
    한도 초과, 5xx, 타임아웃, 쓸 수 없는 응답이면 다음 공급자로 넘기고, 인증 오류는 넘기지 않는다.
    """
    gemini_key = os.getenv("GEMINI_API_KEY", "").strip()
    openai_key = os.getenv("OPENAI_API_KEY", "").strip()
    providers: list[tuple[str, Callable[[httpx.Client], list[list[float]]]]] = []

    if allow_external and gemini_key:
        providers.append(("gemini", lambda client: _call_gemini(client, gemini_key, texts)))

    if allow_external and allow_openai and openai_key:
        providers.append(("openai", lambda client: _call_openai(client, openai_key, texts)))

    if providers:
        client = http_client or httpx.Client(timeout=REQUEST_TIMEOUT)

        try:
            for name, call in providers:
                try:
                    return call(client), name
                except ProviderError as exc:
                    logger.warning("임베딩 공급자 호출 실패: %s (다음 공급자로 넘김=%s)", name, exc.retryable)

                    if not exc.retryable:
                        raise EmbeddingError("AI 서비스 인증 또는 요청 설정에 문제가 있습니다. 운영자에게 문의해 주세요.") from exc
        finally:
            if http_client is None:
                client.close()

    return [hash_vector(text) for text in texts], "hash"


def hash_vector(text: str) -> list[float]:
    """한국어 문자 n-gram을 해시해 만든 256차원 벡터. 외부 호출이 없고 같은 입력이면 항상 같은 값이다.

    의미 유사도가 아니라 표면(글자 조각) 유사도라서 같은 단어를 공유하는 문장끼리만 가깝게 나온다.
    """
    normalized = re.sub(r"\s+", " ", text.lower()).strip()
    vector = np.zeros(DIMENSIONS)

    grams = [normalized[i : i + n] for n in HASH_NGRAMS for i in range(len(normalized) - n + 1)]

    if not grams and normalized:
        grams = [normalized]

    for gram in grams:
        value = int.from_bytes(hashlib.blake2b(gram.encode("utf-8"), digest_size=8).digest(), "big")
        vector[value % DIMENSIONS] += 1.0 if (value >> 40) & 1 else -1.0

    return _unit(vector)


def _unit(values) -> list[float]:
    vector = np.asarray(values, dtype=float)
    norm = float(np.linalg.norm(vector))

    return (vector / norm if norm else vector).tolist()


def _checked(vectors: list, expected: int) -> list[list[float]]:
    """개수와 차원이 맞는지 확인하고 단위 벡터로 맞춘다. 맞지 않으면 쓸 수 없는 응답으로 본다."""
    if len(vectors) != expected or any(len(v) != DIMENSIONS for v in vectors):
        raise ProviderError("unusable embeddings", retryable=True)

    return [_unit(v) for v in vectors]


def _call_gemini(client: httpx.Client, api_key: str, texts: list[str]) -> list[list[float]]:
    model = os.getenv("GEMINI_EMBED_MODEL", DEFAULT_GEMINI_MODEL)
    data = post_json(
        client,
        GEMINI_URL.format(model=model),
        {"x-goog-api-key": api_key},
        {
            "requests": [
                {
                    "model": f"models/{model}",
                    "content": {"parts": [{"text": text}]},
                    "outputDimensionality": DIMENSIONS,
                }
                for text in texts
            ]
        },
    )

    try:
        return _checked([item["values"] for item in data["embeddings"]], len(texts))
    except (KeyError, TypeError) as exc:
        raise ProviderError("empty response", retryable=True) from exc


def _call_openai(client: httpx.Client, api_key: str, texts: list[str]) -> list[list[float]]:
    data = post_json(
        client,
        OPENAI_URL,
        {"Authorization": f"Bearer {api_key}"},
        {"model": os.getenv("OPENAI_EMBED_MODEL", DEFAULT_OPENAI_MODEL), "input": texts, "dimensions": DIMENSIONS},
    )

    try:
        items = sorted(data["data"], key=lambda item: item["index"])

        return _checked([item["embedding"] for item in items], len(texts))
    except (KeyError, TypeError) as exc:
        raise ProviderError("empty response", retryable=True) from exc
