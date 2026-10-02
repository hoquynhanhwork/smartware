# app/services/rag_service.py
from __future__ import annotations
import json
import asyncio
from sqlalchemy.orm import Session
from sqlalchemy import text
from app.services.embedding_service import embed_one
from app.services.gpt_service import CHAT_SYSTEM_PROMPT, _post_chat
from app.config import settings


# ================================================================
#  RETRIEVE
# ================================================================

async def retrieve_policy(
    db: Session,
    supplier_id: int,
    query_vec: list[float],
    top_k: int = 3,
) -> list[dict]:
    """Tìm top_k chunks chính sách gần nhất với câu hỏi."""
    vec_str = "[" + ",".join(f"{v:.6f}" for v in query_vec) + "]"

    rows = db.execute(text(f"""
        SELECT
            source_file,
            chunk_text,
            parent_text,
            1 - (embedding <=> '{vec_str}'::vector) AS similarity
        FROM public.policy_chunks
        WHERE supplier_id = :sid
          AND embedding IS NOT NULL
        ORDER BY embedding <=> '{vec_str}'::vector
        LIMIT :k
    """), {"sid": supplier_id, "k": top_k}).fetchall()

    return [
        {
            "source":     r[0],
            "chunk_text": r[1],
            "parent_text": r[2] or r[1],
            "similarity": float(r[3]),
            "type":       "policy",
        }
        for r in rows
        if float(r[3]) > 0.3
    ]


async def retrieve_inventory(
    db: Session,
    supplier_id: int,
    query_vec: list[float],
    top_k: int = 5,
) -> list[dict]:
    """Tìm top_k sản phẩm trong kho liên quan nhất với câu hỏi."""
    vec_str = "[" + ",".join(f"{v:.6f}" for v in query_vec) + "]"

    rows = db.execute(text(f"""
        SELECT
            product_id,
            chunk_text,
            parent_text,
            1 - (embedding <=> '{vec_str}'::vector) AS similarity
        FROM public.inventory_chunks
        WHERE supplier_id = :sid
          AND embedding IS NOT NULL
        ORDER BY embedding <=> '{vec_str}'::vector
        LIMIT :k
    """), {"sid": supplier_id, "k": top_k}).fetchall()

    return [
        {
            "product_id":  r[0],
            "chunk_text":  r[1],
            "parent_text": r[2] or r[1],
            "similarity":  float(r[3]),
            "type":        "inventory",
        }
        for r in rows
        if float(r[3]) > 0.25
    ]


# ================================================================
#  BUILD CONTEXT
# ================================================================

def build_context(
    policy_chunks: list[dict],
    inventory_chunks: list[dict],
) -> str:
    """Ghép chunks thành context block đưa vào LM Studio."""
    parts: list[str] = []

    if inventory_chunks:
        parts.append("=== DỮ LIỆU TỒN KHO LIÊN QUAN ===")
        for c in inventory_chunks:
            parts.append(c["parent_text"])

    if policy_chunks:
        parts.append("\n=== CHÍNH SÁCH / QUY TRÌNH LIÊN QUAN ===")
        for c in policy_chunks:
            src = c["source"].split("/")[-1]
            parts.append(f"[Nguồn: {src}]\n{c['parent_text']}")

    return "\n\n".join(parts)


# ================================================================
#  SAVE HISTORY
# ================================================================

def save_chat_history(
    db, supplier_id, user_id, role, content, sources=None, tokens=0
):
    db.execute(text("""
        INSERT INTO public.rag_chat_history
            (supplier_id, user_id, role, content, sources, tokens_used)
        VALUES
            (:sid, :uid, :role, :content, CAST(:sources AS jsonb), :tokens)
    """), {
        "sid":     supplier_id,
        "uid":     user_id,
        "role":    role,
        "content": content,
        "sources": json.dumps(
            [{"type": s["type"], "source": s.get("source", ""), "similarity": round(s["similarity"], 3)}
             for s in (sources or [])],
            ensure_ascii=False
        ),
        "tokens":  tokens,
    })
    db.commit()


