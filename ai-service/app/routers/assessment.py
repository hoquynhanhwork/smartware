# app/routers/assessment.py
from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy.orm import Session
from app.models.request import BatchAnalyzeRequest
from app.models.response import BatchAnalyzeResponse
from app.services.assessment_service import calculate_assessment_for_product, batch_assess
from app.security import verify_api_key
from app.db.postgresql import get_db

router = APIRouter()

@router.post("/assess")
async def assess_product(
    product_id: int,
    db: Session = Depends(get_db),
    api_key: str = Depends(verify_api_key),
):
    try:
        result = await calculate_assessment_for_product(db, product_id)
        return {"status": "success", "data": result}
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))

@router.post("/assess/batch")
async def batch_assess_endpoint(
    item: BatchAnalyzeRequest,
    db: Session = Depends(get_db),
    api_key: str = Depends(verify_api_key),
):
    try:
        # đổi company_id → supplier_id
        results = await batch_assess(db, item.supplier_id, item.limit)
        return {
            "supplier_id": item.supplier_id,  
            "analyzed": len(results),
            "results": results,
        }
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))