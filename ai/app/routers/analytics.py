from fastapi import APIRouter, Depends, HTTPException

from app.ml.churn import predict_churn
from app.ml.cluster import NotEnoughData, cluster_contacts
from app.schemas import ChurnRequest, ChurnResponse, ClusterRequest, ClusterResponse
from app.security import verify_internal_token

router = APIRouter(tags=["analytics"], dependencies=[Depends(verify_internal_token)])


@router.post("/predict/churn", response_model=ChurnResponse)
def churn(request: ChurnRequest) -> ChurnResponse:
    return predict_churn(request)


@router.post("/cluster", response_model=ClusterResponse)
def cluster(request: ClusterRequest) -> ClusterResponse:
    try:
        return cluster_contacts(request)
    except NotEnoughData as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc
