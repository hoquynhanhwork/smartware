"""
run_hybrid_csv.py
─────────────────
Chạy hybrid (Prophet tổng hợp + GBM toàn cục) TRỰC TIẾP từ file CSV, không cần
DB/FastAPI — để xuất dữ liệu dự báo và kiểm tra độ chính xác.

Cần: products.csv, stock_outbound_items_export.csv (cùng thư mục --folder).

Ví dụ (chạy từ thư mục ai-service):
    python scripts/run_hybrid_csv.py --folder "C:\\xampp\\htdocs\\smartware"
    python scripts/run_hybrid_csv.py --folder ... --backtest-from 2025-01-01

Kết quả ghi vào <folder>/output/:
    hybrid_forecast_next3m.csv   dự báo 3 tháng kế tiếp cho từng SP
    hybrid_backtest.csv          (nếu --backtest-from) dự báo vs thực tế theo mốc/horizon
    hybrid_backtest_summary.csv  (nếu --backtest-from) WAPE/bias theo horizon vs baseline trung bình
"""
import argparse
import os
import sys
from pathlib import Path

import pandas as pd

sys.path.insert(0, str(Path(__file__).parent.parent))
from app.services import hybrid_forecast as hf  # noqa: E402  (module thuần, không cần .env)


def load(folder: str):
    products = pd.read_csv(os.path.join(folder, "products.csv"), encoding="utf-8-sig")
    ob = pd.read_csv(os.path.join(folder, "stock_outbound_items_export.csv"), encoding="utf-8-sig")
    ob["created"] = pd.to_datetime(ob["created"], format="mixed", dayfirst=True)
    return products, ob


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--folder", required=True)
    ap.add_argument("--exclude-months", default="2023-01",
                    help="tháng bỏ khỏi train (vd tháng đầu data bị ramp-up/thiếu ngày). Rỗng = không bỏ.")
    ap.add_argument("--tet-months", default=",".join(hf.DEFAULT_TET_MONTHS))
    ap.add_argument("--no-tet", action="store_true", help="tắt regressor Tết")
    ap.add_argument("--end", default=None, help="chỉ dùng dữ liệu TRƯỚC ngày này (YYYY-MM-DD); mặc định = hết tháng đủ cuối cùng")
    ap.add_argument("--backtest-from", default=None, help="tháng đầu tiên cần dự báo trong backtest, vd 2025-01-01")
    ap.add_argument("--n-origins", type=int, default=12)
    args = ap.parse_args()

    products, ob = load(args.folder)
    as_of = None
    if args.end:                                   # chỉ dùng giao dịch TRƯỚC ngày --end
        end_ts = pd.Timestamp(args.end)
        ob = ob[ob["created"] < end_ts].copy()
        as_of = end_ts - pd.Timedelta(days=1)
    end_excl = hf.infer_end_exclusive(ob["created"].max(), as_of)
    ob["ds"] = ob["created"].dt.to_period("M").dt.to_timestamp()
    long_df = ob.groupby(["product_id", "ds"])["quantity"].sum().reset_index().rename(columns={"quantity": "y"})
    exclude = hf.parse_months(args.exclude_months)
    tet = hf.parse_months(args.tet_months)
    cat_by_pid = products.set_index("id")["category_id"]

    M_full = hf.build_matrix(long_df, end_excl, exclude)
    print(f"Ma trận: {M_full.shape[0]} SP x {M_full.shape[1]} tháng "
          f"({M_full.columns[0].date()} → {M_full.columns[-1].date()}); bỏ tháng: {sorted(str(m.date()) for m in exclude)}")

    cache = {}
    prog = lambda i, n: print(f"  Prophet tổng hợp: {i}/{n}", flush=True)
    out_dir = os.path.join(args.folder, "output")
    os.makedirs(out_dir, exist_ok=True)

    if args.backtest_from:
        bt = hf.walk_forward(M_full, cat_by_pid, pd.Timestamp(args.backtest_from), args.n_origins,
                             tet, not args.no_tet, cache, prog)
        bt.to_csv(os.path.join(out_dir, "hybrid_backtest.csv"), index=False, encoding="utf-8-sig")
        summ = hf.summarize_backtest(bt)
        summ.to_csv(os.path.join(out_dir, "hybrid_backtest_summary.csv"), index=False, encoding="utf-8-sig")
        print("\n=== Backtest walk-forward (WAPE thấp = tốt) ===")
        print(summ.to_string(index=False))

    bundle = hf.train_bundle(M_full, cat_by_pid, tet, not args.no_tet, cache, prog)
    fc = bundle.forecasts.copy()
    fc.columns = [f"forecast_{d.strftime('%Y-%m')}" for d in bundle.target_months]
    fc = fc.round().astype("Int64")
    fc.insert(0, "sku", products.set_index("id")["sku"].reindex(fc.index).values)
    fc.insert(1, "product_name", products.set_index("id")["name"].reindex(fc.index).values)
    fc.insert(2, "months_valid", bundle.months_valid.reindex(fc.index).values)
    fc.index.name = "product_id"
    path = os.path.join(out_dir, "hybrid_forecast_next3m.csv")
    fc.reset_index().to_csv(path, index=False, encoding="utf-8-sig")
    print(f"\nĐã lưu {path}  ({fc.iloc[:, 3:].notna().all(axis=1).sum()}/{len(fc)} SP có dự báo đủ 3 tháng)")
    print(f"Huấn luyện đến hết {bundle.origin_month.strftime('%Y-%m')}; dòng train theo horizon: {bundle.n_train_rows}")


if __name__ == "__main__":
    main()
