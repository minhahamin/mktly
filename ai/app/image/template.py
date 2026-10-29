"""카드뉴스 템플릿 렌더러. 파스텔 핑크 그라데이션 배경 위에 반투명 유리 카드를 올리고 글자를 그린다.

글자는 항상 여기서 그린다(이미지 모델이 그린 한글은 깨지기 쉽다). 외부 호출이 없어 키 없이 동작한다.
"""

import hashlib
import io
import random
from dataclasses import dataclass
from pathlib import Path

from PIL import Image, ImageDraw, ImageFilter, ImageFont

FONT_DIR = Path(__file__).resolve().parents[2] / "assets" / "fonts"
BOLD = FONT_DIR / "Pretendard-Bold.otf"
REGULAR = FONT_DIR / "Pretendard-Regular.otf"

SIZES = {"1:1": (1080, 1080), "4:5": (1080, 1350), "16:9": (1280, 720)}

# SPEC 3장 팔레트
PINK_50 = (255, 245, 248)
PINK_100 = (255, 228, 236)
PINK_300 = (249, 180, 200)
PINK_500 = (240, 122, 160)
INK_900 = (43, 34, 48)
INK_500 = (122, 111, 128)
LAVENDER = (214, 210, 247)
MINT = (205, 238, 220)
PEACH = (255, 222, 196)

LINE_SPACING = 1.38


@dataclass(frozen=True)
class RenderedPng:
    data: bytes
    width: int
    height: int


def render_slides(slides: list[tuple[str, str]], *, kind: str, ratio: str, seed: str = "") -> list[RenderedPng]:
    """(제목, 본문) 목록을 슬라이드별 PNG로 그린다. 같은 seed면 같은 배경이 나온다."""
    width, height = SIZES[ratio]
    total = len(slides)
    results = []

    for index, (title, body) in enumerate(slides):
        image = _background(width, height, seed, index)
        _glass_card(image)
        _draw_text(image, title, body, index=index, total=total, kind=kind)
        results.append(RenderedPng(_encode(image), width, height))

    return results


def _encode(image: Image.Image) -> bytes:
    """장당 약 100KB. 256색으로 줄이면 더 작지만 그라데이션에 계단이 생겨서 쓰지 않는다."""
    buffer = io.BytesIO()
    image.convert("RGB").save(buffer, "PNG", compress_level=9)

    return buffer.getvalue()


# ---- 배경 -------------------------------------------------------------------


