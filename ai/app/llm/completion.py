"""LLM 텍스트 생성 호출. Gemini(무료), OpenAI(유료) 순서로 시도한다. 카피 생성과 카드뉴스 초안이 함께 쓴다."""

import logging
import os
from collections.abc import Callable
from typing import TypeVar

import httpx

from app.llm.http import ProviderError, post_json

logger = logging.getLogger(__name__)

GEMINI_URL = "https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent"
OPENAI_URL = "https://api.openai.com/v1/chat/completions"
DEFAULT_GEMINI_MODEL = "gemini-2.5-flash"
DEFAULT_OPENAI_MODEL = "gpt-4o-mini"
REQUEST_TIMEOUT = 25.0

T = TypeVar("T")


class CopyGenerationError(Exception):
    """LLM 응답을 사용할 수 없을 때 발생한다."""


def has_llm_keys() -> bool:
    """Gemini, OpenAI 키가 하나라도 있는지. 둘 다 없으면 호출자가 Mock 결과를 돌려준다."""
    return bool(os.getenv("GEMINI_API_KEY", "").strip() or os.getenv("OPENAI_API_KEY", "").strip())


def complete(
    system_prompt: str,
    user_prompt: str,
    *,
    allow_openai: bool,
    http_client: httpx.Client | None,
    parse: Callable[[str, str], T],
) -> T:
    """Gemini -> OpenAI 순서로 시도해 parse(응답 텍스트, 공급자)의 결과를 돌려준다. 키가 있는 공급자만 쓴다.

    한도 초과(429), 5xx, 타임아웃, parse가 CopyGenerationError를 내는 응답이면 다음 공급자로 넘기고, 인증 오류는 넘기지 않는다.
    """
    gemini_key = os.getenv("GEMINI_API_KEY", "").strip()
    openai_key = os.getenv("OPENAI_API_KEY", "").strip()
    providers: list[tuple[str, Callable[[httpx.Client], str]]] = []

    if gemini_key:
        providers.append(("gemini", lambda client: _call_gemini(client, gemini_key, system_prompt, user_prompt)))

    if openai_key and allow_openai:
        providers.append(("openai", lambda client: _call_openai(client, openai_key, system_prompt, user_prompt)))

    if not providers:
        raise CopyGenerationError("오늘 AI 생성 한도를 모두 사용했습니다. 내일 다시 시도해 주세요.")

    client = http_client or httpx.Client(timeout=REQUEST_TIMEOUT)

    try:
        for name, call in providers:
            try:
                return parse(call(client), name)
            except ProviderError as exc:
                logger.warning("AI 공급자 호출 실패: %s (다음 공급자로 넘김=%s)", name, exc.retryable)

                if not exc.retryable:
                    raise CopyGenerationError("AI 서비스 인증 또는 요청 설정에 문제가 있습니다. 운영자에게 문의해 주세요.") from exc
            except CopyGenerationError:
                logger.warning("AI 공급자 응답을 사용할 수 없음: %s", name)
    finally:
        if http_client is None:
            client.close()

    raise CopyGenerationError("AI 응답을 받지 못했습니다. 잠시 후 다시 시도해 주세요.")


def _call_gemini(client: httpx.Client, api_key: str, system_prompt: str, user_prompt: str) -> str:
    model = os.getenv("GEMINI_MODEL", DEFAULT_GEMINI_MODEL)
    data = post_json(
        client,
        GEMINI_URL.format(model=model),
        {"x-goog-api-key": api_key},
        {
            "systemInstruction": {"parts": [{"text": system_prompt}]},
            "contents": [{"role": "user", "parts": [{"text": user_prompt}]}],
            "generationConfig": {"maxOutputTokens": 4096, "responseMimeType": "application/json"},
        },
    )

    try:
        return "".join(part.get("text", "") for part in data["candidates"][0]["content"]["parts"])
    except (KeyError, IndexError, TypeError, AttributeError) as exc:  # 안전 필터 차단 등으로 후보가 없는 경우
        raise ProviderError("empty response", retryable=True) from exc


def _call_openai(client: httpx.Client, api_key: str, system_prompt: str, user_prompt: str) -> str:
    data = post_json(
        client,
        OPENAI_URL,
        {"Authorization": f"Bearer {api_key}"},
        {
            "model": os.getenv("OPENAI_MODEL", DEFAULT_OPENAI_MODEL),
            "max_completion_tokens": 1200,
            "response_format": {"type": "json_object"},
            "messages": [
                {"role": "system", "content": system_prompt},
                {"role": "user", "content": user_prompt},
            ],
        },
    )

    try:
        return data["choices"][0]["message"]["content"] or ""
    except (KeyError, IndexError, TypeError) as exc:
        raise ProviderError("empty response", retryable=True) from exc
