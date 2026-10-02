# app/services/assessment_service.py
import logging
from datetime import datetime, timedelta
from sqlalchemy.orm import Session
from sqlalchemy import text
from app.services.prophet_service import (
    get_daily_sales, calculate_ai_thresholds, calculate_frequency_pct,
    compute_ai_forecast, get_reorder_cycle_days,
)

logger = logging.getLogger(__name__)

async def calculate_assessment_for_product(
    db: Session,
    product_id: int,
    supplier_id: int = 1,
    model_version: str = "assessment-v1"
):
    """
    Tính toán assessment cho 1 sản phẩm và lưu vào bảng warehouse_assessment.
    Sử dụng toàn bộ dữ liệu từ năm 2023 đến 2025.
    """
    # 1. Lấy thông tin sản phẩm và supplier
    product_sql = text("""
        SELECT p.id, p.min_stock, p.max_stock, p.supplier_id, s.lead_time_days
        FROM products p
        LEFT JOIN suppliers s ON s.id = p.supplier_id
        WHERE p.id = :product_id AND p.deleted_at IS NULL
    """)
    product = db.execute(product_sql, {"product_id": product_id}).fetchone()
    if not product:
        raise ValueError(f"Product {product_id} not found")

    # 2. Lấy tổng xuất từ 2023 đến 2025
    outbound_sql = text("""
        SELECT COALESCE(SUM(soi.quantity), 0) AS total_export
        FROM stock_outbound_items soi
        JOIN stock_outbounds so ON so.id = soi.outbound_id
        WHERE soi.product_id = :product_id
          AND so.deleted_at IS NULL
          AND so.status = 'completed'
          AND so.created >= '2023-01-01'
          AND so.created < '2026-01-01'
    """)
    total_export = db.execute(outbound_sql, {"product_id": product_id}).scalar() or 0

    # 3. Lấy xuất theo tháng để tính avg_monthly (toàn bộ 2023-2025)
    monthly_sql = text("""
        SELECT DATE_TRUNC('month', so.created) AS month,
               COALESCE(SUM(soi.quantity), 0) AS qty
        FROM stock_outbound_items soi
        JOIN stock_outbounds so ON so.id = soi.outbound_id
        WHERE soi.product_id = :product_id
          AND so.deleted_at IS NULL
          AND so.status = 'completed'
          AND so.created >= '2023-01-01'
          AND so.created < '2026-01-01'
        GROUP BY month
        ORDER BY month
    """)
    monthly_rows = db.execute(monthly_sql, {"product_id": product_id}).fetchall()
    if monthly_rows:
        avg_monthly = sum(row.qty for row in monthly_rows) / len(monthly_rows)
    else:
        avg_monthly = 0

    # 4. Tính avg_daily từ tổng xuất / số ngày trong 3 năm (1095 ngày)
    # Nếu tổng xuất = 0 thì avg_daily = 0
    # (Dùng làm số liệu MÔ TẢ thực tế lưu vào avg_daily_export/avg_monthly_export,
    #  KHÔNG phải nguồn demand dùng để tính ROP/Safety Stock nữa — xem bước 5)
    if total_export > 0:
        avg_daily = total_export / 1095.0  # 3 năm (365*3)
    elif avg_monthly > 0:
        avg_daily = avg_monthly / 30.0
    else:
        avg_daily = 0

    # 5. Tính recommended_min (= ROP_AI) và recommended_max (= Max_AI)
    #    Nguồn demand chính = Prophet forecast (compute_ai_forecast), đúng
    #    quyết định "Prophet là bộ dự báo chính" đã chốt — KHÔNG dùng
    #    avg_daily thô ở bước 4 để tính ROP nữa (chỉ dùng avg_daily thô
    #    để lưu số liệu mô tả thực tế).
    #    Công thức: Safety Stock = Z x σ x √lead_time x hệ_số_tần_suất (Z=1.65)
    #               ROP = avg_daily_forecast x lead_time + Safety Stock
    #               Max = ROP + avg_daily_forecast x lead_time
    #    Fallback: nếu Prophet không đủ ≥12 tháng data (ValueError), quay về
    #    avg_daily thô + σ/tần suất tính trực tiếp (không qua Prophet).
    stock_sql = text("""
        SELECT COALESCE(SUM(quantity), 0) AS current_stock
        FROM inventory_batches
        WHERE product_id = :product_id
    """)
    current_stock = db.execute(stock_sql, {"product_id": product_id}).scalar() or 0

    try:
        ai_result = compute_ai_forecast(
            db, product_id, product.supplier_id,
            current_stock = current_stock,
            min_stock      = product.min_stock or 0,
            max_stock      = product.max_stock or 0,
        )
        recommended_min = ai_result["rop"]
        recommended_max = ai_result["max_ai"]
    except ValueError:
        # Không đủ 12 tháng data cho Prophet → fallback tính trực tiếp
        # (vẫn theo đúng công thức Z x σ x √LT x hệ_số_tần_suất, chỉ khác
        # nguồn avg_daily_demand là trung bình thô thay vì Prophet)
        lead_time_days = product.lead_time_days or 7
        reorder_cycle_days = get_reorder_cycle_days(db, product_id)
        daily_df = get_daily_sales(db, product_id, product.supplier_id)
        daily_std_dev = float(daily_df['y'].std()) if not daily_df.empty else 0.0
        frequency_pct = calculate_frequency_pct(daily_df)

        if avg_daily > 0:
            thresholds = calculate_ai_thresholds(
                avg_daily_demand   = avg_daily,
                daily_std_dev      = daily_std_dev,
                lead_time_days     = lead_time_days,
                frequency_pct      = frequency_pct,
                reorder_cycle_days = reorder_cycle_days,
            )
            recommended_min = thresholds["rop"]
            recommended_max = thresholds["max_ai"]
        else:
            recommended_min = 0
            recommended_max = 0

    # 6. Xác định assessment_label
    current_min = product.min_stock or 0
    current_max = product.max_stock or 0

    if current_min > recommended_min * 1.2:
        assessment_label = "Min Stock quá cao"
    elif current_min < recommended_min * 0.8 and recommended_min > 0:
        assessment_label = "Min Stock quá thấp"
    elif current_max > recommended_max * 1.2 and recommended_max > 0:
        assessment_label = "Max Stock quá cao"
    else:
        assessment_label = "Hợp lý"

    # 7. Lưu vào bảng warehouse_assessment
    insert_sql = text("""
        INSERT INTO warehouse_assessment
        (product_id, supplier_id, assessment_date,
         current_min_stock, current_max_stock,
         recommended_min, recommended_max,
         total_export_last_90d, avg_monthly_export, avg_daily_export,
         assessment_label, model_version)
        VALUES (
            :product_id, :supplier_id, public.app_today(),
            :current_min, :current_max,
            :recommended_min, :recommended_max,
            :total_export, :avg_monthly, :avg_daily,
            :assessment_label, :model_version
        )
        ON CONFLICT (product_id, assessment_date) DO UPDATE
        SET
            current_min_stock = EXCLUDED.current_min_stock,
            current_max_stock = EXCLUDED.current_max_stock,
            recommended_min = EXCLUDED.recommended_min,
            recommended_max = EXCLUDED.recommended_max,
            total_export_last_90d = EXCLUDED.total_export_last_90d,
            avg_monthly_export = EXCLUDED.avg_monthly_export,
            avg_daily_export = EXCLUDED.avg_daily_export,
            assessment_label = EXCLUDED.assessment_label,
            model_version = EXCLUDED.model_version,
            updated_at = CURRENT_TIMESTAMP
    """)
    db.execute(insert_sql, {
        "product_id": product_id,
        "supplier_id": product.supplier_id,
        "current_min": current_min,
        "current_max": current_max,
        "recommended_min": recommended_min,
        "recommended_max": recommended_max,
        "total_export": total_export,
        "avg_monthly": avg_monthly,
        "avg_daily": avg_daily,
        "assessment_label": assessment_label,
        "model_version": model_version,
    })
    db.commit()

    return {
        "product_id": product_id,
        "supplier_id": product.supplier_id,
        "current_min_stock": current_min,
        "current_max_stock": current_max,
        "recommended_min": recommended_min,
        "recommended_max": recommended_max,
        "total_export_last_90d": total_export,
        "avg_monthly_export": avg_monthly,
        "avg_daily_export": avg_daily,
        "assessment_label": assessment_label,
    }


async def batch_assess(db: Session, supplier_id: int = 1, limit: int = 100):
    """Chạy assessment cho tất cả sản phẩm (hoặc limit)."""
    sql = text("""
        SELECT id FROM products
        WHERE deleted_at IS NULL
        AND supplier_id = :supplier_id
        AND id > 0
        LIMIT :limit
    """)
    rows = db.execute(sql, {"supplier_id": supplier_id, "limit": limit}).fetchall()
    results = []
    for row in rows:
        try:
            result = await calculate_assessment_for_product(db, row.id, supplier_id)
            results.append(result)
        except Exception as e:
            logger.error(f"Error assessing product {row.id}: {e}")
    return results