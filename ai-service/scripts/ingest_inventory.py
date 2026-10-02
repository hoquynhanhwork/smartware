"""
ingest_inventory.py
───────────────────
Sync dữ liệu tồn kho từ vw_stock_summary_for_ai → inventory_chunks.

Chạy thủ công hoặc đặt cron job mỗi giờ/ngày:
  python scripts/ingest_inventory.py
  python scripts/ingest_inventory.py --supplier_id 1

Mỗi sản phẩm tạo ra:
  chunk_text  (child, ~200 token): tên + SKU + số liệu key → dùng để search
  parent_text (parent, ~500 token): đầy đủ thông tin → đưa vào context LLM
"""

import sys
import asyncio
import argparse
from pathlib import Path
from datetime import datetime

sys.path.insert(0, str(Path(__file__).parent.parent))

from sqlalchemy import create_engine, text
from sqlalchemy.orm import sessionmaker
from app.config import settings

engine       = create_engine(settings.database_url)
SessionLocal = sessionmaker(bind=engine)

MONTH_VI = {
    1:"tháng 1", 2:"tháng 2", 3:"tháng 3", 4:"tháng 4",
    5:"tháng 5", 6:"tháng 6", 7:"tháng 7", 8:"tháng 8",
    9:"tháng 9", 10:"tháng 10", 11:"tháng 11", 12:"tháng 12",
}

STATUS_VI = {
    "OUT_OF_STOCK": "hết hàng",
    "LOW_STOCK":    "sắp hết hàng",
    "NEAR_EXPIRY":  "sắp hết hạn sử dụng",
    "OVERSTOCK":    "tồn kho dư thừa",
    "NORMAL":       "bình thường",
}


def build_chunk_texts(row: dict) -> tuple[str, str]:
    """
    Tạo (child_text, parent_text) cho 1 sản phẩm.
    Ngôn ngữ tự nhiên tiếng Việt → embedding tốt hơn cho câu hỏi tiếng Việt.
    """
    name    = row["product_name"]
    sku     = row.get("sku", "")
    cat     = row.get("category_name", "")
    status  = STATUS_VI.get(row.get("stock_status", "NORMAL"), "bình thường")
    current = int(row.get("current_stock", 0))
    min_s   = int(row.get("min_stock", 0))
    max_s   = int(row.get("max_stock", 0))
    avg_out = float(row.get("avg_daily_out", 0))
    days    = row.get("estimated_days_left")
    exp     = row.get("nearest_exp_date")
    days_exp= row.get("days_to_nearest_exp")
    cost    = row.get("cost_price")
    price   = row.get("selling_price")

    child_parts = [
        f"Sản phẩm: {name}",
    ]
    if sku:
        child_parts.append(f"Mã SKU: {sku}")
    if cat:
        child_parts.append(f"Danh mục: {cat}")
    child_parts += [
        f"Trạng thái tồn kho: {status}",
        f"Tồn kho hiện tại: {current} đơn vị (tối thiểu: {min_s})",
    ]
    if avg_out > 0:
        child_parts.append(f"Xuất bình quân: {avg_out:.1f} đơn vị/ngày")
    if days is not None:
        child_parts.append(f"Ước tính hàng đủ dùng: {days:.0f} ngày")
    if exp:
        child_parts.append(f"Lô hàng gần hết hạn nhất: {exp}"
                           + (f" (còn {days_exp} ngày)" if days_exp is not None else ""))
    child_text = ". ".join(child_parts) + "."

    parent_parts = [child_text, ""]

    if max_s > 0:
        parent_parts.append(f"Mức tồn kho tối đa cho phép: {max_s} đơn vị.")

    if cost:
        parent_parts.append(f"Giá nhập: {float(cost):,.0f} VNĐ/đơn vị.")
    if price:
        parent_parts.append(f"Giá bán: {float(price):,.0f} VNĐ/đơn vị.")

    if row.get("stock_status") == "OUT_OF_STOCK":
        parent_parts.append("⚠️ Sản phẩm này đang HẾT HÀNG, cần nhập ngay.")
    elif row.get("stock_status") == "LOW_STOCK":
        parent_parts.append(
            f"⚠️ Tồn kho dưới mức tối thiểu, cần nhập thêm "
            f"ít nhất {max(0, min_s - current)} đơn vị."
        )
    elif row.get("stock_status") == "NEAR_EXPIRY" and days_exp is not None:
        parent_parts.append(
            f"⚠️ Có lô hàng sắp hết hạn sau {days_exp} ngày, "
            "cần ưu tiên xuất kho hoặc xử lý theo chính sách."
        )
    elif row.get("stock_status") == "OVERSTOCK":
        parent_parts.append(
            "ℹ️ Tồn kho vượt mức tối đa, có thể cân nhắc khuyến mãi hoặc điều chuyển."
        )

    if avg_out > 0 and days is not None:
        parent_parts.append(
            f"Với tốc độ xuất hiện tại ({avg_out:.1f} đvt/ngày), "
            f"hàng đủ dùng khoảng {days:.0f} ngày nữa."
        )

    parent_text = "\n".join(parent_parts)
    return child_text, parent_text


