"""
assessment_AI_backtest.py
──────────────────────────
Nhánh AI — song song với assessment_notAI.py, cùng đọc 4 file CSV
(products, inbound, outbound, suppliers) để đảm bảo 2 nhánh so sánh
trên đúng 1 tập dữ liệu.

Train: Prophet train trên data TRƯỚC eval_year (mặc định 2025-01-01,
tức 2023-2024). Test/đánh giá: CHỈ năm eval_year (2025) — để so trực
tiếp với assessment_notAI_2025.xlsx (đã sửa cùng nguyên tắc train/test
này).

Tái dùng các hàm THUẦN TÚY (không phụ thuộc DB) đã có sẵn trong
app/services/prophet_service.py của ai-service — KHÔNG viết lại công
thức, tránh 2 nơi có 2 công thức lệch nhau:
    - check_sufficient_data, preprocess, run_prophet, calculate_order
    - calculate_growth_pct, calculate_frequency_pct, calculate_ai_thresholds

ĐẶT FILE NÀY TRONG ai-service/scripts/ (cùng cấp với ingest_inventory.py)
để dòng sys.path bên dưới trỏ đúng vào thư mục gốc ai-service/ (nơi có
package `app`). Nếu đặt ở chỗ khác, cần tự chỉnh lại sys.path.
"""

import sys
import os
from pathlib import Path

import pandas as pd

# Trỏ về thư mục gốc ai-service/ để import được `app.services.prophet_service`
# (giống cách ingest_inventory.py / ingest_policy.py đang làm)
sys.path.insert(0, str(Path(__file__).parent.parent))

from app.services.prophet_service import (
    check_sufficient_data,
    preprocess,
    run_prophet,
    calculate_order,
    calculate_growth_pct,
    calculate_frequency_pct,
    calculate_ai_thresholds,
    resolve_avg_daily_demand,
)


# ================================================================
#  LOAD DATA — giống hệt assessment_notAI.py (cùng nguồn, cùng cột)
# ================================================================

def load_csv_data(folder):

    products = pd.read_csv(folder + r"\products.csv", encoding="utf-8-sig")
    inbound = pd.read_csv(folder + r"\stock_inbound_items_export.csv", encoding="utf-8-sig")
    outbound = pd.read_csv(folder + r"\stock_outbound_items_export.csv", encoding="utf-8-sig")
    suppliers = pd.read_csv(folder + r"\suppliers.csv", encoding="utf-8-sig")

    inbound["created"] = pd.to_datetime(inbound["created"], format="mixed", dayfirst=True)
    outbound["created"] = pd.to_datetime(outbound["created"], format="mixed", dayfirst=True)

    return products, inbound, outbound, suppliers


# ================================================================
#  Bản CSV của get_monthly_sales / get_daily_sales / get_lead_time_days
#  trong prophet_service.py — cùng hình dạng output (cột ds,y), chỉ
#  khác nguồn (DataFrame đã lọc sẵn theo product thay vì query DB)
# ================================================================

def get_monthly_sales_csv(product_out: pd.DataFrame, train_end_date=None) -> pd.DataFrame:
    df = product_out
    if train_end_date is not None:
        df = df[df["created"] < train_end_date]

    if df.empty:
        return pd.DataFrame(columns=["ds", "y"])

    df = df.copy()
    df["ds"] = df["created"].dt.to_period("M").dt.to_timestamp()
    monthly = df.groupby("ds")["quantity"].sum().reset_index()
    monthly.columns = ["ds", "y"]
    monthly["y"] = monthly["y"].astype(float)
    return monthly


def get_daily_sales_csv(product_out: pd.DataFrame, train_end_date=None) -> pd.DataFrame:
    df = product_out
    if train_end_date is not None:
        df = df[df["created"] < train_end_date]

    if df.empty:
        return pd.DataFrame(columns=["ds", "y"])

    df = df.copy()
    df["ds"] = df["created"].dt.normalize()
    daily = df.groupby("ds")["quantity"].sum().reset_index()
    daily.columns = ["ds", "y"]

    full_range = pd.date_range(start=daily["ds"].min(), end=daily["ds"].max(), freq="D")
    daily = daily.set_index("ds").reindex(full_range, fill_value=0).reset_index()
    daily.columns = ["ds", "y"]
    return daily


def get_lead_time_csv(suppliers: pd.DataFrame, supplier_id, default_days: int = 7) -> int:
    row = suppliers[suppliers["id"] == supplier_id]
    if row.empty:
        return default_days
    lt = row["lead_time_days"].iloc[0]
    if pd.isna(lt) or lt == 0:
        return default_days
    return int(lt)


