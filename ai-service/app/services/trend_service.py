# app/services/trend_service.py
import statistics
from sqlalchemy.orm import Session
from sqlalchemy import text
from app.services.gpt_service import _post_chat
from app.config import settings

def get_sales_data(db: Session, product_id: int, supplier_id: int, months: int = 12) -> list[dict]:
    """
    Lấy lịch sử bán hàng từ v_sales_for_ai.
    Trả về list dict: [{month_num, year_num, total_qty, revenue, period_label}, ...]
    Sắp xếp cũ → mới.
    """
    rows = db.execute(text("""
        SELECT
            month_num, year_num, total_qty, revenue, period_label
        FROM public.v_sales_for_ai
        WHERE product_id  = :product_id
          AND supplier_id  = :supplier_id
          AND sale_month >= DATE_TRUNC('month', public.app_today() - (:months || ' months')::INTERVAL)
        ORDER BY year_num, month_num
    """), {"product_id": product_id, "supplier_id": supplier_id, "months": months}).fetchall()

    return [
        {
            "month_num":    r[0],
            "year_num":     r[1],
            "total_qty":    float(r[2] or 0),
            "revenue":      float(r[3] or 0),
            "period_label": r[4],
        }
        for r in rows
    ]


# ================================================================
#  BƯỚC 2 — Tính toán xu hướng
# ================================================================

def compute_trend(data: list[dict]) -> dict:
    """
    Tính direction, pct_change, avg_monthly, peak_month, low_month.
    Yêu cầu tối thiểu 3 tháng data.
    """
    if len(data) < 3:
        raise ValueError(f"Chỉ có {len(data)} tháng data, cần ít nhất 3 tháng.")

    quantities = [d["total_qty"] for d in data]
    n = len(quantities)

    half = n // 2
    old_avg    = statistics.mean(quantities[:half]) if half > 0 else 0
    recent_avg = statistics.mean(quantities[-half:]) if half > 0 else quantities[-1]

    if old_avg > 0:
        pct_change = ((recent_avg - old_avg) / old_avg) * 100
    else:
        pct_change = 0.0

    if recent_avg > old_avg * 1.1:
        direction = "tăng"
    elif recent_avg < old_avg * 0.9:
        direction = "giảm"
    else:
        direction = "ổn định"

    avg_monthly = statistics.mean(quantities)

    max_qty = max(quantities)
    min_qty = min(quantities)
    peak_idx = quantities.index(max_qty)
    low_idx  = quantities.index(min_qty)
    peak_month = data[peak_idx]["month_num"]
    low_month  = data[low_idx]["month_num"]

    return {
        "direction":       direction,
        "pct_change":      round(pct_change, 2),
        "avg_monthly":     round(avg_monthly, 2),
        "peak_month":      int(peak_month),
        "low_month":       int(low_month),
        "months_analyzed": n,
        "quantities":      quantities,
        "period_labels":   [d["period_label"] for d in data],
    }


# ================================================================
#  BƯỚC 3 — LM Studio viết insight
# ================================================================

MONTH_VI = {
    1:"tháng 1", 2:"tháng 2", 3:"tháng 3", 4:"tháng 4",
    5:"tháng 5", 6:"tháng 6", 7:"tháng 7", 8:"tháng 8",
    9:"tháng 9", 10:"tháng 10", 11:"tháng 11", 12:"tháng 12",
}

async def generate_trend_insight(
    product_name: str,
    trend_data:   dict,
) -> tuple[str, int]:
    """LM Studio viết nhận xét xu hướng ngắn gọn tiếng Việt."""

    direction   = trend_data["direction"]
    pct         = trend_data["pct_change"]
    avg         = trend_data["avg_monthly"]
    peak        = MONTH_VI.get(trend_data["peak_month"], "?")
    low         = MONTH_VI.get(trend_data["low_month"], "?")
    n           = trend_data["months_analyzed"]
    qty_str     = ", ".join(str(int(q)) for q in trend_data["quantities"])

    prompt = f"""Phân tích xu hướng tiêu thụ sau và viết nhận xét ngắn gọn cho quản lý kho:

Sản phẩm: {product_name}
Số liệu {n} tháng gần nhất (cũ → mới): [{qty_str}]
Xu hướng: {direction} ({pct:+.1f}%)
Trung bình/tháng: {avg:.0f} đơn vị
Tháng cao điểm: {peak} | Tháng thấp điểm: {low}

Viết 2-3 câu tự nhiên, nêu xu hướng, nguyên nhân có thể, và gợi ý cho nhà quản lý. Không cần JSON."""

    try:
        content, tokens = await _post_chat(
            base_url    = settings.lm_base_url,
            api_key     = settings.lm_api_key,
            model       = settings.lm_model,
            messages    = [
                {"role": "system", "content": "Bạn là chuyên gia phân tích bán hàng, viết nhận xét xu hướng bằng tiếng Việt."},
                {"role": "user",   "content": prompt},
            ],
            max_tokens  = 250,
            temperature = 0.3,
            timeout     = 30.0,
        )
        return content.strip(), tokens
    except Exception:
        sign = "tăng" if pct > 0 else ("giảm" if pct < 0 else "ổn định")
        return (
            f"Sản phẩm {product_name} có xu hướng {direction} ({pct:+.1f}%) "
            f"trong {n} tháng gần nhất, trung bình {avg:.0f} đơn vị/tháng. "
            f"Tháng cao điểm là {peak}, thấp điểm là {low}."
        ), 0


# ================================================================
#  BƯỚC 4 — Lưu DB
# ================================================================

def save_trend(
    db: Session,
    supplier_id: int,
    product_id: int,
    trend_data: dict,
    insight: str,
) -> None:
    db.execute(text("""
        INSERT INTO public.trends
            (supplier_id, product_id, direction, pct_change, avg_monthly,
             peak_month, low_month, months_analyzed, insight, model_version)
        VALUES
            (:supplier_id, :product_id, :direction, :pct_change, :avg_monthly,
             :peak_month, :low_month, :months_analyzed, :insight, :model_version)
    """), {
        "supplier_id": supplier_id,
        "product_id": product_id,
        "direction": trend_data["direction"],
        "pct_change": trend_data["pct_change"],
        "avg_monthly": trend_data["avg_monthly"],
        "peak_month": trend_data["peak_month"],
        "low_month": trend_data["low_month"],
        "months_analyzed": trend_data["months_analyzed"],
        "insight": insight,
        "model_version": "lm-studio-1.0",
    })
    db.commit()


# ================================================================
#  PUBLIC API
# ================================================================

async def analyze_trend(
    product_id: int,
    product_name: str,
    supplier_id: int,
    db: Session,
) -> tuple[dict, int]:
    """
    Full pipeline: DB → tính toán → LM Studio insight → lưu DB.
    Returns (result_dict, tokens_used).
    """
    data = get_sales_data(db, product_id, supplier_id, months=12)

    if len(data) < 3:
        raise ValueError(
            f"Chỉ có {len(data)} tháng data. "
            "Cần ít nhất 3 tháng lịch sử bán hàng để phân tích xu hướng."
        )

    trend_data = compute_trend(data)
    insight, tokens = await generate_trend_insight(product_name, trend_data)

    save_trend(db, supplier_id, product_id, trend_data, insight)

    return {
        "direction":       trend_data["direction"],
        "pct_change":      trend_data["pct_change"],
        "avg_monthly":     trend_data["avg_monthly"],
        "peak_month":      trend_data["peak_month"],
        "low_month":       trend_data["low_month"],
        "months_analyzed": trend_data["months_analyzed"],
        "insight":         insight,
    }, tokens