from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy.orm import Session
from app.models.request import ForecastAdvancedRequest
from app.models.response import ForecastAdvancedResponse
from app.services.prophet_service import forecast_advanced
from app.security import verify_api_key
from app.db.postgresql import get_db

router = APIRouter()

@router.post("/forecast/advanced", response_model=ForecastAdvancedResponse)
async def forecast_advanced_endpoint(
    item: ForecastAdvancedRequest,
    db:      Session = Depends(get_db),
    api_key: str     = Depends(verify_api_key),
):
    try:
        # Đổi company_id → supplier_id
        train_end_date = None
        if getattr(item, "train_end_date", None):
            from datetime import date as _date
            train_end_date = _date.fromisoformat(item.train_end_date)

        result, explanation, tokens = await forecast_advanced(
            db             = db,
            product_id     = item.product_id,
            product_name   = item.product_name,
            supplier_id    = item.supplier_id,   # đổi tên tham số
            current_stock  = item.current_stock,
            min_stock      = item.min_stock,
            max_stock      = item.max_stock,
            train_end_date = train_end_date,
        )
        return ForecastAdvancedResponse(
            product_id    = item.product_id,
            product_name  = item.product_name,
            next_month    = result['next_month'],
            next_2_months = result['next_2_months'],
            next_3_months = result['next_3_months'],
            suggested_order = result['suggested_order'],
            trend         = result['trend'],
            warning       = result['warning'],
            explanation   = explanation,
            months_trained = result['months_trained'],
            confidence    = result['confidence'],
            # ── Nối các field AI ROP/Safety Stock (trước đây bỏ trống) ──
            rop               = result.get('rop'),
            safety_stock      = result.get('safety_stock'),
            max_ai            = result.get('max_ai'),
            demand_std_dev    = result.get('demand_std_dev'),
            lead_time_days    = result.get('lead_time_days'),
            avg_daily_demand  = result.get('avg_daily_demand'),
            growth_pct        = result.get('growth_pct'),
            frequency_pct     = result.get('frequency_pct'),
            tokens_used   = tokens,
        )
    except ValueError as e:
        raise HTTPException(status_code=400, detail=str(e))
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))