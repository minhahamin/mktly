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
    names = _name_clusters(stats)

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


def _name_clusters(stats: pd.DataFrame) -> dict[int, tuple[str, str]]:
    """클러스터 평균 지표의 상대 순위로 의미 있는 이름을 붙인다. 이미 쓴 이름은 다시 쓰지 않는다."""
    rules = [
        ("핵심 고객", "구매액이 높고 최근에도 구매한 고객", lambda s: s["avg_spent"].rank() - s["avg_recency"].rank()),
        ("휴면 고객", "마지막 구매가 오래되었거나 구매 이력이 없는 고객", lambda s: s["avg_recency"].rank() + s["never_purchased"].rank()),
        ("적극 반응 고객", "메일 오픈과 클릭 반응이 좋은 고객", lambda s: s["open_rate"].rank()),
        ("신규 고객", "가입한 지 얼마 되지 않은 고객", lambda s: -s["tenure"].rank()),
    ]

    names: dict[int, tuple[str, str]] = {}
    for title, description, score in rules:
        for cluster_id in score(stats).sort_values(ascending=False).index:
            if cluster_id not in names:
                names[cluster_id] = (title, description)
                break

    for n, cluster_id in enumerate((c for c in stats.index if c not in names), start=1):
        names[cluster_id] = (f"일반 고객 {n}", "뚜렷한 특징이 없는 일반 고객")

    return names

