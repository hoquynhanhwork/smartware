# app/services/batch_service.py
import json
from datetime import datetime, timedelta
from sqlalchemy.orm import Session
from sqlalchemy import text
from app.services.risk_service import calculate_risk_score, score_to_level, save_risk
from app.services.replenishment_service import (
    get_latest_forecast, calculate_replenishment, save_replenishment,
)
from app.services.gpt_service import _post_chat
from app.config import settings

def get_all_stock(db: Session, supplier_id: int | None, stock_status: str | None, limit: int) -> list[dict]:
    """Query vw_stock_summary_for_ai với optional filter."""
    where_extra = ""
    params: dict = {"limit": limit}

    if supplier_id and supplier_id > 0:
        where_extra = "AND supplier_id = :supplier_id"
        params["supplier_id"] = supplier_id

    if stock_status:
        where_extra += " AND stock_status = :stock_status"
        params["stock_status"] = stock_status

    rows = db.execute(text(f"""
        SELECT
            product_id, product_name, sku,
            current_stock, min_stock, max_stock,
            avg_daily_out, estimated_days_left,
            nearest_exp_date, days_to_nearest_exp,
            stock_status, cost_price
        FROM public.vw_stock_summary_for_ai
        WHERE 1=1 {where_extra}
        ORDER BY
            CASE stock_status
                WHEN 'OUT_OF_STOCK' THEN 1
                WHEN 'LOW_STOCK'    THEN 2
                WHEN 'NEAR_EXPIRY'  THEN 3
                WHEN 'OVERSTOCK'    THEN 4
                ELSE 5
            END,
            estimated_days_left ASC NULLS LAST
        LIMIT :limit
    """), params).fetchall()

    return [
        {
            "product_id":          r[0],
            "product_name":        r[1],
            "sku":                 r[2],
            "current_stock":       int(r[3] or 0),
            "min_stock":           int(r[4] or 0),
            "max_stock":           int(r[5] or 0),
            "avg_daily_out":       float(r[6] or 0),
            "estimated_days_left": float(r[7]) if r[7] is not None else None,
            "nearest_exp_date":    str(r[8]) if r[8] else None,
            "days_to_nearest_exp": int(r[9]) if r[9] is not None else None,
            "stock_status":        r[10] or "NORMAL",
            "cost_price":          float(r[11]) if r[11] else None,
        }
        for r in rows
    ]

def get_cached_batch(db: Session, supplier_id: int) -> dict | None:
    """Trả về cache batch analyze nếu còn hạn."""
    row = db.execute(text("""
        SELECT extra_data FROM public.ai_cache
        WHERE supplier_id = :sid
          AND product_id IS NULL
          AND forecast_type = 'batch_analyze'
          AND expires > NOW()
        ORDER BY created DESC
        LIMIT 1
    """), {"sid": supplier_id}).fetchone()

    if row and row[0]:
        return row[0]
    return None


def save_cache_batch(db: Session, supplier_id: int, result: dict) -> None:
    """Lưu kết quả batch vào ai_cache, TTL = settings.cache_ttl giây."""
    expires = datetime.utcnow() + timedelta(seconds=settings.cache_ttl)
    db.execute(text("""
        INSERT INTO public.ai_cache
            (supplier_id, product_id, forecast_type, extra_data, expires, model_version)
        VALUES
            (:sid, NULL, 'batch_analyze', CAST(:data AS jsonb), :expires, 'batch-v1')
        ON CONFLICT DO NOTHING
    """), {
        "sid":     supplier_id,
        "data":    json.dumps(result, ensure_ascii=False),
        "expires": expires,
    })
    db.commit()
    