def _background(width: int, height: int, seed: str, index: int) -> Image.Image:
    """핑크 바탕에 라벤더, 피치, 민트 blob을 흐리게 깐다. 유리 뒤에 색이 있어야 유리 효과가 보인다."""
    rng = random.Random(int.from_bytes(hashlib.sha256(f"{seed}:{index}".encode()).digest()[:8], "big"))
    scale = 4  # 작게 그려 흐리게 한 뒤 키우면 빠르다
    small = (width // scale, height // scale)
    layer = Image.new("RGBA", small, PINK_50 + (255,))
    draw = ImageDraw.Draw(layer)

    blobs = [(PINK_300, 0.62), (LAVENDER, 0.58), (PEACH, 0.55), (MINT, 0.5), (PINK_500, 0.28), (LAVENDER, 0.4)]

    for color, relative in blobs:
        radius = int(min(small) * relative * rng.uniform(0.8, 1.1))
        cx = rng.randint(-radius // 4, small[0] + radius // 4)
        cy = rng.randint(-radius // 4, small[1] + radius // 4)
        draw.ellipse((cx - radius, cy - radius, cx + radius, cy + radius), fill=color + (200,))

    layer = layer.filter(ImageFilter.GaussianBlur(radius=min(small) * 0.16))

    return layer.resize((width, height), Image.Resampling.BICUBIC).convert("RGBA")


def _card_box(width: int, height: int) -> tuple[int, int, int, int]:
    margin = int(min(width, height) * 0.07)

    return margin, margin, width - margin, height - margin


def _glass_card(image: Image.Image) -> None:
    """뒤 배경을 흐리게 하고 흰색을 덮은 반투명 유리 카드. 그림자, 흰 테두리, 윗부분 하이라이트를 더한다."""
    width, height = image.size
    box = _card_box(width, height)
    radius = int(min(width, height) * 0.06)

    shadow = Image.new("RGBA", image.size, (0, 0, 0, 0))
    ImageDraw.Draw(shadow).rounded_rectangle((box[0], box[1] + 14, box[2], box[3] + 14), radius, fill=(194, 71, 111, 52))
    image.alpha_composite(shadow.filter(ImageFilter.GaussianBlur(radius=int(min(width, height) * 0.03))))

    mask = Image.new("L", image.size, 0)
    ImageDraw.Draw(mask).rounded_rectangle(box, radius, fill=255)

    frosted = image.filter(ImageFilter.GaussianBlur(radius=int(min(width, height) * 0.025)))
    white = Image.new("RGBA", image.size, (255, 255, 255, 120))
    frosted.alpha_composite(white)
    image.paste(frosted, (0, 0), mask)

    edge = ImageDraw.Draw(image)
    edge.rounded_rectangle(box, radius, outline=(255, 255, 255, 230), width=3)
    inset = radius
    edge.line((box[0] + inset, box[1] + 6, box[2] - inset, box[1] + 6), fill=(255, 255, 255, 255), width=3)


# ---- 글자 -------------------------------------------------------------------


def _font(path: Path, size: int) -> ImageFont.FreeTypeFont:
    return ImageFont.truetype(str(path), size)


def _wrap(text: str, font: ImageFont.FreeTypeFont, max_width: int) -> list[str]:
    """공백 단위로 줄을 나누고, 한 단어가 한 줄보다 길면 글자 단위로 자른다. 문단(줄바꿈)은 유지한다."""
    lines: list[str] = []

    for paragraph in text.split("\n"):
        current = ""

        for word in paragraph.split(" "):
            candidate = f"{current} {word}".strip() if current else word

            if font.getlength(candidate) <= max_width:
                current = candidate
                continue

            if current:
                lines.append(current)
                current = ""

            while font.getlength(word) > max_width:
                cut = len(word)

                while cut > 1 and font.getlength(word[:cut]) > max_width:
                    cut -= 1

                lines.append(word[:cut])
                word = word[cut:]

            current = word

        lines.append(current)

    return [line for line in lines if line != ""] or [""]


def _fit(text: str, path: Path, max_width: int, max_height: int, start: int, minimum: int, max_lines: int) -> tuple[ImageFont.FreeTypeFont, list[str]]:
    """영역에 들어갈 때까지 글자 크기를 줄인다. 최소 크기에서도 넘치면 줄 수를 줄이고 마지막 줄을 잘라낸다."""
    size = start

    while True:
        font = _font(path, size)
        lines = _wrap(text, font, max_width)

        if len(lines) <= max_lines and len(lines) * size * LINE_SPACING <= max_height:
            return font, lines

        if size <= minimum:
            break

        size -= 2

    fits = max(1, min(max_lines, int(max_height // (size * LINE_SPACING))))
    lines = lines[:fits]
    last = lines[-1]

    while last and font.getlength(last + "...") > max_width:
        last = last[:-1]

    lines[-1] = last.rstrip() + "..."

    return font, lines


def _draw_text(image: Image.Image, title: str, body: str, *, index: int, total: int, kind: str) -> None:
    width, height = image.size
    unit = min(width, height)
    left, top, right, bottom = _card_box(width, height)
    padding = int(unit * 0.075)
    text_left = left + padding
    text_width = right - left - padding * 2
    draw = ImageDraw.Draw(image)

    # 위쪽: 쪽수 알약. 대표 이미지는 한 장이라 생략한다.
    top_y = top + padding
    pill_height = int(unit * 0.062)

    if kind == "cardnews":
        label = f"{index + 1} / {total}"
        pill_font = _font(BOLD, int(unit * 0.03))
        pill_width = int(pill_font.getlength(label) + unit * 0.06)
        draw.rounded_rectangle((text_left, top_y, text_left + pill_width, top_y + pill_height), pill_height // 2, fill=PINK_100 + (255,), outline=(255, 255, 255, 230), width=2)
        draw.text((text_left + pill_width / 2, top_y + pill_height / 2), label, font=pill_font, fill=INK_900, anchor="mm")

    # 표지와 마지막 장은 제목을 더 크게 쓴다.
    emphasized = kind == "cover" or index == 0 or index == total - 1
    area_top = top_y + pill_height + int(unit * 0.03)
    area_bottom = bottom - padding
    area_height = area_bottom - area_top
    bar_height = int(unit * 0.012)
    gap = int(unit * 0.035)

    body_budget = int(area_height * 0.38) if body.strip() else 0
    title_budget = area_height - body_budget - (bar_height + gap * 2 if body.strip() else 0)

    title_font, title_lines = _fit(
        title, BOLD, text_width, title_budget,
        start=int(unit * (0.098 if emphasized else 0.084)), minimum=int(unit * 0.05), max_lines=4,
    )
    title_height = int(len(title_lines) * title_font.size * LINE_SPACING)

    body_lines: list[str] = []
    body_font = None
    body_height = 0

    if body.strip():
        body_font, body_lines = _fit(body, REGULAR, text_width, body_budget, start=int(unit * 0.043), minimum=int(unit * 0.03), max_lines=5)
        body_height = int(len(body_lines) * body_font.size * LINE_SPACING)

    block_height = title_height + (gap * 2 + bar_height + body_height if body_lines else 0)
    y = area_top + max(0, (area_height - block_height) // 2)

    for line in title_lines:
        draw.text((text_left, y), line, font=title_font, fill=INK_900)
        y += int(title_font.size * LINE_SPACING)

    if body_lines and body_font is not None:
        y += gap
        draw.rounded_rectangle((text_left, y, text_left + int(unit * 0.11), y + bar_height), bar_height // 2, fill=PINK_500 + (255,))
        y += bar_height + gap

        for line in body_lines:
            draw.text((text_left, y), line, font=body_font, fill=INK_900)
            y += int(body_font.size * LINE_SPACING)