def get_reorder_cycle_days_csv(product_in: pd.DataFrame, train_end_date=None) -> float | None:
    """
    Bản CSV của get_reorder_cycle_days() trong prophet_service.py — trung
    vị khoảng cách giữa các lần nhập hàng liên tiếp (ngày), dùng cho phần
    Max thay cho giả định "chu kỳ ≈ lead_time" (đã kiểm chứng sai qua
    reorder_cycle_analysis.py).

    CHỈ tính từ inbound TRƯỚC train_end_date (giống mọi feature khác
    trong backtest này) — nếu tính từ full lịch sử sẽ để lộ thông tin
    2025 vào ngưỡng Max dùng để đánh giá 2025, phá vỡ nguyên tắc train/test
    đã áp dụng xuyên suốt.

    Trả về None nếu không đủ ≥2 lần nhập trong kỳ train — nơi gọi tự
    fallback về lead_time_days.
    """
    df = product_in
    if train_end_date is not None:
        df = df[df["created"] < train_end_date]

    inbound_dates = df["created"].dt.normalize().drop_duplicates().sort_values()

    if len(inbound_dates) < 2:
        return None

    gaps = inbound_dates.diff().dropna().dt.days
    if gaps.empty:
        return None

    return float(gaps.median())


# ================================================================
#  Lõi tính AI ROP/Safety Stock/Max — bản CSV của compute_ai_forecast()
#  (dùng lại đúng công thức từ prophet_service.py, chỉ khác cách lấy data)
# ================================================================

def compute_ai_forecast_csv(
    product_out: pd.DataFrame,
    product_in: pd.DataFrame,
    suppliers: pd.DataFrame,
    supplier_id,
    current_stock: float,
    min_stock: float,
    max_stock: float,
    train_end_date,
) -> dict:
    """Raise ValueError nếu không đủ ≥12 tháng data (không chạy Prophet được)."""

    monthly_df = get_monthly_sales_csv(product_out, train_end_date=train_end_date)

    if not check_sufficient_data(monthly_df, min_months=12):
        raise ValueError(
            f"Chỉ có {len(monthly_df)} tháng data, cần ít nhất 12 tháng để dự báo chính xác."
        )

    growth_pct = calculate_growth_pct(monthly_df)

    df_clean = preprocess(monthly_df)
    prophet_result = run_prophet(df_clean)

    daily_df = get_daily_sales_csv(product_out, train_end_date=train_end_date)
    daily_std_dev = float(daily_df["y"].std()) if not daily_df.empty else 0.0
    frequency_pct = calculate_frequency_pct(daily_df)

    avg_daily_demand_forecast, demand_source = resolve_avg_daily_demand(prophet_result, df_clean)
    lead_time_days = get_lead_time_csv(suppliers, supplier_id)
    reorder_cycle_days = get_reorder_cycle_days_csv(product_in, train_end_date=train_end_date)

    thresholds = calculate_ai_thresholds(
        avg_daily_demand=avg_daily_demand_forecast,
        daily_std_dev=daily_std_dev,
        lead_time_days=lead_time_days,
        frequency_pct=frequency_pct,
        reorder_cycle_days=reorder_cycle_days,
    )

    # calculate_order() chạy SAU khi có rop/safety_stock, để warning/
    # suggested_order dùng đúng ngưỡng ROP (đồng bộ với prophet_service.py)
    prophet_result = calculate_order(
        prophet_result, current_stock, min_stock, max_stock,
        rop=thresholds["rop"], safety_stock=thresholds["safety_stock"],
    )

    prophet_result["rop"] = thresholds["rop"]
    prophet_result["safety_stock"] = thresholds["safety_stock"]
    prophet_result["max_ai"] = thresholds["max_ai"]
    prophet_result["demand_std_dev"] = round(daily_std_dev, 2)
    prophet_result["lead_time_days"] = lead_time_days
    prophet_result["avg_daily_demand"] = round(avg_daily_demand_forecast, 2)
    prophet_result["growth_pct"] = growth_pct
    prophet_result["frequency_pct"] = round(frequency_pct, 4)
    prophet_result["reorder_cycle_days_used"] = thresholds["max_coverage_days_used"]
    # forecast_source phản ánh ĐÚNG nguồn avg_daily_demand thực tế đã dùng
    # (không còn gán cứng "prophet" cho mọi trường hợp) — để đo được tỷ lệ
    # phải fallback trong 3 tháng dự báo (đã bàn: cần biết % này trước khi
    # kết luận Prophet có đáng tin hay không).
    prophet_result["forecast_source"] = demand_source

    return prophet_result


