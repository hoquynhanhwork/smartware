# app/routers/trends.py
from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy.orm import Session
from app.models.request import TrendRequest
from app.models.response import TrendResponse
from app.services.trend_service import analyze_trend
from app.security import verify_api_key
from app.db.postgresql import get_db

router = APIRouter()

@router.post("/trends", response_model=TrendResponse)
async def trend_analysis(
    item:    TrendRequest,
    db:      Session = Depends(get_db),
    api_key: str     = Depends(verify_api_key),
):
    try:
        # Đổi company_id → supplier_id
        result, tokens = await analyze_trend(
            product_id   = item.product_id,
            product_name = item.product_name,
            supplier_id  = item.supplier_id,   # đổi tên tham số
            db           = db,
        )
        return TrendResponse(
            product_id   = item.product_id,
            product_name = item.product_name,
            tokens_used  = tokens,
            **result,
        )
    except ValueError as e:
        raise HTTPException(status_code=400, detail=str(e))
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))