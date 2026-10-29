from typing import Literal

from pydantic import BaseModel, Field, field_validator, model_validator

MAX_CONTACTS = 20_000
MAX_EMBED_TEXT = 4_000


EmbeddingProvider = Literal["gemini", "openai", "hash"]
MAX_COPY_EXAMPLES = 100
MAX_BRAND_CHUNKS = 200


class CopyExample(BaseModel):
    """참고 후보로 Laravel이 보내는 과거 캠페인. 같은 워크스페이스의 것만 보낸다."""

    id: int
    subject: str = Field(max_length=300)
    body: str = Field(max_length=2000)
    open_rate: float = Field(ge=0, le=1)
    click_rate: float = Field(default=0, ge=0, le=1)
    vector: list[float] = Field(min_length=1, max_length=1024)
    embedding_provider: EmbeddingProvider


class BrandChunk(BaseModel):
    """참고 후보로 Laravel이 보내는 브랜드 문서 조각. 같은 워크스페이스의 것만 보낸다."""

    id: int
    document_title: str = Field(max_length=200)
    content: str = Field(min_length=1, max_length=1000)
    vector: list[float] = Field(min_length=1, max_length=1024)
    embedding_provider: EmbeddingProvider


class CopyReference(BaseModel):
    kind: Literal["campaign", "brand"]
    id: int
    title: str = Field(description="캠페인은 제목, 브랜드 문서는 문서 제목")
    open_rate: float | None = Field(default=None, description="캠페인일 때만 있다")
    score: float = Field(description="제품 설명과의 코사인 유사도")


class CopyRequest(BaseModel):
    product: str = Field(min_length=1, max_length=300, description="제품/서비스 설명")
    target: str = Field(default="", max_length=200, description="타겟 고객")
    tone: Literal["friendly", "professional", "playful", "urgent"] = "friendly"
    channel: Literal["email", "sms", "push"] = "email"
    allow_openai: bool = Field(default=True, description="False면 유료 OpenAI 호출을 건너뛴다(일일 상한 도달 시 Laravel이 지정)")
    examples: list[CopyExample] = Field(default_factory=list, max_length=MAX_COPY_EXAMPLES, description="참고 후보(고성과 과거 캠페인). 비어 있으면 참고 없이 생성한다")
    brand_chunks: list[BrandChunk] = Field(default_factory=list, max_length=MAX_BRAND_CHUNKS, description="브랜드 문서 조각 후보. 비어 있으면 브랜드 지침 없이 생성한다")
    allow_external_embedding: bool = Field(default=True, description="False면 쿼리 임베딩에 외부 호출을 쓰지 않는다(임베딩 일일 상한 도달 시 Laravel이 지정)")


class CopyResponse(BaseModel):
    subjects: list[str]
    body: str
    mock: bool = Field(description="True면 LLM 호출 없이 만든 샘플 결과")
    provider: Literal["gemini", "openai", "mock"] = Field(description="응답을 만든 공급자")
    references: list[CopyReference] = Field(default_factory=list, description="프롬프트에 참고로 넣은 자료")
    embedding_provider: EmbeddingProvider | None = Field(default=None, description="쿼리 임베딩을 만든 공급자. 검색을 하지 않았으면 null")


class EmbedRequest(BaseModel):
    texts: list[str] = Field(min_length=1, max_length=64, description="임베딩할 텍스트")
    allow_openai: bool = Field(default=True, description="False면 유료 OpenAI 호출을 건너뛴다")
    allow_external: bool = Field(default=True, description="False면 외부 호출 없이 해시 벡터를 만든다(임베딩 일일 상한 도달 시 Laravel이 지정)")

    @field_validator("texts")
    @classmethod
    def texts_are_bounded(cls, texts: list[str]) -> list[str]:
        if any(not text.strip() or len(text) > MAX_EMBED_TEXT for text in texts):
            raise ValueError(f"각 텍스트는 비어 있지 않고 {MAX_EMBED_TEXT}자 이하여야 합니다.")

        return texts


class EmbedResponse(BaseModel):
    vectors: list[list[float]]
    provider: EmbeddingProvider = Field(description="벡터를 만든 공급자. 같은 공급자의 벡터끼리만 비교할 수 있다")
    dimensions: int


