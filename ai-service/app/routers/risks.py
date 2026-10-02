# app/routers/risks.py
from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy.orm import Session
from app.models.request import RiskRequest
from app.models.response import RiskResponse
from app.services.risk_service import analyze_risk
from app.security import verify_api_key
from app.db.postgresql import get_db

router = APIRouter()

@router.post("/risks", response_model=RiskResponse)
async def risk_analysis(
    item:    RiskRequest,
    db:      Session = Depends(get_db),
    api_key: str     = Depends(verify_api_key),
):
    try:
        # Đổi company_id → supplier_id
        result, tokens = await analyze_risk(
            product_id          = item.product_id,
            product_name        = item.product_name,
            supplier_id         = item.supplier_id,   # đổi tên tham số
            current_stock       = item.current_stock,
            min_stock           = item.min_stock,
            max_stock           = item.max_stock,
            avg_daily_out       = item.avg_daily_out,
            estimated_days_left = item.estimated_days_left,
            days_to_nearest_exp = item.days_to_nearest_exp,
            sales_history       = item.sales_history,
            db                  = db,
        )
        return RiskResponse(
            product_id   = item.product_id,
            product_name = item.product_name,
            tokens_used  = tokens,
            **result,
        )
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))