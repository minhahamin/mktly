import os

from fastapi import Depends, FastAPI, Header, HTTPException

app = FastAPI(title="Mktly AI Service")


def verify_internal_token(x_internal_token: str | None = Header(default=None)) -> None:
    expected = os.getenv("AI_INTERNAL_TOKEN", "")
    if not expected or x_internal_token != expected:
        raise HTTPException(status_code=401, detail="invalid internal token")


@app.get("/health")
def health() -> dict:
    return {"status": "ok", "service": "mktly-ai"}


@app.get("/ping", dependencies=[Depends(verify_internal_token)])
def ping() -> dict:
    return {"pong": True}
