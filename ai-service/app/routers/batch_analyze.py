from fastapi import APIRouter, Depends, HTTPException
from fastapi.responses import StreamingResponse
from sqlalchemy.orm import Session
from app.models.request import BatchAnalyzeRequest, ChatRequest
from app.models.response import BatchAnalyzeResponse, ChatResponse
from app.services.batch_service import analyze_batch
from app.services.rag_service import rag_chat, rag_chat_stream
from app.security import verify_api_key
from app.db.postgresql import get_db

router = APIRouter()

# ── /analyze/batch ────────────────────────────────────────────
@router.post("/analyze/batch", response_model=BatchAnalyzeResponse)
async def batch_analyze(
    item:    BatchAnalyzeRequest,
    db:      Session = Depends(get_db),
    api_key: str     = Depends(verify_api_key),
):
    try:
        # Đổi company_id → supplier_id
        result, tokens = await analyze_batch(
            supplier_id   = item.supplier_id,   # đổi tên tham số
            db           = db,
            limit        = item.limit,
            stock_status = item.stock_status,
        )
        return BatchAnalyzeResponse(**result)
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))

# ── /chat — RAG-aware, non-streaming ─────────────────────────
@router.post("/chat", response_model=ChatResponse)
async def chat(
    item:    ChatRequest,
    db:      Session = Depends(get_db),
    api_key: str     = Depends(verify_api_key),
):
    try:
        # Đổi company_id → supplier_id
        answer, sources, tokens = await rag_chat(
            message    = item.message,
            supplier_id = item.supplier_id,   # đổi tên tham số
            user_id    = None,
            db         = db,
        )
        return ChatResponse(answer=answer, tokens_used=tokens)
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))

# ── /chat/stream — RAG + streaming SSE ───────────────────────
@router.post("/chat/stream")
async def chat_stream(
    item:    ChatRequest,
    db:      Session = Depends(get_db),
    api_key: str     = Depends(verify_api_key),
):
    return StreamingResponse(
        rag_chat_stream(
            message    = item.message,
            supplier_id = item.supplier_id,   # đổi tên tham số
            db         = db,
        ),
        media_type="text/event-stream",
        headers={
            "Cache-Control":    "no-cache",
            "X-Accel-Buffering": "no",
        },
    )