from fastapi import APIRouter, Depends, HTTPException
from app.models.request import InventoryItem
from app.models.response import PredictionResponse
from app.services.gpt_service import predict_inventory
from app.security import verify_api_key
router = APIRouter()

@router.post("/predict", response_model=PredictionResponse)
async def predict(item: InventoryItem,
                  api_key: str = Depends(verify_api_key)):
    try:
        result, tokens = await predict_inventory(
        product_name=item.product_name,
        sales_history=item.sales_history,
        current_stock=item.current_stock,
        min_stock=item.min_stock,
        max_stock=getattr(item, "max_stock", 0),
        avg_daily_out=getattr(item, "avg_daily_out", 0),
        estimated_days_left=getattr(item, "estimated_days_left", None),
        nearest_exp_date=getattr(item, "nearest_exp_date", None),
    )
        return PredictionResponse(
            product_id=item.product_id,
            product_name=item.product_name,
            tokens_used=tokens,
            **result
        )
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))