async def generate_batch_summary(
    high_risk_products: list[dict],
) -> tuple[str, int]:
    """
    Viết tóm tắt tổng quan cho các sản phẩm rủi ro cao.
    Chỉ gọi 1 lần LLM cho toàn bộ batch → tiết kiệm token.
    """
    if not high_risk_products:
        return "Không có sản phẩm nào ở mức rủi ro cao. Kho hàng đang ở trạng thái ổn định.", 0

    lines = []
    for p in high_risk_products[:5]:  # top 5 thôi
        lines.append(
            f"- {p['product_name']}: rủi ro {p['risk_level'].upper()} "
            f"(điểm {p['risk_score']:.0f}), tồn {p['current_stock']} "
            f"(min {p['min_stock']})"
            + (f", còn ~{p['estimated_days_left']:.0f} ngày" if p.get("estimated_days_left") else "")
        )

    prompt = f"""Tóm tắt tình trạng kho hàng sau và đưa ra khuyến nghị tổng quan cho quản lý:

{chr(10).join(lines)}

Viết 3-4 câu tổng quan: tình trạng chung, sản phẩm cần ưu tiên xử lý, và hành động đề xuất. Tiếng Việt tự nhiên."""

    try:
        content, tokens = await _post_chat(
            base_url    = settings.lm_base_url,
            api_key     = settings.lm_api_key,
            model       = settings.lm_model,
            messages    = [
                {"role": "system", "content": "Bạn là chuyên gia quản lý kho, viết tóm tắt tổng quan bằng tiếng Việt."},
                {"role": "user",   "content": prompt},
            ],
            max_tokens  = 300,
            temperature = 0.3,
            timeout     = 40.0,
        )
        return content.strip(), tokens
    except Exception:
        names = ", ".join(p["product_name"] for p in high_risk_products[:3])
        return (
            f"Có {len(high_risk_products)} sản phẩm rủi ro cao cần xử lý ngay, "
            f"đặc biệt: {names}. Vui lòng kiểm tra và đặt hàng bổ sung."
        ), 0

async def analyze_batch(
    supplier_id: int,
    db: Session,
    limit: int = 500,
    stock_status: str | None = None,
) -> tuple[dict, int]:
    """
    Phân tích toàn bộ kho: risk + replenishment cho tất cả SP.
    Returns (result_dict, tokens_used).
    """
    cached = get_cached_batch(db, supplier_id)
    if cached and not stock_status:
        cached["cached"] = True
        return cached, 0

    products = get_all_stock(db, supplier_id, stock_status, limit)
    if not products:
        return {
            "supplier_id": supplier_id, "total_products": 0,
            "analyzed": 0, "summary": {}, "results": [], "cached": False,
        }, 0

    results      = []
    high_risk    = []
    total_tokens = 0

    risk_counts   = {"low": 0, "medium": 0, "high": 0}
    priority_counts = {"low": 0, "medium": 0, "high": 0, "critical": 0}

    for p in products:
        risk_score, factors = calculate_risk_score(
            current_stock       = p["current_stock"],
            min_stock           = p["min_stock"],
            max_stock           = p["max_stock"],
            avg_daily_out       = p["avg_daily_out"],
            estimated_days_left = p["estimated_days_left"],
            days_to_nearest_exp = p["days_to_nearest_exp"],
            sales_history       = [],
        )
        risk_level = score_to_level(risk_score)
        risk_counts[risk_level] = risk_counts.get(risk_level, 0) + 1

        save_risk(db, supplier_id, p["product_id"], risk_level, risk_score,
                  factors[0] if factors else "Ổn định")

        forecast = get_latest_forecast(db, p["product_id"], supplier_id)
        repl = calculate_replenishment(
            current_stock       = p["current_stock"],
            min_stock           = p["min_stock"],
            max_stock           = p["max_stock"],
            avg_daily_out       = p["avg_daily_out"],
            estimated_days_left = p["estimated_days_left"],
            forecast            = forecast,
            cost_price          = p["cost_price"],
        )
        priority_counts[repl["priority"]] = priority_counts.get(repl["priority"], 0) + 1

        item = {
            "product_id":         p["product_id"],
            "product_name":       p["product_name"],
            "stock_status":       p["stock_status"],
            "risk_level":         risk_level,
            "risk_score":         risk_score,
            "priority":           repl["priority"],
            "suggested_quantity": repl["suggested_quantity"],
            "warning":            factors[0] if risk_level in ("medium", "high") else None,
        }
        results.append(item)

        if risk_level == "high":
            high_risk.append({
                **item,
                "current_stock":       p["current_stock"],
                "min_stock":           p["min_stock"],
                "estimated_days_left": p["estimated_days_left"],
            })

    summary_text, tokens = await generate_batch_summary(high_risk)
    total_tokens += tokens

    output = {
        "supplier_id":     supplier_id,
        "total_products": len(products),
        "analyzed":       len(results),
        "summary": {
            "risk_counts":      risk_counts,
            "priority_counts":  priority_counts,
            "high_risk_count":  risk_counts.get("high", 0),
            "critical_count":   priority_counts.get("critical", 0),
            "overview_text":    summary_text,
        },
        "results":    results,
        "tokens_used": total_tokens,
        "cached":      False,
    }

    if not stock_status:
        save_cache_batch(db, supplier_id, output)

    return output, total_tokens