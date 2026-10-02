from fastapi import APIRouter, Depends, HTTPException, Response
from pydantic import BaseModel
from sqlalchemy.orm import Session
from app.services.text_to_sql_service import ask_sql
from app.security import verify_api_key
from app.db.postgresql import get_db
import csv
import io

router = APIRouter()

class QueryRequest(BaseModel):
    question: str
    supplier_id: int = 1

class QueryResponse(BaseModel):
    question: str
    sql: str
    result: list | dict | None
    error: str | None = None


# ── /query — trả về JSON ──────────────────────────────────────
@router.post("/query", response_model=QueryResponse)
async def text_to_sql_endpoint(
    req: QueryRequest,
    db: Session = Depends(get_db),
    api_key: str = Depends(verify_api_key),
):
    try:
        sql, result = await ask_sql(req.question, req.supplier_id, db)
        return QueryResponse(question=req.question, sql=sql, result=result)
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))


# ── /query/csv — trả về CSV cho Power BI ──────────────────────
@router.post("/query/csv")
async def query_csv(
    req: QueryRequest,
    db: Session = Depends(get_db),
    api_key: str = Depends(verify_api_key),
):
    """
    Trả về kết quả truy vấn dưới dạng CSV.
    Phù hợp để Power BI gọi qua Web.Contents().
    """
    sql, data = await ask_sql(req.question, req.supplier_id, db)

    # Nếu có lỗi, trả về 400
    if isinstance(data, dict) and "error" in data:
        raise HTTPException(status_code=400, detail=data["error"])

    # Nếu không có dữ liệu, trả về CSV rỗng
    if not data:
        return Response(content="", media_type="text/csv")

    # Ghi CSV
    output = io.StringIO()
    writer = csv.DictWriter(output, fieldnames=data[0].keys())
    writer.writeheader()
    writer.writerows(data)

    return Response(
        content=output.getvalue(),
        media_type="text/csv",
        headers={
            "Content-Disposition": "attachment; filename=query_result.csv"
        }
    )