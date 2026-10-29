from typing import Literal

from pydantic import BaseModel, Field

MAX_CONTACTS = 20_000


class CopyRequest(BaseModel):
    product: str = Field(min_length=1, max_length=300, description="제품/서비스 설명")
    target: str = Field(default="", max_length=200, description="타겟 고객")
    tone: Literal["friendly", "professional", "playful", "urgent"] = "friendly"
    channel: Literal["email", "sms", "push"] = "email"
    allow_openai: bool = Field(default=True, description="False면 유료 OpenAI 호출을 건너뛴다(일일 상한 도달 시 Laravel이 지정)")


class CopyResponse(BaseModel):
    subjects: list[str]
    body: str
    mock: bool = Field(description="True면 LLM 호출 없이 만든 샘플 결과")
    provider: Literal["gemini", "openai", "mock"] = Field(description="응답을 만든 공급자")


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
