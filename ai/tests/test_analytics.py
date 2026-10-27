import numpy as np
import pytest


def make_contacts(n=300, seed=7):
    """최근 구매한 고객일수록 메일 반응이 좋도록 만든 합성 데이터."""
    rng = np.random.default_rng(seed)
    rows = []
    for i in range(1, n + 1):
        never = rng.random() < 0.1
        recency = None if never else int(rng.integers(1, 400))
        active = (not never) and recency < 90
        received = int(rng.integers(3, 12))
        open_p = 0.7 if active else 0.12
        opens = int(rng.binomial(received, open_p))
        rows.append(
            {
                "id": i,
                "recency_days": recency,
                "tenure_days": int(rng.integers(10, 700)),
                "total_spent": 0 if never else int(rng.integers(1, 60) * 10000),
                "received": received,
                "opens": opens,
                "clicks": int(rng.binomial(opens, 0.3)),
            }
        )
    return rows


# ---- 이탈 예측 -----------------------------------------------------------------


def test_churn_trains_model_and_scores_everyone(client, auth):
    contacts = make_contacts()

    res = client.post("/predict/churn", headers=auth, json={"contacts": contacts})

    assert res.status_code == 200
    data = res.json()
    assert [s["id"] for s in data["scores"]] == [c["id"] for c in contacts]
    assert all(0 <= s["score"] <= 1 for s in data["scores"])
    assert data["metrics"]["method"] == "logistic_regression"
    assert data["metrics"]["auc"] > 0.7
    assert data["metrics"]["trained_on"] == len(contacts)
    assert "대리 라벨" in data["metrics"]["note"]


def test_churn_returns_roc_curve_and_cross_validated_metrics(client, auth):
    metrics = client.post("/predict/churn", headers=auth, json={"contacts": make_contacts()}).json()["metrics"]

    roc = metrics["roc"]
    assert 2 <= len(roc) <= 21
    assert roc[0] == [0.0, 0.0] and roc[-1] == [1.0, 1.0]
    assert all(0 <= x <= 1 and 0 <= y <= 1 for x, y in roc)
    assert [p[0] for p in roc] == sorted(p[0] for p in roc)   # 곡선은 x축 방향으로 단조 증가
    assert 0 < metrics["precision"] <= 1 and 0 < metrics["recall"] <= 1
    # 곡선이 대각선(무작위)보다 위에 있어야 AUC가 0.5보다 크다.
    assert sum(y - x for x, y in roc) > 0


def test_heuristic_fallback_has_no_evaluation_curve(client, auth):
    metrics = client.post("/predict/churn", headers=auth, json={"contacts": [{"id": 1, "recency_days": 5}]}).json()["metrics"]

    assert metrics["roc"] is None and metrics["precision"] is None and metrics["recall"] is None


def test_churn_scores_inactive_contacts_higher(client, auth):
    contacts = make_contacts()
    res = client.post("/predict/churn", headers=auth, json={"contacts": contacts}).json()
    score = {s["id"]: s["score"] for s in res["scores"]}

    active = [score[c["id"]] for c in contacts if c["recency_days"] is not None and c["recency_days"] < 90]
    lapsed = [score[c["id"]] for c in contacts if c["recency_days"] is None or c["recency_days"] > 180]

    assert np.mean(lapsed) > np.mean(active) + 0.2


def test_churn_falls_back_to_heuristic_for_small_data(client, auth):
    contacts = [
        {"id": 1, "recency_days": 5},
        {"id": 2, "recency_days": 400},
        {"id": 3, "recency_days": None},
    ]

    data = client.post("/predict/churn", headers=auth, json={"contacts": contacts}).json()
    score = {s["id"]: s["score"] for s in data["scores"]}

    assert data["metrics"]["method"] == "heuristic" and data["metrics"]["auc"] is None
    assert score[1] < 0.2 and score[2] > 0.9 and score[3] == 0.9


def test_churn_falls_back_when_only_one_class_exists(client, auth):
    contacts = [{"id": i, "recency_days": 10, "tenure_days": 100, "total_spent": 1000, "received": 3} for i in range(1, 60)]

    data = client.post("/predict/churn", headers=auth, json={"contacts": contacts}).json()

    assert data["metrics"]["method"] == "heuristic"


@pytest.mark.parametrize(
    "contacts",
    [[], [{"id": 1, "total_spent": -5}], [{"id": 1, "recency_days": -1}], [{"recency_days": 3}]],
)
def test_churn_validates_input(client, auth, contacts):
    assert client.post("/predict/churn", headers=auth, json={"contacts": contacts}).status_code == 422


