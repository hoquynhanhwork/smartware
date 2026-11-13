"""
assessment_AI_hybrid.py
───────────────────────
Nhánh AI dùng HYBRID (Prophet tổng hợp + GBM toàn cục), xuất file CÙNG CẤU TRÚC
với assessment_AI_2025.xlsx (21 cột, mỗi dòng = 1 sản phẩm x 1 ngày của eval_year):

    year, date, product_id, sku, product_name, current_stock, min_stock, max_stock,
    avg_daily_demand, demand_std_dev, lead_time_days, growth_pct, frequency_pct,
    safety_stock, reorder_point, max_ai, times_below_rop, times_above_max_ai,
    reorder_cycle_days_used, forecast_source, assessment

Mọi thứ KHÔNG liên quan tới dự báo nhu cầu được giữ y hệt assessment_AI_backtest.py
(cách dựng tồn kho theo ngày, σ, tần suất, lead time, chu kỳ nhập, công thức
ROP/Safety Stock/Max, nhãn đánh giá) — hàm được import lại từ chính file đó và từ
prophet_service.py, không viết lại công thức. Chỉ thay nguồn dự báo:
Prophet từng SP  →  hybrid.

Hai chế độ (--mode):
  static  : hybrid huấn luyện 1 lần bằng data TRƯỚC eval_year; ngưỡng cố định cả năm.
            Giống hệt cách file cũ đang làm → so được trực tiếp với file cũ.
  rolling : đầu mỗi tháng huấn luyện lại chỉ bằng data TRƯỚC tháng đó và cập nhật
            ngưỡng (ROP/Max) hàng tháng. Gần cách hệ thống chạy thật hơn; các cột
            ngưỡng khi đó đổi theo tháng chứ không cố định cả năm.
            (times_below_rop/times_above_max_ai vẫn là TỔNG CẢ NĂM của SP, mỗi ngày
            được đếm theo ngưỡng của chính tháng đó.)

Chạy (từ thư mục ai-service, cần file .env như khi chạy assessment_AI_backtest.py):
    python scripts/assessment_AI_hybrid.py --mode static
    python scripts/assessment_AI_hybrid.py --mode rolling
Kết quả: <folder>/output/assessment_AI_<năm>_hybrid_<mode>.xlsx
"""
import argparse
import os
import sys
from pathlib import Path

import numpy as np
import pandas as pd

HERE = Path(__file__).parent
sys.path.insert(0, str(HERE.parent))      # package `app`
sys.path.insert(0, str(HERE))             # assessment_AI_backtest.py

from app.services import hybrid_forecast as hf  # noqa: E402
from app.services.prophet_service import (      # noqa: E402
    check_sufficient_data, preprocess, calculate_growth_pct, calculate_frequency_pct,
    calculate_ai_thresholds, resolve_avg_daily_demand,
)
import assessment_AI_backtest as old               # noqa: E402  (tái dùng hàm CSV + fallback)

COLUMNS = [
    "year", "date", "product_id", "sku", "product_name", "current_stock", "min_stock",
    "max_stock", "avg_daily_demand", "demand_std_dev", "lead_time_days", "growth_pct",
    "frequency_pct", "safety_stock", "reorder_point", "max_ai", "times_below_rop",
    "times_above_max_ai", "reorder_cycle_days_used", "forecast_source", "assessment",
]


def load_csv_data(folder):
    """Giống old.load_csv_data nhưng dùng os.path.join (chạy được cả Windows lẫn Linux)."""
    rd = lambda f: pd.read_csv(os.path.join(folder, f), encoding="utf-8-sig")
    products, inbound = rd("products.csv"), rd("stock_inbound_items_export.csv")
    outbound, suppliers = rd("stock_outbound_items_export.csv"), rd("suppliers.csv")
    for d in (inbound, outbound):
        d["created"] = pd.to_datetime(d["created"], format="mixed", dayfirst=True)
    return products, inbound, outbound, suppliers