# ================================================================
#  PUBLIC API — RAG CHAT
# ================================================================

async def _retrieve_parallel(
    db: Session,
    supplier_id: int,
    query_vec: list[float],
) -> tuple[list[dict], list[dict]]:
    """Query cả 2 store — dùng asyncio.gather để chạy song song."""
    policy_task    = retrieve_policy(db, supplier_id, query_vec, top_k=3)
    inventory_task = retrieve_inventory(db, supplier_id, query_vec, top_k=5)

    policy_chunks, inventory_chunks = await asyncio.gather(
        policy_task, inventory_task
    )
    return policy_chunks, inventory_chunks


async def rag_chat(
    message: str,
    supplier_id: int,
    user_id: int | None,
    db: Session,
) -> tuple[str, list[dict], int]:
    """
    Full RAG pipeline.
    Returns: (answer, sources_used, tokens_used)
    """
    query_vec = await embed_one(message)

    policy_chunks, inventory_chunks = await _retrieve_parallel(db, supplier_id, query_vec)
    all_sources = policy_chunks + inventory_chunks

    context = build_context(policy_chunks, inventory_chunks)

    system = CHAT_SYSTEM_PROMPT
    if context:
        system += f"\n\n{context}"
    else:
        system += "\n\n(Không tìm thấy dữ liệu liên quan trong hệ thống. Hãy trả lời dựa trên kiến thức chuyên môn.)"

    answer, tokens = await _post_chat(
        base_url    = settings.lm_base_url,
        api_key     = settings.lm_api_key,
        model       = settings.lm_model,
        messages    = [
            {"role": "system", "content": system},
            {"role": "user",   "content": message},
        ],
        max_tokens  = 400,
        temperature = 0.4,
        timeout     = 90.0,
    )

    save_chat_history(db, supplier_id, user_id, "user",      message, None,        0)
    save_chat_history(db, supplier_id, user_id, "assistant", answer,  all_sources, tokens)

    return answer, all_sources, tokens


# ================================================================
#  STREAMING VERSION
# ================================================================

async def rag_chat_stream(
    message: str,
    supplier_id: int,
    db: Session,
):
    """
    RAG + streaming — yield từng chunk text.
    """
    import httpx, json as _json

    query_vec = await embed_one(message)
    policy_chunks, inventory_chunks = await _retrieve_parallel(db, supplier_id, query_vec)
    context = build_context(policy_chunks, inventory_chunks)

    system = CHAT_SYSTEM_PROMPT
    if context:
        system += f"\n\n{context}"

    payload = {
        "model":       settings.lm_model,
        "messages":    [
            {"role": "system", "content": system},
            {"role": "user",   "content": message},
        ],
        "max_tokens":  400,
        "temperature": 0.4,
        "stream":      True,
    }
    body_bytes = _json.dumps(payload, ensure_ascii=False).encode("utf-8")
    url = settings.lm_base_url.rstrip("/") + "/chat/completions"
    headers = {
        "Authorization": f"Bearer {settings.lm_api_key}",
        "Content-Type":  "application/json; charset=utf-8",
    }

    sources_info = [
        {"type": s["type"], "source": s.get("source", "kho hàng"), "sim": round(s["similarity"], 2)}
        for s in (policy_chunks + inventory_chunks)
    ]
    yield f"event: sources\ndata: {_json.dumps(sources_info, ensure_ascii=False)}\n\n"

    full_answer = ""
    async with httpx.AsyncClient(timeout=90.0) as client:
        async with client.stream("POST", url, content=body_bytes, headers=headers) as resp:
            async for line in resp.aiter_lines():
                if not line.startswith("data: "):
                    continue
                data = line[6:]
                if data == "[DONE]":
                    break
                try:
                    chunk = _json.loads(data)
                    delta = chunk["choices"][0]["delta"].get("content", "")
                    if delta:
                        full_answer += delta
                        yield f"data: {delta}\n\n"
                except Exception:
                    continue

    yield "data: [DONE]\n\n"