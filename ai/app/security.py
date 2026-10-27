import hmac
import os

from fastapi import Header, HTTPException


def verify_internal_token(x_internal_token: str | None = Header(default=None)) -> None:
    """Laravel만 호출할 수 있도록 내부 토큰을 검증한다. 토큰이 설정되지 않았다면 모두 거부한다."""
    expected = os.getenv("AI_INTERNAL_TOKEN", "")

    if not expected or not x_internal_token or not hmac.compare_digest(x_internal_token, expected):
        raise HTTPException(status_code=401, detail="invalid internal token")
