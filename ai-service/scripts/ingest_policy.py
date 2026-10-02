"""
ingest_policy.py
────────────────
Ingest policy files (PDF, DOCX, TXT) vào bảng policy_chunks.
"""

import sys
import os
import argparse
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent.parent))

from sqlalchemy import create_engine, text
from sqlalchemy.orm import sessionmaker
from app.config import settings

engine       = create_engine(settings.database_url)
SessionLocal = sessionmaker(bind=engine)


def ingest_file(db, filepath: Path, supplier_id: int, batch_size: int = 16) -> int:
    import asyncio
    from app.services.chunker import read_file, make_chunks
    from app.services.embedding_service import embed_texts

    print(f"Đọc: {filepath.name}")
    try:
        raw_text = read_file(filepath)
    except Exception as e:
        print(f"Lỗi đọc file: {e}")
        return 0

    if not raw_text.strip():
        print(f"File rỗng, bỏ qua")
        return 0

    chunks = make_chunks(
        text          = raw_text,
        source_file   = filepath.name,
        file_type     = filepath.suffix.lstrip(".").lower(),
        child_tokens  = 200,
        parent_tokens = 500,
    )
    print(f"Tạo {len(chunks)} chunks")

    db.execute(text("""
        DELETE FROM public.policy_chunks
        WHERE supplier_id = :sid AND source_file = :src
    """), {"sid": supplier_id, "src": filepath.name})
    db.commit()

    saved = 0
    for i in range(0, len(chunks), batch_size):
        batch = chunks[i:i + batch_size]
        texts = [c.chunk_text for c in batch]
        vecs  = asyncio.run(embed_texts(texts))

        for chunk, vec in zip(batch, vecs):
            vec_str = "[" + ",".join(f"{v:.6f}" for v in vec) + "]"
            db.execute(text(f"""
                INSERT INTO public.policy_chunks
                    (supplier_id, source_file, file_type, chunk_index,
                     chunk_text, parent_text, embedding)
                VALUES
                    (:sid, :src, :ftype, :idx,
                     :chunk_text, :parent_text, '{vec_str}'::vector)
                ON CONFLICT (supplier_id, source_file, chunk_index)
                DO UPDATE SET
                    chunk_text  = EXCLUDED.chunk_text,
                    parent_text = EXCLUDED.parent_text,
                    embedding   = EXCLUDED.embedding,
                    updated     = CURRENT_TIMESTAMP
            """), {
                "sid":         supplier_id,
                "src":         chunk.source_file,
                "ftype":       chunk.file_type,
                "idx":         chunk.chunk_index,
                "chunk_text":  chunk.chunk_text.replace("\x00", ""),
                "parent_text": (chunk.parent_text.replace("\x00", "") if chunk.parent_text else None),
            })
        db.commit()
        saved += len(batch)
        print(f"Đã lưu {saved}/{len(chunks)} chunks...")

    return saved


def main():
    parser = argparse.ArgumentParser(description="Ingest policy files vào RAG")
    parser.add_argument("--dir",        default="policies")
    parser.add_argument("--supplier_id", default=1, type=int)
    parser.add_argument("--file",       default=None)
    args = parser.parse_args()

    policy_dir = Path(args.dir)
    if not policy_dir.exists():
        print(f"Không tìm thấy thư mục: {policy_dir}")
        sys.exit(1)

    SUPPORTED = {".pdf", ".docx", ".doc", ".txt"}

    if args.file:
        files = [Path(args.file)]
    else:
        files = [f for f in policy_dir.iterdir() if f.suffix.lower() in SUPPORTED]

    if not files:
        print(f"Không có file nào (hỗ trợ: {', '.join(SUPPORTED)})")
        sys.exit(0)

    print(f"Bắt đầu ingest {len(files)} file(s) cho supplier_id={args.supplier_id}\n")

    db    = SessionLocal()
    total = 0
    try:
        for i, f in enumerate(files):
            print(f"[{i+1}/{len(files)}] {f.name}")
            try:
                n      = ingest_file(db, f, args.supplier_id)
                total += n
                print(f"Xong — {n} chunks\n")
            except Exception as e:
                db.rollback()
                print(f"Lỗi xử lý file {f.name}: {e}\n")
    finally:
        db.close()

    print(f"Hoàn tất! Tổng {total} chunks từ {len(files)} file(s)")
    print(f"Kiểm tra: SELECT COUNT(*) FROM policy_chunks WHERE supplier_id={args.supplier_id};")


if __name__ == "__main__":
    main()