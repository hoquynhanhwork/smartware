"""
score_reorder_need.py
─────────────────────
Chấm điểm cảnh báo "Cảnh báo nhập hàng" của AI và No-AI bằng đáp án
khách quan, KHÔNG cần tồn kho từng chạm 0 (data hiện tại không có đợt
hết hàng thật nào — xem lý do đã bàn).

Đáp án tại mỗi ngày t của mỗi sản phẩm:
    cần_đặt_hàng(t) = 1  nếu current_stock(t) < nhu_cầu_THẬT(t+1 .. t+lead_time)
                     = 0  ngược lại
Nhu cầu thật lấy từ chính stock_outbound_items/stock_outbounds — vì 2025
đã xảy ra rồi nên biết chính xác, không phải ước lượng.

Ngày mà cửa sổ (t+1..t+lead_time) vượt quá 30/12/2025 (hết dữ liệu) sẽ
bị loại, không đoán mò.

Input:
  - assessment_AI_2025.xlsx, assessment_notAI_2025.xlsx (đã có current_stock,
    lead_time_days, assessment theo từng ngày/SP)
  - stock_outbounds_export.csv, stock_outbound_items_export.csv (nhu cầu thật)

Output: in ra Recall/Precision/lead-time trung bình cho cả 2 hệ thống,
và lưu chi tiết theo từng SP ra CSV.
"""
import pandas as pd
import numpy as np

AI_XLSX = "/mnt/user-data/uploads/assessment_AI_2025.xlsx"
NOAI_XLSX = "/mnt/user-data/uploads/assessment_notAI_2025.xlsx"
OUTBOUNDS_CSV = "/mnt/user-data/uploads/stock_outbounds_export.csv"
ITEMS_CSV = "/mnt/user-data/uploads/stock_outbound_items_export.csv"

WARN_LABEL = "Cảnh báo nhập hàng"


def load_real_daily_demand() -> pd.DataFrame:
    """product_id x date (đủ mọi ngày, fill 0) -> quantity thật."""
    outbounds = pd.read_csv(OUTBOUNDS_CSV, encoding="utf-8-sig")
    items = pd.read_csv(ITEMS_CSV, encoding="utf-8-sig")
    outbounds["created_dt"] = pd.to_datetime(outbounds["created"], format="mixed", dayfirst=True)
    outbounds = outbounds[outbounds["status"] == "completed"]
    m = items.merge(outbounds[["id", "created_dt"]], left_on="outbound_id", right_on="id")
    m["date"] = m["created_dt"].dt.normalize()
    daily = m.groupby(["product_id", "date"])["quantity"].sum().reset_index()
    return daily


def build_forward_demand(daily: pd.DataFrame, product_id: int, lead_time: int,
                          start: pd.Timestamp, end: pd.Timestamp) -> pd.Series:
    """
    Với mỗi ngày t trong [start, end], trả về tổng nhu cầu thật (t+1..t+lead_time).
    Ngày nào cửa sổ vượt quá dữ liệu thật có (data_end) -> NaN.
    """
    data_end = daily["date"].max()
    full_range = pd.date_range(start, end, freq="D")
    prod_daily = daily[daily["product_id"] == product_id].set_index("date")["quantity"]
    series = prod_daily.reindex(pd.date_range(start, data_end, freq="D"), fill_value=0.0)

    result = {}
    for t in full_range:
        window_end = t + pd.Timedelta(days=lead_time)
        if window_end > data_end:
            result[t] = np.nan
        else:
            result[t] = series.loc[t + pd.Timedelta(days=1): window_end].sum()
    return pd.Series(result)


