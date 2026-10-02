# app/services/replenishment_service.py
from sqlalchemy.orm import Session
from sqlalchemy import text
from app.services.gpt_service import _post_chat
from app.services.prophet_service import (
    get_daily_sales, get_lead_time_days, calculate_ai_thresholds,
    calculate_frequency_pct, get_reorder_cycle_days,
)
from app.config import settings

def get_latest_forecast(db: Session, product_id: int, supplier_id: int) -> dict | None:
    """Lấy forecast mới nhất từ bảng forecasts (Prophet)."""
    rows = db.execute(text("""
        SELECT predicted_quantity, period_start, confidence
        FROM public.forecasts
        WHERE product_id = :pid AND supplier_id = :sid
        ORDER BY created DESC
        LIMIT 3
    """), {"pid": product_id, "sid": supplier_id}).fetchall()

    if not rows:
        return None

    return {
        "next_month":     int(rows[0][0]) if len(rows) > 0 else 0,
        "next_2_months":  int(rows[1][0]) if len(rows) > 1 else 0,
        "next_3_months":  int(rows[2][0]) if len(rows) > 2 else 0,
        "confidence":     float(rows[0][2]) if rows[0][2] else 80.0,
        "source":         "prophet",
    }

def calculate_replenishment(
    current_stock:       int,
    min_stock:           int,
    max_stock:           int,
    avg_daily_out:       float,
    estimated_days_left: float | None,
    forecast:            dict | None,
    cost_price:          float | None,
    daily_std_dev:       float | None = None,
    lead_time_days:      int | None = None,
    frequency_pct:       float | None = None,
    reorder_cycle_days:  float | None = None,
) -> dict:
    """
    Tính suggested_quantity, priority, urgency_days, estimated_cost.

    Safety Stock / ROP dùng công thức chuẩn đã chốt (Z=1.65 x σ x √lead_time
    x hệ_số_tần_suất) khi có đủ daily_std_dev + lead_time_days (từ
    prophet_service). Nếu thiếu (vd không đủ data ngày), fallback về
    heuristic cũ (20% demand tháng) để không vỡ pipeline.
    """
    if forecast:
        demand_3m = forecast["next_month"] + forecast["next_2_months"] + forecast["next_3_months"]
        demand_1m = forecast["next_month"]
    else:
        demand_1m = int(avg_daily_out * 30)
        demand_3m = demand_1m * 3

    rop = None
    if daily_std_dev is not None and lead_time_days is not None:
        avg_daily_demand_forecast = demand_1m / 30.0
        thresholds = calculate_ai_thresholds(
            avg_daily_demand   = avg_daily_demand_forecast,
            daily_std_dev      = daily_std_dev,
            lead_time_days     = lead_time_days,
            frequency_pct      = frequency_pct,
            reorder_cycle_days = reorder_cycle_days,
        )
        safety_stock = max(min_stock, thresholds["safety_stock"])
        rop = thresholds["rop"]
    else:
        # Fallback cũ — heuristic 20% khi thiếu dữ liệu ngày
        safety_stock = max(min_stock, int(demand_1m * 0.2))

    needed = demand_3m + safety_stock - current_stock
    suggested_quantity = max(0, needed)

    if max_stock > 0:
        room = max_stock - current_stock
        suggested_quantity = min(suggested_quantity, max(0, room))

    # rop đã tính ở trên (nếu có đủ daily_std_dev/lead_time_days); dùng nó
    # làm ngưỡng ưu tiên thay cho min_stock — trước đây rop chỉ trả ra để
    # hiển thị, không ảnh hưởng priority (bug đã sửa)
    priority_threshold = rop if rop is not None else min_stock

    if current_stock == 0:
        priority    = "critical"
        urgency_days = 0
    elif current_stock <= priority_threshold:
        priority    = "high"
        urgency_days = max(0, int(estimated_days_left or 3))
    elif estimated_days_left is not None and estimated_days_left <= 14:
        priority    = "high"
        urgency_days = max(0, int(estimated_days_left - 3))
    elif estimated_days_left is not None and estimated_days_left <= 30:
        priority    = "medium"
        urgency_days = max(0, int(estimated_days_left - 7))
    elif suggested_quantity > 0:
        priority    = "low"
        urgency_days = 30
    else:
        priority     = "low"
        urgency_days = 60
        suggested_quantity = 0

    estimated_cost = None
    if cost_price and cost_price > 0 and suggested_quantity > 0:
        estimated_cost = round(cost_price * suggested_quantity, 2)

    return {
        "suggested_quantity": suggested_quantity,
        "priority":           priority,
        "urgency_days":       urgency_days,
        "estimated_cost":     estimated_cost,
        "demand_3m":          demand_3m,
        "safety_stock":       safety_stock,
        "rop":                rop,
        "forecast_source":    forecast["source"] if forecast else "avg_daily",
    }

