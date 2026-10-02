from __future__ import annotations
import numpy as np
from functools import lru_cache
from app.config import settings

EMBEDDING_BACKEND = getattr(settings, "embedding_backend", "local")
EMBEDDING_DIM     = 384  


@lru_cache(maxsize=1)
def _get_local_model():
    """Load model 1 lần duy nhất — tránh load lại mỗi request."""
    from sentence_transformers import SentenceTransformer
    print("[Embedding] Loading all-MiniLM-L6-v2 (local)...")
    model = SentenceTransformer("sentence-transformers/all-MiniLM-L6-v2")
    print("[Embedding] Model loaded ✓")
    return model


def _embed_local(texts: list[str]) -> list[list[float]]:
    model = _get_local_model()
    vecs  = model.encode(texts, normalize_embeddings=True, show_progress_bar=False)
    return vecs.tolist()


async def _embed_openai(texts: list[str]) -> list[list[float]]:
    import httpx, json

    url     = settings.gpt_mini_base_url.rstrip("/") + "/embeddings"
    payload = {
        "model": "text-embedding-3-small",
        "input": texts,
    }
    headers = {
        "Authorization": f"Bearer {settings.gpt_mini_api_key}",
        "Content-Type":  "application/json",
    }
    body = json.dumps(payload, ensure_ascii=False).encode("utf-8")

    async with httpx.AsyncClient(timeout=30.0) as client:
        resp = await client.post(url, content=body, headers=headers)
        resp.raise_for_status()
        data = resp.json()

    items = sorted(data["data"], key=lambda x: x["index"])
    return [item["embedding"] for item in items]

async def embed_texts(texts: list[str]) -> list[list[float]]:
    """
    Embed danh sách text, trả về list vectors.
    Tự động dùng local hoặc OpenAI tùy EMBEDDING_BACKEND.
    """
    if not texts:
        return []

    if EMBEDDING_BACKEND == "openai":
        return await _embed_openai(texts)
    else:
        import asyncio
        loop = asyncio.get_event_loop()
        return await loop.run_in_executor(None, _embed_local, texts)


async def embed_one(text: str) -> list[float]:
    """Embed 1 text duy nhất — dùng cho query."""
    results = await embed_texts([text])
    return results[0] if results else []


def cosine_similarity(a: list[float], b: list[float]) -> float:
    """Tính cosine similarity giữa 2 vector (fallback nếu không dùng pgvector)."""
    va = np.array(a)
    vb = np.array(b)
    return float(np.dot(va, vb) / (np.linalg.norm(va) * np.linalg.norm(vb) + 1e-9))