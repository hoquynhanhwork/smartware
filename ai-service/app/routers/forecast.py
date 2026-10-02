from fastapi import APIRouter, Depends, HTTPException
from app.models.request import InventoryItem
from app.models.response import ForecastResponse
from app.services.gpt_service import forecast_inventory
from app.security import verify_api_key

router = APIRouter()

@router.post("/forecast", response_model=ForecastResponse)
async def forecast(item: InventoryItem,
                   api_key: str = Depends(verify_api_key)):
    try:
        result, tokens = await forecast_inventory(
            product_name=item.product_name,
            sales_history=item.sales_history,
            current_stock=item.current_stock,
            min_stock=item.min_stock,
            max_stock=item.max_stock,
            avg_daily_out=item.avg_daily_out,
            estimated_days_left=item.estimated_days_left,
        )
        return ForecastResponse(
            product_id=item.product_id,
            product_name=item.product_name,
            tokens_used=tokens,
            **result
        )
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))