from datetime import date

from fastapi import APIRouter, Depends, HTTPException, Query
from fastapi.responses import Response
from pydantic import BaseModel
from sqlalchemy.orm import Session
from typing import Optional

from app.config import settings
from app.db.postgresql import get_db
from app.security import verify_api_key
from app.services import hybrid_service

router = APIRouter(prefix="/forecast/hybrid")


class RetrainRequest(BaseModel):
    # "YYYY-MM-DD": chỉ dùng dữ liệu TRƯỚC tháng của ngày này (backtest). Bỏ trống = theo 'hôm nay' của hệ thống.
    train_end_date: Optional[str] = None
    # true = bỏ cache (kể cả cache Prophet trên đĩa), huấn luyện lại từ đầu (chậm hơn).
    force: bool = False


def _parse(d: Optional[str]) -> Optional[date]:
    try:
        return date.fromisoformat(d) if d else None
    except ValueError:
        raise HTTPException(status_code=400, detail="train_end_date phải có dạng YYYY-MM-DD")


def _summary(b) -> dict:
    return {
        "model_version": b.version,
        "engine_setting": settings.forecast_engine,
        "trained_through": b.origin_month.strftime("%Y-%m"),
        "forecast_months": [m.strftime("%Y-%m") for m in b.target_months],
        "products_total": int(len(b.forecasts)),
        "products_forecasted": int(b.forecasts.notna().all(axis=1).sum()),
        "train_rows_by_horizon": b.n_train_rows,
        "trained_at": b.trained_at,
        "config": b.config,
    }


@router.get("/status")
def status(train_end_date: Optional[str] = None, db: Session = Depends(get_db), api_key: str = Depends(verify_api_key)):
    """Trạng thái model hiện hành (tự huấn luyện nếu chưa có — lần đầu có thể mất 1-2 phút)."""
    try:
        return _summary(hybrid_service.get_bundle(db, _parse(train_end_date)))
    except ValueError as e:
        raise HTTPException(status_code=400, detail=str(e))


@router.post("/retrain")
def retrain(item: RetrainRequest, db: Session = Depends(get_db), api_key: str = Depends(verify_api_key)):
    """Huấn luyện lại hybrid. Nên gọi sau khi nạp dữ liệu mới (vd đầu mỗi tháng)."""
    try:
        return _summary(hybrid_service.get_bundle(db, _parse(item.train_end_date), force=item.force))
    except ValueError as e:
        raise HTTPException(status_code=400, detail=str(e))


@router.get("/forecasts")
def forecasts(train_end_date: Optional[str] = None, db: Session = Depends(get_db), api_key: str = Depends(verify_api_key)):
    """Dự báo 3 tháng kế tiếp của MỌI sản phẩm (JSON). SP chưa đủ dữ liệu có giá trị null."""
    try:
        df, b = hybrid_service.forecasts_frame(db, _parse(train_end_date))
    except ValueError as e:
        raise HTTPException(status_code=400, detail=str(e))
    rows = df.astype(object).where(df.notna(), None).to_dict(orient="records")
    return {**_summary(b), "items": rows}


@router.get("/export.csv")
def export_csv(train_end_date: Optional[str] = None, db: Session = Depends(get_db), api_key: str = Depends(verify_api_key)):
    """Cùng dữ liệu như /forecasts nhưng tải về dạng CSV (UTF-8 BOM, mở được bằng Excel)."""
    try:
        df, b = hybrid_service.forecasts_frame(db, _parse(train_end_date))
    except ValueError as e:
        raise HTTPException(status_code=400, detail=str(e))
    return Response(
        content=df.to_csv(index=False).encode("utf-8-sig"),
        media_type="text/csv; charset=utf-8",
        headers={"Content-Disposition": f'attachment; filename="hybrid_forecast_{b.origin_month.strftime("%Y-%m")}.csv"'},
    )


@router.post("/save")
def save(item: RetrainRequest, db: Session = Depends(get_db), api_key: str = Depends(verify_api_key)):
    """Ghi dự báo 3 tháng của mọi SP vào bảng `forecasts` (model_version = hybrid-1.0)."""
    try:
        b = hybrid_service.get_bundle(db, _parse(item.train_end_date), force=item.force)
        n = hybrid_service.save_forecasts(db, b)
        return {"rows_written": n, **_summary(b)}
    except ValueError as e:
        raise HTTPException(status_code=400, detail=str(e))