def score_system(df: pd.DataFrame, daily: pd.DataFrame, label: str) -> tuple[pd.DataFrame, dict]:
    df = df.copy()
    df["date"] = pd.to_datetime(df["date"])
    per_sp_rows = []
    lead_summary = []

    for pid, g in df.groupby("product_id"):
        g = g.sort_values("date").reset_index(drop=True)
        lead_time = int(g["lead_time_days"].iloc[0]) if pd.notna(g["lead_time_days"].iloc[0]) else 7

        fwd = build_forward_demand(daily, pid, lead_time, g["date"].min(), g["date"].max())
        g["fwd_demand"] = g["date"].map(fwd)
        g = g.dropna(subset=["fwd_demand"])  # bỏ ngày cuối kỳ thiếu dữ liệu tương lai
        if g.empty:
            continue

        g["need_reorder"] = g["current_stock"] < g["fwd_demand"]
        g["warned"] = g["assessment"] == WARN_LABEL

        tp = int((g["need_reorder"] & g["warned"]).sum())
        fn = int((g["need_reorder"] & ~g["warned"]).sum())
        fp = int((~g["need_reorder"] & g["warned"]).sum())
        tn = int((~g["need_reorder"] & ~g["warned"]).sum())

        per_sp_rows.append({
            "product_id": pid, "n_days": len(g),
            "n_need_reorder": int(g["need_reorder"].sum()),
            "tp": tp, "fn": fn, "fp": fp, "tn": tn,
        })

        # lead-time cảnh báo trước mỗi ĐỢT cần đặt hàng (chuỗi ngày need_reorder liên tiếp)
        idx = g.index[g["need_reorder"]].tolist()
        if idx:
            episodes, cur = [], [idx[0]]
            for i in idx[1:]:
                if i == cur[-1] + 1:
                    cur.append(i)
                else:
                    episodes.append(cur); cur = [i]
            episodes.append(cur)

            for ep in episodes:
                start_i = ep[0]
                j = start_i - 1
                first_warn = None
                while j >= 0 and g.loc[j, "warned"]:
                    first_warn = j
                    j -= 1
                lead_days = (g.loc[start_i, "date"] - g.loc[first_warn, "date"]).days if first_warn is not None else 0
                lead_summary.append(lead_days)

    per_sp = pd.DataFrame(per_sp_rows)
    TP, FN, FP, TN = per_sp[["tp", "fn", "fp", "tn"]].sum()
    recall = TP / (TP + FN) if (TP + FN) else float("nan")
    precision = TP / (TP + FP) if (TP + FP) else float("nan")
    lead = pd.Series(lead_summary)

    summary = {
        "label": label,
        "n_products": per_sp["product_id"].nunique(),
        "n_days_total": int(per_sp["n_days"].sum()),
        "n_need_reorder_days": int(per_sp["n_need_reorder"].sum()),
        "TP": int(TP), "FN": int(FN), "FP": int(FP), "TN": int(TN),
        "recall": round(recall, 4),
        "precision": round(precision, 4),
        "n_episodes": len(lead),
        "pct_episodes_no_warning": round(100 * (lead == 0).mean(), 1) if len(lead) else None,
        "mean_lead_days": round(lead.mean(), 1) if len(lead) else None,
        "median_lead_days": float(lead.median()) if len(lead) else None,
    }
    return per_sp, summary


def main():
    daily = load_real_daily_demand()

    ai = pd.read_excel(AI_XLSX)
    noai = pd.read_excel(NOAI_XLSX)

    per_sp_ai, summary_ai = score_system(ai, daily, "AI")
    per_sp_noai, summary_noai = score_system(noai, daily, "No-AI")

    print("=== ĐÁP ÁN: cần đặt hàng nếu current_stock < nhu cầu thật (t+1..t+lead_time) ===\n")
    for s in (summary_noai, summary_ai):
        print(f"--- {s['label']} ---")
        for k, v in s.items():
            if k == "label":
                continue
            print(f"  {k}: {v}")
        print()

    with pd.ExcelWriter("/mnt/user-data/outputs/score_reorder_need.xlsx") as w:
        per_sp_ai.to_excel(w, sheet_name="AI_per_SP", index=False)
        per_sp_noai.to_excel(w, sheet_name="NoAI_per_SP", index=False)
        pd.DataFrame([summary_noai, summary_ai]).to_excel(w, sheet_name="summary", index=False)

    print("Đã lưu: /mnt/user-data/outputs/score_reorder_need.xlsx")


if __name__ == "__main__":
    main()