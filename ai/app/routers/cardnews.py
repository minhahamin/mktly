import base64

from fastapi import APIRouter, Depends, HTTPException

from app.image.background import generate_background
from app.image.template import render_slides
from app.llm.cardnews import draft_cardnews
from app.llm.completion import CopyGenerationError
from app.schemas import (
    CardnewsDraftRequest,
    CardnewsDraftResponse,
    CardnewsRenderRequest,
    CardnewsRenderResponse,
    RenderedImage,
)
from app.security import verify_internal_token

router = APIRouter(prefix="/cardnews", tags=["cardnews"], dependencies=[Depends(verify_internal_token)])


@router.post("/draft", response_model=CardnewsDraftResponse)
def draft(request: CardnewsDraftRequest) -> CardnewsDraftResponse:
    try:
        return draft_cardnews(request)
    except CopyGenerationError as exc:
        raise HTTPException(status_code=502, detail=str(exc)) from exc


@router.post("/render", response_model=CardnewsRenderResponse)
def render(request: CardnewsRenderRequest) -> CardnewsRenderResponse:
    # 배경 삽화는 세트당 한 번만 요청한다. 쓸 수 없으면 None이 와서 템플릿 배경으로 그린다.
    art = generate_background(request.topic, request.ratio) if request.ai_background else None
    rendered = render_slides(
        [(slide.title, slide.body) for slide in request.slides],
        kind=request.kind,
        ratio=request.ratio,
        seed=request.seed,
        art=art,
    )

    return CardnewsRenderResponse(
        images=[
            RenderedImage(index=index, width=png.width, height=png.height, png_base64=base64.b64encode(png.data).decode("ascii"))
            for index, png in enumerate(rendered)
        ],
        background_provider="template" if art is None else "gemini",
    )
