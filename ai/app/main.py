from fastapi import Depends, FastAPI

from app.routers import analytics, copy
from app.security import verify_internal_token

app = FastAPI(title="Mktly AI Service")
app.include_router(copy.router)
app.include_router(analytics.router)


@app.get("/health")
def health() -> dict:
    return {"status": "ok", "service": "mktly-ai"}


@app.get("/ping", dependencies=[Depends(verify_internal_token)])
def ping() -> dict:
    return {"pong": True}