def test_churn_rejects_oversized_payload(client, auth):
    contacts = [{"id": i} for i in range(20_001)]

    assert client.post("/predict/churn", headers=auth, json={"contacts": contacts}).status_code == 422


def test_request_does_not_accept_personal_fields(client, auth):
    contacts = make_contacts(40)
    contacts[0]["email"] = "someone@example.com"

    res = client.post("/predict/churn", headers=auth, json={"contacts": contacts})

    # 알 수 없는 필드는 모델 입력으로 쓰이지 않고 응답에도 나타나지 않는다.
    assert res.status_code == 200 and "someone@example.com" not in res.text


# ---- 클러스터링 ----------------------------------------------------------------


def test_cluster_assigns_everyone_and_names_clusters(client, auth):
    contacts = make_contacts()

    data = client.post("/cluster", headers=auth, json={"contacts": contacts, "k": 4}).json()

    assert len(data["clusters"]) == 4
    assert sum(c["size"] for c in data["clusters"]) == len(contacts)
    assert sorted(a["id"] for a in data["assignments"]) == sorted(c["id"] for c in contacts)
    assert {a["cluster"] for a in data["assignments"]} == {c["id"] for c in data["clusters"]} == {1, 2, 3, 4}
    names = [c["name"] for c in data["clusters"]]
    assert len(set(names)) == len(names)
    assert [c["size"] for c in data["clusters"]] == sorted((c["size"] for c in data["clusters"]), reverse=True)


def test_cluster_is_deterministic(client, auth):
    contacts = make_contacts()

    first = client.post("/cluster", headers=auth, json={"contacts": contacts, "k": 3}).json()
    second = client.post("/cluster", headers=auth, json={"contacts": contacts, "k": 3}).json()

    assert first == second


def test_cluster_reduces_k_for_small_data(client, auth):
    data = client.post("/cluster", headers=auth, json={"contacts": make_contacts(9), "k": 8}).json()

    assert len(data["clusters"]) == 3


def test_cluster_requires_enough_contacts(client, auth):
    res = client.post("/cluster", headers=auth, json={"contacts": make_contacts(4), "k": 3})

    assert res.status_code == 422 and "최소 6명" in res.json()["detail"]


@pytest.mark.parametrize("k", [1, 9, 0])
def test_cluster_validates_k(client, auth, k):
    assert client.post("/cluster", headers=auth, json={"contacts": make_contacts(40), "k": k}).status_code == 422


def grouped_contacts():
    """뚜렷한 세 집단: 최근 구매 고액 / 구매 이력 없음 / 오래전 구매하고 반응 좋음."""
    rng = np.random.default_rng(3)
    rows = []

    def add(n, make):
        for _ in range(n):
            rows.append({"id": len(rows) + 1, **make()})

    add(60, lambda: {"recency_days": int(rng.integers(1, 20)), "tenure_days": 300, "total_spent": 900000,
                     "received": 10, "opens": int(rng.integers(4, 7)), "clicks": 1})
    add(60, lambda: {"recency_days": None, "tenure_days": 300, "total_spent": 0,
                     "received": 10, "opens": int(rng.integers(0, 2)), "clicks": 0})
    add(60, lambda: {"recency_days": int(rng.integers(100, 160)), "tenure_days": 300, "total_spent": 100000,
                     "received": 10, "opens": int(rng.integers(8, 10)), "clicks": 5})
    return rows


def test_cluster_names_reflect_actual_characteristics(client, auth):
    data = client.post("/cluster", headers=auth, json={"contacts": grouped_contacts(), "k": 3}).json()
    by_name = {c["name"]: c for c in data["clusters"]}

    assert "핵심 고객" in by_name and "휴면 고객" in by_name and "적극 반응 고객" in by_name
    assert by_name["핵심 고객"]["avg_recency_days"] < 30 and by_name["핵심 고객"]["avg_spent"] > 500000
    assert by_name["휴면 고객"]["avg_recency_days"] is None
    assert by_name["적극 반응 고객"]["open_rate"] > 0.7


def test_cluster_without_distinct_traits_gets_unique_descriptive_names(client, auth):
    """모두 비슷한 고객이라 기준을 만족하는 클러스터가 없어도 이름이 겹치지 않고 설명이 있다."""
    contacts = [
        {"id": i, "recency_days": 50 + i % 3, "tenure_days": 200, "total_spent": 100000 + i, "received": 10, "opens": 4, "clicks": 1}
        for i in range(1, 61)
    ]

    data = client.post("/cluster", headers=auth, json={"contacts": contacts, "k": 4}).json()
    names = [c["name"] for c in data["clusters"]]

    assert len(set(names)) == len(names) and all(c["description"] for c in data["clusters"])