def hybrid_thresholds(product_out, product_in, suppliers, supplier_id, train_end, fc_row):
    """
    Bản hybrid của old.compute_ai_forecast_csv(): cùng quy trình/công thức, chỉ thay
    `run_prophet(df_clean)` bằng dự báo hybrid `fc_row` (3 tháng kế tiếp của SP).
    Trả về dict giống old.compute_ai_fallback_csv khi không đủ dữ liệu (ValueError cũ).
    """
    monthly_df = old.get_monthly_sales_csv(product_out, train_end_date=train_end)
    if (fc_row is None or np.isnan(fc_row).any()
            or not check_sufficient_data(monthly_df, min_months=12)):
        return old.compute_ai_fallback_csv(product_out, product_in, suppliers, supplier_id, train_end)

    growth_pct = calculate_growth_pct(monthly_df)
    df_clean = preprocess(monthly_df)
    result = {
        "next_month": int(max(0, round(float(fc_row[0])))),
        "next_2_months": int(max(0, round(float(fc_row[1])))),
        "next_3_months": int(max(0, round(float(fc_row[2])))),
    }
    daily_df = old.get_daily_sales_csv(product_out, train_end_date=train_end)
    daily_std = float(daily_df["y"].std()) if not daily_df.empty else 0.0
    freq = calculate_frequency_pct(daily_df)
    avg_daily, source = resolve_avg_daily_demand(result, df_clean)
    lt = old.get_lead_time_csv(suppliers, supplier_id)
    cycle = old.get_reorder_cycle_days_csv(product_in, train_end_date=train_end)
    th = calculate_ai_thresholds(
        avg_daily_demand=avg_daily, daily_std_dev=daily_std, lead_time_days=lt,
        frequency_pct=freq, reorder_cycle_days=cycle,
    )
    return {
        "rop": th["rop"], "safety_stock": th["safety_stock"], "max_ai": th["max_ai"],
        "avg_daily_demand": round(avg_daily, 2), "demand_std_dev": round(daily_std, 2),
        "lead_time_days": lt, "growth_pct": growth_pct, "frequency_pct": round(freq, 4),
        "reorder_cycle_days_used": th["max_coverage_days_used"],
        "forecast_source": f"hybrid_{source}",
    }


def daily_stock_series(product_in, product_out):
    """Tồn kho cuối mỗi ngày lịch (forward-fill) — cùng kết quả vòng lặp trong old.calculate_AI_daily."""
    tx = pd.concat([
        pd.DataFrame({"d": product_in["created"].dt.normalize(), "q": product_in["quantity"].astype(float)}),
        pd.DataFrame({"d": product_out["created"].dt.normalize(), "q": -product_out["quantity"].astype(float)}),
    ])
    if tx.empty:
        return None
    net = tx.groupby("d")["q"].sum().sort_index().cumsum()
    return net.reindex(pd.date_range(net.index.min(), net.index.max(), freq="D")).ffill()


