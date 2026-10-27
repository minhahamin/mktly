"""K-means 고객 클러스터링과 클러스터 이름 부여."""

import pandas as pd
from sklearn.cluster import KMeans
from sklearn.preprocessing import StandardScaler

from app.ml.features import CLUSTER_FEATURES, to_frame
from app.schemas import ClusterAssignment, ClusterRequest, ClusterResponse, ClusterSummary

MIN_ROWS_PER_CLUSTER = 3


class NotEnoughData(ValueError):
    """고객 수가 클러스터 수에 비해 부족할 때 발생한다."""


def cluster_contacts(request: ClusterRequest) -> ClusterResponse:
    df = to_frame(request.contacts)
    k = min(request.k, len(df) // MIN_ROWS_PER_CLUSTER)

    if k < 2:
        raise NotEnoughData("클러스터링하려면 고객이 최소 6명 필요합니다.")

    x = StandardScaler().fit_transform(df[CLUSTER_FEATURES].to_numpy(dtype=float))
    raw_labels = KMeans(n_clusters=k, n_init=10, random_state=42).fit_predict(x)

    # 큰 클러스터부터 1, 2, 3 ... 번호를 다시 매겨 실행마다 번호가 안정적이도록 한다.
    order = pd.Series(raw_labels).value_counts().index.tolist()
    relabel = {old: new + 1 for new, old in enumerate(order)}
    df["cluster"] = [relabel[label] for label in raw_labels]

    stats = df.groupby("cluster").agg(
        size=("id", "size"),
        avg_spent=("total_spent", "mean"),
        avg_recency=("recency_filled", "mean"),
        never_purchased=("recency_days", lambda s: s.isna().mean()),
        open_rate=("open_rate", "mean"),
        tenure=("tenure_days", "mean"),
    )
    names = _name_clusters(stats, df)

    clusters = []
    for cluster_id, row in stats.iterrows():
        name, description = names[cluster_id]
        known = df.loc[(df["cluster"] == cluster_id) & df["recency_days"].notna(), "recency_days"]
        clusters.append(
            ClusterSummary(
                id=int(cluster_id),
                name=name,
                description=description,
                size=int(row["size"]),
                avg_spent=int(round(row["avg_spent"], -2)),
                avg_recency_days=int(known.mean()) if len(known) else None,
                open_rate=round(float(row["open_rate"]), 3),
            )
        )

    return ClusterResponse(
        assignments=[ClusterAssignment(id=int(i), cluster=int(c)) for i, c in zip(df["id"], df["cluster"], strict=True)],
        clusters=clusters,
    )


def _name_clusters(stats: pd.DataFrame, df: pd.DataFrame) -> dict[int, tuple[str, str]]:
    """전체 평균 대비 편차(z-score)로 클러스터 특징을 판단해 이름을 붙인다.

    기준을 실제로 만족하는 클러스터에만 이름을 주고, 나머지는 가장 두드러진 특징으로 이름을 만든다.
    같은 이름이 겹치면 번호를 붙여 구분한다.
    """
    pop = {
        "recency": (df["recency_filled"].mean(), df["recency_filled"].std(ddof=0) or 1.0),
        "spent": (df["total_spent"].mean(), df["total_spent"].std(ddof=0) or 1.0),
        "open": (df["open_rate"].mean(), df["open_rate"].std(ddof=0) or 1.0),
        "tenure": (df["tenure_days"].mean(), df["tenure_days"].std(ddof=0) or 1.0),
    }
    column = {"recency": "avg_recency", "spent": "avg_spent", "open": "open_rate", "tenure": "tenure"}
    z = pd.DataFrame({k: (stats[column[k]] - mean) / std for k, (mean, std) in pop.items()})

    # (이름, 설명, 충족 조건, 우선순위 점수)
    rules = [
        ("휴면 고객", "마지막 구매가 오래되었거나 구매 이력이 없는 고객",
         lambda r, c: r["recency"] >= 0.5 or stats.loc[c, "never_purchased"] >= 0.5,
         lambda r, c: r["recency"] + stats.loc[c, "never_purchased"]),
        ("핵심 고객", "최근에 구매했고 구매액도 높은 고객",
         lambda r, c: r["recency"] <= -0.2 and r["spent"] >= 0.2,
         lambda r, c: r["spent"] - r["recency"]),
        ("적극 반응 고객", "메일 오픈과 클릭 반응이 좋은 고객",
         lambda r, c: r["open"] >= 0.5,
         lambda r, c: r["open"]),
        ("신규 고객", "가입한 지 얼마 되지 않은 고객",
         lambda r, c: r["tenure"] <= -0.5,
         lambda r, c: -r["tenure"]),
    ]

    names: dict[int, tuple[str, str]] = {}
    for title, description, qualifies, score in rules:
        candidates = [c for c in z.index if c not in names and qualifies(z.loc[c], c)]
        if candidates:
            best = max(candidates, key=lambda c: score(z.loc[c], c))
            names[best] = (title, description)

    traits = {
        ("recency", False): ("최근 구매 고객", "마지막 구매가 비교적 최근인 고객"),
        ("recency", True): ("구매 공백 고객", "마지막 구매 이후 시간이 오래 지난 고객"),
        ("spent", True): ("고액 고객", "누적 구매액이 높은 고객"),
        ("spent", False): ("소액 고객", "누적 구매액이 낮은 고객"),
        ("open", True): ("반응 좋은 고객", "메일 반응이 평균보다 좋은 고객"),
        ("open", False): ("저반응 고객", "메일 반응이 평균보다 낮은 고객"),
        ("tenure", True): ("장기 고객", "가입한 지 오래된 고객"),
        ("tenure", False): ("가입 초기 고객", "가입한 지 얼마 되지 않은 고객"),
    }
    used = {title for title, _ in names.values()}

    for cluster_id in (c for c in z.index if c not in names):
        trait = z.loc[cluster_id].abs().idxmax()
        title, description = traits[(trait, bool(z.loc[cluster_id, trait] > 0))]
        if title in used:
            n = 2
            while f"{title} {n}" in used:
                n += 1
            title = f"{title} {n}"
        used.add(title)
        names[cluster_id] = (title, description)

    return names
