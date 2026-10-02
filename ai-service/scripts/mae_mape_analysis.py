"""
mae_mape_analysis.py
─────────────────────
Cross-validation kiểu rolling-origin CHỈ trong 2023-2024 (không đụng 2025):
  1. Tính MAE / MAPE / WAPE độ chính xác forecast của Prophet (mục còn
     thiếu trong sơ đồ gốc).
  2. So sánh cấu hình Prophet CŨ (multiplicative, Fourier mặc định) với
     MỚI (additive, Fourier=4) bằng số liệu — không mặc định cấu hình
     mới là tốt hơn.
  3. Rút ngưỡng "SP quá thưa" từ chính kết quả CV, đồng thời KIỂM TRA
     giả thuyết "thưa hơn → sai số cao hơn" (nếu data không ủng hộ thì
     báo rõ, không ép ra ngưỡng).

ĐẶT FILE NÀY TRONG ai-service/scripts/ (như assessment_AI_backtest.py),
chạy từ thư mục ai-service:  python scripts\\mae_mape_analysis.py
Chạy lâu (Prophet fit nhiều lần/SP: 4 fold x 2 cấu hình).
"""

import sys
import os
from pathlib import Path

import pandas as pd

sys.path.insert(0, str(Path(__file__).parent.parent))

from app.services.prophet_service import (
    check_sufficient_data,
    run_prophet_cv,
    calculate_frequency_pct,
)

CONFIGS = {
    "cu":  {"seasonality_mode": "multiplicative", "yearly_order": True},
    "moi": {"seasonality_mode": "additive",        "yearly_order": 4},
}


# ================================================================
#  LOAD DATA — chỉ cần products + outbound
# ================================================================

def load_csv_data(folder):
    products = pd.read_csv(folder + r"\products.csv", encoding="utf-8-sig")
    outbound = pd.read_csv(folder + r"\stock_outbound_items_export.csv", encoding="utf-8-sig")
    outbound["created"] = pd.to_datetime(outbound["created"], format="mixed", dayfirst=True)
    return products, outbound


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


# ================================================================
#  CHẠY CV CHO TOÀN BỘ SP (cả 2 cấu hình)
# ================================================================

def run_all_cv(products: pd.DataFrame, outbound: pd.DataFrame, cutoff_ts: pd.Timestamp) -> pd.DataFrame:

    results = []

    for i, product_id in enumerate(products["id"]):

        product = products[products["id"] == product_id].iloc[0]
        product_out = outbound[outbound["product_id"] == product_id].copy()

        monthly_df = get_monthly_sales_csv(product_out, train_end_date=cutoff_ts)

        if not check_sufficient_data(monthly_df, min_months=12):
            continue

        daily_df = get_daily_sales_csv(product_out, train_end_date=cutoff_ts)

        row = {
            "product_id": product_id,
            "sku": product["sku"],
            "product_name": product["name"],
            "months_train": len(monthly_df),
            "frequency_pct": round(calculate_frequency_pct(daily_df), 4),
        }

        for name, cfg in CONFIGS.items():
            cv = run_prophet_cv(monthly_df, **cfg)
            row[f"mae_{name}"] = cv["mae"]
            row[f"mape_{name}"] = cv["mape"]
            row[f"wape_{name}"] = cv["wape"]
            row[f"n_folds_{name}"] = cv["n_folds"]

        results.append(row)

        if (i + 1) % 10 == 0:
            print(f"  ... {i + 1}/{len(products)} SP đã xong", flush=True)

    return pd.DataFrame(results)


# ================================================================
#  SO SÁNH CẤU HÌNH CŨ vs MỚI
# ================================================================

def compare_configs(result: pd.DataFrame):

    both = result.dropna(subset=["mae_cu", "mae_moi"])
    if both.empty:
        print("Không có SP nào chạy được CV ở cả 2 cấu hình.")
        return

    print(f"\n=== SO SÁNH CẤU HÌNH PROPHET ({len(both)} SP chạy được cả 2) ===")
    for metric in ["mae", "mape", "wape"]:
        cu = both[f"{metric}_cu"].dropna()
        moi = both[f"{metric}_moi"].dropna()
        print(f"{metric.upper():5s} trung bình — cũ: {cu.mean():8.2f} | mới: {moi.mean():8.2f}"
              f"   (trung vị — cũ: {cu.median():.2f} | mới: {moi.median():.2f})")

    moi_better = (both["mae_moi"] < both["mae_cu"]).sum()
    cu_better = (both["mae_cu"] < both["mae_moi"]).sum()
    print(f"Số SP có MAE thấp hơn — cấu hình mới: {moi_better} | cấu hình cũ: {cu_better}"
          f" | bằng nhau: {len(both) - moi_better - cu_better}")
    print("=> Nếu cấu hình mới KHÔNG tốt hơn rõ rệt, đổi mặc định của run_prophet()"
          " về seasonality_mode='multiplicative', yearly_order=True.")


