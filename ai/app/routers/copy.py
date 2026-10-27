from fastapi import APIRouter, Depends, HTTPException

from app.llm.copy import CopyGenerationError, generate_copy
from app.schemas import CopyRequest, CopyResponse
from app.security import verify_internal_token

router = APIRouter(prefix="/copy", tags=["copy"], dependencies=[Depends(verify_internal_token)])


@router.post("/generate", response_model=CopyResponse)
def generate(request: CopyRequest) -> CopyResponse:
    try:
        return generate_copy(request)
    except CopyGenerationError as exc:
        raise HTTPException(status_code=502, detail=str(exc)) from exc
