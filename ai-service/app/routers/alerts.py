# app/routers/alerts.py
from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy.orm import Session
from app.services.alert_service import generate_alerts
from app.security import verify_api_key
from app.db.postgresql import get_db

router = APIRouter()

@router.post("/alerts/generate")
async def generate_alerts_endpoint(
    db: Session = Depends(get_db),
    api_key: str = Depends(verify_api_key),
):
    try:
        result = await generate_alerts(db)
        return result
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))