# ================================================================
#  RÚT NGƯỠNG "QUÁ THƯA" TỪ KẾT QUẢ CV (cấu hình mới)
# ================================================================

def derive_sparse_threshold(result: pd.DataFrame):

    valid = result[result["mape_moi"].notna() & result["wape_moi"].notna()].copy()
    if len(valid) < 10:
        print("\nQuá ít SP có MAPE hợp lệ — không rút được ngưỡng.")
        return

    valid["freq_bin"] = pd.qcut(valid["frequency_pct"], q=5, duplicates="drop")

    print("\n=== Sai số theo NGŨ PHÂN VỊ tần suất giao dịch (cấu hình mới) ===")
    print("(MAPE bị thổi phồng khi tháng bán ít; WAPE không bị — so cả hai)")
    summary = valid.groupby("freq_bin", observed=True).agg(
        n=("mape_moi", "size"),
        mape_tb=("mape_moi", "mean"),
        wape_tb=("wape_moi", "mean"),
    ).round(2)
    print(summary)

    # Điểm cắt: chỉ thử các mốc ngũ phân vị đã có sẵn từ chính data,
    # chọn mốc mà nhóm thưa (<= mốc) có MAPE cao hơn nhóm còn lại nhiều nhất
    edges = valid["frequency_pct"].quantile([0.2, 0.4, 0.6, 0.8]).unique()
    best_threshold, best_diff = None, 0.0
    for t in edges:
        low = valid[valid["frequency_pct"] <= t]
        high = valid[valid["frequency_pct"] > t]
        if low.empty or high.empty:
            continue
        diff = low["mape_moi"].mean() - high["mape_moi"].mean()
        if diff > best_diff:
            best_diff, best_threshold = diff, t

    if best_threshold is None:
        print("\n=> KHÔNG tìm được mốc nào mà nhóm thưa có MAPE cao hơn nhóm còn lại."
              "\n   Data không ủng hộ giả thuyết 'thưa hơn → sai số cao hơn' —"
              " không có căn cứ đặt ngưỡng chuyển sang fallback theo độ thưa.")
        return

    low = valid[valid["frequency_pct"] <= best_threshold]
    high = valid[valid["frequency_pct"] > best_threshold]
    print(f"\n=> Mốc tách rõ nhất (rút từ data): frequency_pct <= {best_threshold:.4f}")
    print(f"   MAPE nhóm thưa: {low['mape_moi'].mean():.1f}% ({len(low)} SP)"
          f" | nhóm còn lại: {high['mape_moi'].mean():.1f}% ({len(high)} SP)")
    print(f"   WAPE nhóm thưa: {low['wape_moi'].mean():.1f}%"
          f" | nhóm còn lại: {high['wape_moi'].mean():.1f}%")
    if low["wape_moi"].mean() <= high["wape_moi"].mean():
        print("   LƯU Ý: WAPE KHÔNG cho thấy nhóm thưa kém hơn — chênh lệch MAPE có thể"
              " chỉ do tháng bán ít làm MAPE phồng lên, chưa đủ căn cứ dùng ngưỡng này.")


# ================================================================
#  CHẠY
# ================================================================

if __name__ == "__main__":

    folder = r"C:\xampp\htdocs\smartware"

    products, outbound = load_csv_data(folder)
    cutoff_ts = pd.Timestamp("2025-01-01")

    print(f"Bắt đầu CV cho {len(products)} sản phẩm (chỉ dùng data 2023-2024, 2 cấu hình)...")
    result = run_all_cv(products, outbound, cutoff_ts)

    output_folder = folder + r"\output"
    os.makedirs(output_folder, exist_ok=True)
    output = output_folder + r"\mae_mape_analysis.xlsx"
    result.to_excel(output, index=False)

    print(f"\nDa luu: {output}")
    print(f"SP chạy được CV: {len(result)} / {len(products)}")

    if not result.empty:
        compare_configs(result)
        derive_sparse_threshold(result)