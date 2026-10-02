from fastapi import APIRouter, HTTPException, Depends
from pydantic import BaseModel
from sqlalchemy.orm import Session
from app.services.gpt_service import explain_and_draft_email
from app.services.app_time import app_today
from app.db.postgresql import get_db

router = APIRouter(prefix="/predict", tags=["explain"])

class ExplainRequest(BaseModel):
    product_id:    int
    product_name:  str
    quantity:      float
    min_stock:     float
    supplier_name: str

def get_seasonal_context(db: Session) -> str:
    month = app_today(db).month
    seasons = {
        1:  "tháng Tết Nguyên Đán, nhu cầu tiêu dùng tăng rất mạnh",
        2:  "sau Tết, nhu cầu đang giảm dần",
        4:  "dịp lễ 30/4 và 1/5, nhu cầu tăng nhẹ",
        6:  "đầu mùa hè, học sinh nghỉ hè",
        8:  "chuẩn bị năm học mới, nhu cầu văn phòng phẩm tăng cao",
        9:  "khai giảng, back-to-school",
        12: "mùa Giáng sinh và tổng kết cuối năm, nhu cầu tăng cao",
    }
    return seasons.get(month, "không có sự kiện mùa vụ đặc biệt tháng này")

@router.post("/explain")
async def predict_and_explain(data: ExplainRequest, db: Session = Depends(get_db)):
    try:
        result, tokens = await explain_and_draft_email(
            product_name=data.product_name,
            quantity=data.quantity,
            min_stock=data.min_stock,
            supplier_name=data.supplier_name,
            seasonal_context=get_seasonal_context(db),
        )
        return {
            "product_id":    data.product_id,
            "explanation":   result.get("explanation"),
            "email_subject": result.get("email_subject"),
            "email_draft":   result.get("email_draft"),
            "tokens_used":   tokens,
        }
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))