class ContactFeatures(BaseModel):
    """고객 한 명의 행동 지표. 개인 식별 정보(이메일, 이름)는 받지 않는다."""

    id: int
    recency_days: int | None = Field(default=None, ge=0, description="마지막 구매 후 경과일. 구매 이력이 없으면 null")
    tenure_days: int = Field(default=0, ge=0)
    total_spent: int = Field(default=0, ge=0)
    received: int = Field(default=0, ge=0, description="받은 캠페인 수")
    opens: int = Field(default=0, ge=0)
    clicks: int = Field(default=0, ge=0)


class ChurnRequest(BaseModel):
    contacts: list[ContactFeatures] = Field(min_length=1, max_length=MAX_CONTACTS)
    lapse_days: int = Field(default=90, ge=7, le=730, description="이 기간 이상 구매가 없으면 이탈로 간주")


class ChurnScore(BaseModel):
    id: int
    score: float = Field(ge=0, le=1)


class ChurnMetrics(BaseModel):
    method: Literal["logistic_regression", "heuristic"]
    trained_on: int
    positive_rate: float
    auc: float | None = None
    precision: float | None = Field(default=None, description="임계값 0.5에서의 정밀도(교차 검증)")
    recall: float | None = Field(default=None, description="임계값 0.5에서의 재현율(교차 검증)")
    roc: list[list[float]] | None = Field(default=None, description="ROC 곡선 점 [[fpr, tpr], ...]")
    note: str


class ChurnResponse(BaseModel):
    scores: list[ChurnScore]
    metrics: ChurnMetrics


class ClusterRequest(BaseModel):
    contacts: list[ContactFeatures] = Field(min_length=1, max_length=MAX_CONTACTS)
    k: int = Field(default=4, ge=2, le=8)


class ClusterAssignment(BaseModel):
    id: int
    cluster: int


class ClusterSummary(BaseModel):
    id: int
    name: str
    description: str
    size: int
    avg_spent: int
    avg_recency_days: int | None
    open_rate: float


class ClusterResponse(BaseModel):
    assignments: list[ClusterAssignment]
    clusters: list[ClusterSummary]


SLIDE_TITLE_MAX = 20
SLIDE_BODY_MAX = 60
MAX_SLIDES = 6


class CardSlide(BaseModel):
    title: str = Field(min_length=1, max_length=SLIDE_TITLE_MAX)
    body: str = Field(default="", max_length=SLIDE_BODY_MAX)


class CardnewsDraftRequest(BaseModel):
    topic: str = Field(min_length=1, max_length=300, description="주제/제품 설명")
    target: str = Field(default="", max_length=200, description="타겟 고객")
    tone: Literal["friendly", "professional", "playful", "urgent"] = "friendly"
    kind: Literal["cardnews", "cover"] = "cardnews"
    slide_count: int = Field(default=5, ge=3, le=MAX_SLIDES, description="카드뉴스 장수. 대표 이미지(cover)는 항상 1장")
    allow_openai: bool = True
    examples: list[CopyExample] = Field(default_factory=list, max_length=MAX_COPY_EXAMPLES)
    brand_chunks: list[BrandChunk] = Field(default_factory=list, max_length=MAX_BRAND_CHUNKS)
    allow_external_embedding: bool = True


class CardnewsDraftResponse(BaseModel):
    slides: list[CardSlide]
    mock: bool
    provider: Literal["gemini", "openai", "mock"]
    references: list[CopyReference] = Field(default_factory=list)
    embedding_provider: EmbeddingProvider | None = None


class CardnewsRenderRequest(BaseModel):
    kind: Literal["cardnews", "cover"] = "cardnews"
    ratio: Literal["1:1", "4:5", "16:9"] = "1:1"
    slides: list[CardSlide] = Field(min_length=1, max_length=MAX_SLIDES)
    seed: str = Field(default="", max_length=64, description="같은 값이면 같은 배경이 나온다")

    @model_validator(mode="after")
    def slide_count_matches_kind(self) -> "CardnewsRenderRequest":
        if self.kind == "cover" and len(self.slides) != 1:
            raise ValueError("대표 이미지는 1장만 만들 수 있습니다.")

        if self.kind == "cardnews" and len(self.slides) < 3:
            raise ValueError("카드뉴스는 3장 이상이어야 합니다.")

        return self


class RenderedImage(BaseModel):
    index: int
    width: int
    height: int
    png_base64: str


class CardnewsRenderResponse(BaseModel):
    images: list[RenderedImage]
    background_provider: Literal["gemini", "template"]