def compute_ai_fallback_csv(
    product_out: pd.DataFrame,
    product_in: pd.DataFrame,
    suppliers: pd.DataFrame,
    supplier_id,
    train_end_date,
) -> dict:
    """
    Fallback khi SP không đủ 12 tháng data cho Prophet — vẫn dùng đúng
    công thức Z x σ x √LT x hệ_số_tần_suất, chỉ khác nguồn avg_daily_demand
    là trung bình thô (train-only) thay vì Prophet forecast.
    """
    monthly_df = get_monthly_sales_csv(product_out, train_end_date=train_end_date)
    avg_monthly = monthly_df["y"].mean() if not monthly_df.empty else 0
    avg_daily_demand = (avg_monthly / 30.0) if avg_monthly else 0.0

    daily_df = get_daily_sales_csv(product_out, train_end_date=train_end_date)
    daily_std_dev = float(daily_df["y"].std()) if not daily_df.empty else 0.0
    frequency_pct = calculate_frequency_pct(daily_df)
    lead_time_days = get_lead_time_csv(suppliers, supplier_id)
    reorder_cycle_days = get_reorder_cycle_days_csv(product_in, train_end_date=train_end_date)

    if avg_daily_demand > 0:
        thresholds = calculate_ai_thresholds(
            avg_daily_demand=avg_daily_demand,
            daily_std_dev=daily_std_dev,
            lead_time_days=lead_time_days,
            frequency_pct=frequency_pct,
            reorder_cycle_days=reorder_cycle_days,
        )
        rop, safety_stock, max_ai = thresholds["rop"], thresholds["safety_stock"], thresholds["max_ai"]
        reorder_cycle_days_used = thresholds["max_coverage_days_used"]
    else:
        rop = safety_stock = max_ai = 0
        reorder_cycle_days_used = None

    return {
        "rop": rop,
        "safety_stock": safety_stock,
        "max_ai": max_ai,
        "avg_daily_demand": round(avg_daily_demand, 2),
        "demand_std_dev": round(daily_std_dev, 2),
        "lead_time_days": lead_time_days,
        "growth_pct": calculate_growth_pct(monthly_df),
        "frequency_pct": round(frequency_pct, 4),
        "reorder_cycle_days_used": reorder_cycle_days_used,
        "forecast_source": "fallback_avg",
    }


# ================================================================
#  ĐÁNH GIÁ THEO NGÀY - AI (chỉ eval_year, để so với No-AI)
# ================================================================