async def generate_reason(
    product_name:     str,
    result:           dict,
    current_stock:    int,
    min_stock:        int,
    avg_daily_out:    float,
) -> tuple[str, int]:
    """LM Studio viết lý do đề xuất nhập hàng ngắn gọn."""

    cost_str = (
        f"~{result['estimated_cost']:,.0f}đ"
        if result["estimated_cost"] else "chưa xác định"
    )
    prompt = f"""Viết lý do đề xuất nhập hàng ngắn gọn cho quản lý:

Sản phẩm: {product_name}
Mức ưu tiên: {result['priority'].upper()}
Tồn kho hiện tại: {current_stock} (tối thiểu: {min_stock})
Xuất trung bình/ngày: {avg_daily_out:.1f}
Cần nhập: {result['suggested_quantity']} đơn vị
Cần trong: {result['urgency_days']} ngày
Chi phí ước tính: {cost_str}
Nguồn dự báo: {result['forecast_source']}

Viết 1-2 câu rõ ràng, nêu lý do cụ thể và thời gian cần hành động. Không JSON."""

    try:
        content, tokens = await _post_chat(
            base_url    = settings.lm_base_url,
            api_key     = settings.lm_api_key,
            model       = settings.lm_model,
            messages    = [
                {"role": "system", "content": "Bạn là chuyên gia kho hàng, viết đề xuất nhập hàng bằng tiếng Việt."},
                {"role": "user",   "content": prompt},
            ],
            max_tokens  = 150,
            temperature = 0.2,
            timeout     = 25.0,
        )
        return content.strip(), tokens
    except Exception:
        reason = (
            f"Cần nhập {result['suggested_quantity']} đơn vị {product_name} "
            f"(ưu tiên {result['priority']}) để đảm bảo tồn kho 3 tháng tới. "
            f"Nên đặt hàng trong {result['urgency_days']} ngày."
        )
        return reason, 0

def save_replenishment(
    db: Session,
    supplier_id: int,
    product_id: int,
    result: dict,
    reason: str,
    preferred_supplier_id: int | None,
) -> None:
    """
    LƯU Ý: bảng replenishment_recommendations hiện chỉ có 1 cột `supplier_id`
    (không có cột `preferred_supplier_id` trong schema DB). Bug cũ: câu INSERT
    liệt kê "supplier_id" 2 lần trong danh sách cột trong khi bind
    :preferred_supplier_id vào chỗ đó → Postgres báo lỗi "column specified
    more than once". Đã bỏ cột/param preferred_supplier_id khỏi INSERT.
    Nếu muốn lưu preferred_supplier_id riêng, cần ALTER TABLE thêm cột này
    trước — tham số vẫn giữ trong signature để không phải sửa lời gọi hàm.
    """
    db.execute(text("""
        INSERT INTO public.replenishment_recommendations
            (supplier_id, product_id, suggested_quantity, priority,
             urgency_days, estimated_cost, reason,
             status, model_version)
        VALUES
            (:supplier_id, :product_id, :suggested_quantity, :priority,
             :urgency_days, :estimated_cost, :reason,
             'pending', 'lm-studio-1.0')
    """), {
        "supplier_id": supplier_id,
        "product_id": product_id,
        "suggested_quantity": result["suggested_quantity"],
        "priority": result["priority"],
        "urgency_days": result["urgency_days"],
        "estimated_cost": result["estimated_cost"],
        "reason": reason,
    })
    db.commit()

async def create_replenishment(
    product_id: int,
    product_name: str,
    supplier_id: int,
    current_stock: int,
    min_stock: int,
    max_stock: int,
    avg_daily_out: float,
    estimated_days_left: float | None,
    cost_price: float | None,
    preferred_supplier_id: int | None,
    db: Session,
) -> tuple[dict, int]:
    """
    Full pipeline: DB forecast → tính → LM Studio → lưu.
    Returns (result_dict, tokens_used).
    """
    forecast = get_latest_forecast(db, product_id, supplier_id)

    # Lấy σ (daily) + tần suất + lead_time + chu kỳ đặt hàng thật để tính ROP/Safety Stock/Max theo công thức chuẩn
    daily_df = get_daily_sales(db, product_id, supplier_id)
    daily_std_dev = float(daily_df['y'].std()) if not daily_df.empty else None
    frequency_pct = calculate_frequency_pct(daily_df)
    lead_time_days = get_lead_time_days(db, supplier_id)
    reorder_cycle_days = get_reorder_cycle_days(db, product_id)

    result = calculate_replenishment(
        current_stock, min_stock, max_stock,
        avg_daily_out, estimated_days_left,
        forecast, cost_price,
        daily_std_dev=daily_std_dev,
        lead_time_days=lead_time_days,
        frequency_pct=frequency_pct,
        reorder_cycle_days=reorder_cycle_days,
    )

    reason, tokens = await generate_reason(
        product_name, result, current_stock, min_stock, avg_daily_out,
    )

    result["reason"] = reason

    if result["suggested_quantity"] > 0:
        save_replenishment(db, supplier_id, product_id, result, reason, preferred_supplier_id)

    return result, tokens