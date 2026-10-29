from fastapi import APIRouter, Depends, HTTPException

from app.llm.embed import DIMENSIONS, EmbeddingError, embed_texts
from app.schemas import EmbedRequest, EmbedResponse
from app.security import verify_internal_token

router = APIRouter(tags=["embed"], dependencies=[Depends(verify_internal_token)])


@router.post("/embed", response_model=EmbedResponse)
def embed(request: EmbedRequest) -> EmbedResponse:
    try:
        vectors, provider = embed_texts(
            request.texts,
            allow_openai=request.allow_openai,
            allow_external=request.allow_external,
        )
    except EmbeddingError as exc:
        raise HTTPException(status_code=502, detail=str(exc)) from exc

    return EmbedResponse(vectors=vectors, provider=provider, dimensions=DIMENSIONS)