def build(folder, eval_year, mode, exclude_months, tet_months, use_tet, progress=print):
    products, inbound, outbound, suppliers = load_csv_data(folder)
    end_year = pd.Timestamp(f"{eval_year + 1}-01-01")
    cat_by_pid = products.set_index("id")["category_id"]

    # Ma trận SP x tháng cho hybrid (chỉ data đến hết eval_year; bản huấn luyện mỗi mốc cắt lại phía trước)
    ob = outbound[outbound["created"] < end_year].copy()
    ob["ds"] = ob["created"].dt.to_period("M").dt.to_timestamp()
    long_df = ob.groupby(["product_id", "ds"])["quantity"].sum().reset_index().rename(columns={"quantity": "y"})
    M_full = hf.build_matrix(long_df, end_year, exclude_months)

    origins = ([pd.Timestamp(f"{eval_year}-01-01")] if mode == "static"
               else [pd.Timestamp(f"{eval_year}-{m:02d}-01") for m in range(1, 13)])
    cache, bundles = {}, {}
    for o in origins:
        idx = M_full.columns.get_loc(o)
        progress(f"Huấn luyện hybrid với dữ liệu trước {o:%Y-%m} ({idx} tháng)...")
        bundles[o] = hf.train_bundle(M_full.iloc[:, :idx], cat_by_pid, tet_months, use_tet, cache)

    rows = []
    for n, (_, product) in enumerate(products.iterrows(), 1):
        pid, supplier_id = product["id"], product["supplier_id"]
        if suppliers[suppliers["id"] == supplier_id].empty:
            continue
        p_in = inbound[inbound["product_id"] == pid]
        p_out = outbound[outbound["product_id"] == pid]
        stock = daily_stock_series(p_in, p_out)
        if stock is None:
            continue
        stock = stock[stock.index.year == eval_year]
        if stock.empty:
            continue

        th_by_month = {}
        for o in origins:
            b = bundles[o]
            fc = b.forecasts.loc[pid].values.astype(float) if pid in b.forecasts.index else None
            th_by_month[o.month] = hybrid_thresholds(p_out, p_in, suppliers, supplier_id, o, fc)

        recs = []
        for d, s in stock.items():
            th = th_by_month[1 if mode == "static" else d.month]
            rop, mx = th["rop"], th["max_ai"]
            status = "Cảnh báo nhập hàng" if s <= rop else ("Tồn kho dư" if s > mx else "Hợp lý")
            recs.append((d, s, th, rop, mx, status))
        below = int(sum(r[1] <= r[3] for r in recs))
        above = int(sum(r[1] > r[4] for r in recs))
        for d, s, th, rop, mx, status in recs:
            rows.append({
                "year": d.year, "date": d.date(), "product_id": pid, "sku": product["sku"],
                "product_name": product["name"], "current_stock": s,
                "min_stock": product["min_stock"], "max_stock": product["max_stock"],
                "avg_daily_demand": th["avg_daily_demand"], "demand_std_dev": th["demand_std_dev"],
                "lead_time_days": th["lead_time_days"], "growth_pct": th["growth_pct"],
                "frequency_pct": th["frequency_pct"], "safety_stock": th["safety_stock"],
                "reorder_point": rop, "max_ai": mx, "times_below_rop": below,
                "times_above_max_ai": above, "reorder_cycle_days_used": th.get("reorder_cycle_days_used"),
                "forecast_source": th["forecast_source"], "assessment": status,
            })
        if n % 50 == 0:
            progress(f"  {n}/{len(products)} sản phẩm")
    return pd.DataFrame(rows, columns=COLUMNS)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--folder", default=str(HERE.parent.parent), help="thư mục chứa 4 file CSV (mặc định: thư mục gốc dự án)")
    ap.add_argument("--eval-year", type=int, default=2025)
    ap.add_argument("--mode", choices=["static", "rolling"], default="static")
    ap.add_argument("--exclude-months", default="2023-01")
    ap.add_argument("--tet-months", default=",".join(hf.DEFAULT_TET_MONTHS))
    ap.add_argument("--no-tet", action="store_true")
    ap.add_argument("--out", default=None)
    args = ap.parse_args()

    res = build(args.folder, args.eval_year, args.mode, hf.parse_months(args.exclude_months),
                hf.parse_months(args.tet_months), not args.no_tet)
    out_dir = os.path.join(args.folder, "output")
    os.makedirs(out_dir, exist_ok=True)
    out = args.out or os.path.join(out_dir, f"assessment_AI_{args.eval_year}_hybrid_{args.mode}.xlsx")
    res.to_excel(out, index=False)
    print("Đã lưu:", out, f"({len(res)} dòng, {res['product_id'].nunique()} SP)")
    print("\nPhân bố đánh giá:")
    print(res["assessment"].value_counts().to_string())
    print("\nNguồn demand (theo SP, lần cuối của năm):")
    print(res.drop_duplicates("product_id", keep="last")["forecast_source"].value_counts().to_string())


if __name__ == "__main__":
    main()
