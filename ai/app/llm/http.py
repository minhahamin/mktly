"""LLM, 임베딩 공급자 REST 호출 공통 처리."""

import httpx


class ProviderError(Exception):
    """공급자 호출 실패. retryable이면 다음 공급자로 넘길 수 있다(한도 초과, 서버 오류, 타임아웃, 쓸 수 없는 응답)."""

    def __init__(self, message: str, retryable: bool) -> None:
        super().__init__(message)
        self.retryable = retryable


def post_json(client: httpx.Client, url: str, headers: dict[str, str], payload: dict) -> dict:
    """공급자 REST 호출. 오류 메시지에 키나 응답 본문을 담지 않는다."""
    try:
        response = client.post(url, headers=headers, json=payload)
    except httpx.HTTPError as exc:  # 타임아웃, 연결 오류
        raise ProviderError("network", retryable=True) from exc

    if response.status_code == 429 or response.status_code >= 500:
        raise ProviderError(f"http {response.status_code}", retryable=True)

    if response.status_code >= 400:
        raise ProviderError(f"http {response.status_code}", retryable=False)

    try:
        return response.json()
    except ValueError as exc:
        raise ProviderError("invalid json", retryable=True) from exc
