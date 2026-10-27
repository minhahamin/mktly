"""이탈 확률 예측.

실제 이탈 라벨이 없으므로 "lapse_days 이상 구매가 없음"을 대리 라벨로 쓴다.
구매 최근성(recency)은 라벨 정의에 쓰이므로 입력에서 빼고, 구매액, 가입 기간, 메일 반응으로 위험도를 학습한다.
실서비스에서는 실제 이탈 기록으로 라벨을 교체해야 한다.
"""

import numpy as np
from sklearn.linear_model import LogisticRegression
from sklearn.model_selection import StratifiedKFold, cross_val_score
from sklearn.pipeline import make_pipeline
from sklearn.preprocessing import StandardScaler

from app.ml.features import CHURN_FEATURES, to_frame
from app.schemas import ChurnMetrics, ChurnRequest, ChurnResponse, ChurnScore

MIN_ROWS = 30
MIN_PER_CLASS = 5
NOTE_MODEL = "구매 이력이 없거나 마지막 구매가 오래된 고객을 이탈로 간주한 대리 라벨로 학습했습니다."
NOTE_HEURISTIC = "학습 데이터가 부족해 구매 경과일 기반 규칙으로 계산했습니다."


def predict_churn(request: ChurnRequest) -> ChurnResponse:
    df = to_frame(request.contacts)
    label = (df["recency_days"].isna() | (df["recency_days"] > request.lapse_days)).astype(int)
    positives = int(label.sum())
    negatives = len(df) - positives

    if len(df) < MIN_ROWS or min(positives, negatives) < MIN_PER_CLASS:
        scores = _heuristic(df, request.lapse_days)
        metrics = ChurnMetrics(
            method="heuristic",
            trained_on=len(df),
            positive_rate=round(positives / len(df), 4),
            note=NOTE_HEURISTIC,
        )
    else:
        model = make_pipeline(StandardScaler(), LogisticRegression(class_weight="balanced", max_iter=1000))
        x = df[CHURN_FEATURES].to_numpy(dtype=float)
        y = label.to_numpy()

        folds = min(5, positives, negatives)
        auc = float(
            np.mean(cross_val_score(model, x, y, cv=StratifiedKFold(folds, shuffle=True, random_state=42), scoring="roc_auc"))
        )

        model.fit(x, y)
        scores = model.predict_proba(x)[:, 1]
        metrics = ChurnMetrics(
            method="logistic_regression",
            trained_on=len(df),
            positive_rate=round(positives / len(df), 4),
            auc=round(auc, 3),
            note=NOTE_MODEL,
        )

    return ChurnResponse(
        scores=[ChurnScore(id=int(i), score=round(float(s), 4)) for i, s in zip(df["id"], scores, strict=True)],
        metrics=metrics,
    )


def _heuristic(df, lapse_days: int) -> np.ndarray:
    """구매 경과일이 lapse_days에 가까워질수록 확률이 높아지는 시그모이드. 구매 이력이 없으면 0.9."""
    z = (df["recency_days"].fillna(lapse_days * 3) - lapse_days) / (lapse_days / 2)
    scores = 1 / (1 + np.exp(-z.to_numpy(dtype=float)))

    return np.where(df["recency_days"].isna(), 0.9, scores)
