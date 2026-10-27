"""고객 지표(ContactFeatures)를 모델 입력 행렬로 바꾼다."""

import numpy as np
import pandas as pd

from app.schemas import ContactFeatures

# 이탈 모델 입력. recency는 라벨 정의에 쓰이므로 입력에서 제외한다(라벨 누수 방지).
CHURN_FEATURES = ["log_spent", "tenure_days", "open_rate", "click_rate", "received"]

# 클러스터링 입력. 구매 최근성, 구매액, 메일 반응을 함께 본다.
CLUSTER_FEATURES = ["recency_filled", "log_spent", "open_rate", "click_rate"]


def to_frame(contacts: list[ContactFeatures]) -> pd.DataFrame:
    df = pd.DataFrame([c.model_dump() for c in contacts])

    received = df["received"].replace(0, np.nan)
    df["open_rate"] = (df["opens"] / received).fillna(0.0).clip(0, 1)
    df["click_rate"] = (df["clicks"] / received).fillna(0.0).clip(0, 1)
    df["log_spent"] = np.log1p(df["total_spent"])

    # 구매 이력이 없는 고객은 가장 오래된 고객보다 더 멀리 있는 것으로 취급한다.
    max_recency = df["recency_days"].max()
    fill = (0 if pd.isna(max_recency) else max_recency) + 30
    df["recency_filled"] = df["recency_days"].fillna(fill)

    return df