async def sync_inventory(db, supplier_id: int, batch_size: int = 32) -> int:
    from app.services.embedding_service import embed_texts

    rows = db.execute(text("""
        SELECT
            v.product_id, v.product_name, v.sku,
            c.name AS category_name,
            v.current_stock, v.min_stock, v.max_stock,
            v.avg_daily_out, v.estimated_days_left,
            v.nearest_exp_date, v.days_to_nearest_exp,
            v.stock_status,
            p.cost_price, p.price AS selling_price
        FROM public.vw_stock_summary_for_ai v
        JOIN public.products p ON p.id = v.product_id
        LEFT JOIN public.categories c ON c.id = p.category_id
        WHERE v.supplier_id = :sid
          AND p.deleted_at IS NULL
        ORDER BY v.product_id
    """), {"sid": supplier_id}).fetchall()

    cols = [
        "product_id", "product_name", "sku", "category_name",
        "current_stock", "min_stock", "max_stock",
        "avg_daily_out", "estimated_days_left",
        "nearest_exp_date", "days_to_nearest_exp",
        "stock_status", "cost_price", "selling_price",
    ]
    products = [dict(zip(cols, r)) for r in rows]
    print(f"  📦 {len(products)} sản phẩm cần sync")

    saved = 0
    for i in range(0, len(products), batch_size):
        batch = products[i:i + batch_size]

        child_texts  = []
        parent_texts = []
        for p in batch:
            c, par = build_chunk_texts(p)
            child_texts.append(c)
            parent_texts.append(par)

        vecs = await embed_texts(child_texts)

        for p, vec, parent in zip(batch, vecs, parent_texts):
            child_text, _ = build_chunk_texts(p)
            vec_str = "[" + ",".join(f"{v:.6f}" for v in vec) + "]"
            db.execute(text(f"""
                INSERT INTO public.inventory_chunks
                    (supplier_id, product_id, chunk_text, parent_text, embedding, synced_at)
                VALUES
                    (:sid, :pid, :chunk_text, :parent_text, '{vec_str}'::vector, NOW())
                ON CONFLICT (supplier_id, product_id)
                DO UPDATE SET
                    chunk_text  = EXCLUDED.chunk_text,
                    parent_text = EXCLUDED.parent_text,
                    embedding   = EXCLUDED.embedding,
                    synced_at   = NOW()
            """), {
                "sid":        supplier_id,
                "pid":        p["product_id"],
                "chunk_text": child_text,
                "parent_text": parent,
            })
        db.commit()
        saved += len(batch)
        print(f"  💾 {saved}/{len(products)} sản phẩm...")

    return saved


def main():
    parser = argparse.ArgumentParser(description="Sync inventory vào RAG vector store")
    parser.add_argument("--supplier_id", default=1, type=int)
    args = parser.parse_args()

    print(f"🚀 Sync inventory cho supplier_id={args.supplier_id}...")
    db = SessionLocal()
    try:
        n = asyncio.run(sync_inventory(db, args.supplier_id))
        print(f"\n🎉 Hoàn tất! {n} sản phẩm đã được embed.")
        print(f"   Kiểm tra: SELECT COUNT(*) FROM inventory_chunks WHERE supplier_id={args.supplier_id};")
    finally:
        db.close()


if __name__ == "__main__":
    main()