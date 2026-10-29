"""임베딩 벡터 유사도 검색. DB를 읽지 않고 호출자가 넘긴 후보 안에서만 찾는다."""

from dataclasses import dataclass
from typing import Any

import numpy as np


@dataclass(frozen=True)
class Indexed:
    """검색 후보. item은 호출자가 되돌려 받고 싶은 값(캠페인, 문서 조각 등)이다."""

    item: Any
    vector: list[float]
    provider: str


def top_k(
    query_vector: list[float],
    query_provider: str,
    candidates: list[Indexed],
    k: int,
    min_score: float = 0.0,
) -> list[tuple[Any, float]]:
    """코사인 유사도 상위 k개를 (item, 점수) 목록으로 돌려준다. 점수가 min_score를 넘는 것만 포함한다.

    공급자가 다르면 벡터 공간이 달라 비교할 수 없으므로 쿼리와 같은 공급자, 같은 차원의 후보만 본다.
    """
    query = np.asarray(query_vector, dtype=float)
    query_norm = float(np.linalg.norm(query))

    if k <= 0 or not query_norm:
        return []

    comparable = [c for c in candidates if c.provider == query_provider and len(c.vector) == len(query)]

    if not comparable:
        return []

    matrix = np.asarray([c.vector for c in comparable], dtype=float)
    norms = np.linalg.norm(matrix, axis=1)
    norms[norms == 0] = 1.0
    scores = (matrix / norms[:, None]) @ (query / query_norm)

    # 점수가 같으면 입력 순서를 유지한다.
    order = sorted(range(len(comparable)), key=lambda i: (-scores[i], i))

    return [(comparable[i].item, float(scores[i])) for i in order[:k] if scores[i] > min_score]
