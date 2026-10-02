# app/routers/replenishment.py
from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy.orm import Session
from app.models.request import ReplenishmentRequest
from app.models.response import ReplenishmentResponse
from app.services.replenishment_service import create_replenishment
from app.security import verify_api_key
from app.db.postgresql import get_db

router = APIRouter()

@router.post("/replenishment", response_model=ReplenishmentResponse)
async def replenishment(
    item:    ReplenishmentRequest,
    db:      Session = Depends(get_db),
    api_key: str     = Depends(verify_api_key),
):
    try:
        # Đổi company_id → supplier_id
        result, tokens = await create_replenishment(
            product_id            = item.product_id,
            product_name          = item.product_name,
            supplier_id           = item.supplier_id,   # đổi tên tham số
            current_stock         = item.current_stock,
            min_stock             = item.min_stock,
            max_stock             = item.max_stock,
            avg_daily_out         = item.avg_daily_out,
            estimated_days_left   = item.estimated_days_left,
            cost_price            = item.cost_price,
            preferred_supplier_id = item.preferred_supplier_id,
            db                    = db,
        )
        return ReplenishmentResponse(
            product_id   = item.product_id,
            product_name = item.product_name,
            tokens_used  = tokens,
            suggested_quantity = result["suggested_quantity"],
            priority           = result["priority"],
            urgency_days       = result["urgency_days"],
            estimated_cost     = result["estimated_cost"],
            reason             = result["reason"],
            rop                = result.get("rop"),
            safety_stock       = result.get("safety_stock"),
        )
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))