def calculate_AI_daily(
    products: pd.DataFrame,
    inbound: pd.DataFrame,
    outbound: pd.DataFrame,
    suppliers: pd.DataFrame,
    eval_year: int = 2025,
) -> pd.DataFrame:

    results = []
    cutoff_ts = pd.Timestamp(f"{eval_year}-01-01")

    for product_id in products["id"]:

        product = products[products["id"] == product_id].iloc[0]
        min_stock = product["min_stock"]
        max_stock = product["max_stock"]
        supplier_id = product["supplier_id"]

        supplier = suppliers[suppliers["id"] == supplier_id]
        if supplier.empty:
            continue

        product_in = inbound[inbound["product_id"] == product_id].copy()
        product_out = outbound[outbound["product_id"] == product_id].copy()

        product_in["type"] = "IN"
        product_out["type"] = "OUT"

        transactions = pd.concat(
            [product_in[["created", "quantity", "type"]], product_out[["created", "quantity", "type"]]],
            ignore_index=True,
        )
        if transactions.empty:
            continue
        transactions = transactions.sort_values("created")

        # Tồn kho tại thời điểm cutoff (đầu eval_year) — cần cho calculate_order()
        pre_cutoff = transactions[transactions["created"] < cutoff_ts]
        stock_at_cutoff = 0
        for _, r in pre_cutoff.iterrows():
            stock_at_cutoff += r["quantity"] if r["type"] == "IN" else -r["quantity"]

        try:
            ai_result = compute_ai_forecast_csv(
                product_out, product_in, suppliers, supplier_id,
                current_stock=stock_at_cutoff,
                min_stock=min_stock,
                max_stock=max_stock,
                train_end_date=cutoff_ts,
            )
        except ValueError:
            ai_result = compute_ai_fallback_csv(product_out, product_in, suppliers, supplier_id, cutoff_ts)

        rop = ai_result["rop"]
        max_ai = ai_result["max_ai"]

        # Dựng tồn kho chạy theo ngày TOÀN BỘ lịch sử (giống No-AI)
        stock = 0
        daily_history = []
        for _, row in transactions.iterrows():
            stock += row["quantity"] if row["type"] == "IN" else -row["quantity"]
            daily_history.append({"date": row["created"].date(), "stock": stock})

        daily_history = pd.DataFrame(daily_history)
        daily_stock = daily_history.groupby("date").last().reset_index()

        # ===========================
        # TRẢI RA TẤT CẢ NGÀY LỊCH (forward-fill) — đồng bộ với fix đã
        # áp dụng cho assessment_notAI.py: trước đây chỉ có ngày có giao
        # dịch, bỏ sót các ngày yên lặng (tồn kho vẫn giữ nguyên chứ
        # không "biến mất" giữa 2 lần giao dịch). Không forward-fill sẽ
        # đếm "số lần giao dịch" thay vì "số ngày lịch" — không khớp
        # cách đếm bên notAI.
        # ===========================
        daily_stock["date"] = pd.to_datetime(daily_stock["date"])
        daily_stock = daily_stock.set_index("date")

        full_range = pd.date_range(
            start=daily_stock.index.min(),
            end=daily_stock.index.max(),
            freq="D",
        )
        daily_stock = daily_stock.reindex(full_range)
        daily_stock["stock"] = daily_stock["stock"].ffill()

        daily_stock = daily_stock.reset_index().rename(columns={"index": "date"})
        daily_stock["date"] = daily_stock["date"].dt.date
        daily_stock["year"] = pd.to_datetime(daily_stock["date"]).dt.year

        # Đếm số ngày lịch trong eval_year mà tồn kho dưới ROP / vượt
        # Max_AI — dùng ĐÚNG NGƯỠNG CỦA CHÍNH AI (rop/max_ai), không
        # phải min_stock/max_stock tĩnh — mỗi bên (No-AI/AI) chỉ đo 1
        # lần, bằng ngưỡng của chính mình (đã thống nhất trước đó).
        daily_stock_eval_year = daily_stock[daily_stock["year"] == eval_year]
        times_below_rop = int((daily_stock_eval_year["stock"] <= rop).sum())
        times_above_max_ai = int((daily_stock_eval_year["stock"] > max_ai).sum())

        for _, row in daily_stock.iterrows():
            row_year = pd.to_datetime(row["date"]).year
            if row_year != eval_year:
                continue

            current_stock_day = row["stock"]

            if current_stock_day <= rop:
                status = "Cảnh báo nhập hàng"
            elif current_stock_day > max_ai:
                status = "Tồn kho dư"
            else:
                status = "Hợp lý"

            results.append({
                "year": row_year,
                "date": row["date"],
                "product_id": product_id,
                "sku": product["sku"],
                "product_name": product["name"],
                "current_stock": current_stock_day,
                "min_stock": min_stock,
                "max_stock": max_stock,
                "avg_daily_demand": ai_result["avg_daily_demand"],
                "demand_std_dev": ai_result["demand_std_dev"],
                "lead_time_days": ai_result["lead_time_days"],
                "growth_pct": ai_result["growth_pct"],
                "frequency_pct": ai_result["frequency_pct"],
                "safety_stock": ai_result["safety_stock"],
                "reorder_point": rop,
                "max_ai": max_ai,
                "times_below_rop": times_below_rop,
                "times_above_max_ai": times_above_max_ai,
                "reorder_cycle_days_used": ai_result.get("reorder_cycle_days_used"),
                "forecast_source": ai_result["forecast_source"],
                "assessment": status,
            })

    return pd.DataFrame(results)


# ================================================================
#  CHẠY
# ================================================================

if __name__ == "__main__":

    folder = r"C:\xampp\htdocs\smartware"

    products, inbound, outbound, suppliers = load_csv_data(folder)

    result = calculate_AI_daily(products, inbound, outbound, suppliers, eval_year=2025)

    output_folder = folder + r"\output"
    os.makedirs(output_folder, exist_ok=True)

    output = output_folder + r"\assessment_AI_2025.xlsx"
    result.to_excel(output, index=False)

    print("Đã lưu:", output)
    if not result.empty:
        # 4 nhãn có thể có: next_month (Prophet dùng thẳng), 3_month_avg
        # (Prophet nhưng phải làm mượt 3 tháng), historical_fallback
        # (Prophet ra cả 3 tháng vô lý, fallback về lịch sử), fallback_avg
        # (không đủ 12 tháng, không chạy Prophet được từ đầu)
        n_products = result["product_id"].nunique()
        by_source = result.drop_duplicates("product_id")["forecast_source"].value_counts()
        print(f"\nTổng {n_products} sản phẩm — phân bố theo nguồn demand thực tế đã dùng:")
        for source, count in by_source.items():
            print(f"  {source}: {count} SP ({100*count/n_products:.1f}%)")
    else:
        print("Không có sản phẩm nào đủ dữ liệu.")