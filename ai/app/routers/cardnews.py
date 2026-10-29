import base64

from fastapi import APIRouter, Depends, HTTPException

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
    rendered = render_slides(
        [(slide.title, slide.body) for slide in request.slides],
        kind=request.kind,
        ratio=request.ratio,
        seed=request.seed,
    )

    return CardnewsRenderResponse(
        images=[
            RenderedImage(index=index, width=png.width, height=png.height, png_base64=base64.b64encode(png.data).decode("ascii"))
            for index, png in enumerate(rendered)
        ],
        background_provider="template",
    )
