#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
THỬ NGHIỆM NGÀY LỄ (không đụng pipeline chính) — so 3 biến thể của mô hình hybrid bằng walk-forward, chỉ dùng dữ liệu TRƯỚC mỗi mốc.
  A = cờ Tết theo tháng (như hiện tại, hybrid-1.0)
  B = cờ Tết theo tháng + regressor ngày lễ theo danh mục (PB)
  C = thay cờ Tết theo tháng bằng cửa sổ Tết (21 ngày trước, 6 ngày sau) + ngày lễ theo danh mục (PC)
Cần: app/services/hybrid_forecast_holidays.py (đặt cạnh hybrid_forecast.py). Chạy từ thư mục ai-service:
    python scripts/run_holiday_experiment.py --folder ..\\raw --out ..\\holiday [--inbound ..\\raw\\stock_inbound_items_export.csv]
Ghi (--out): bt_A.csv, bt_B.csv, bt_C.csv (dự báo từng mốc), holiday_ablation_wape.csv (WAPE horizon 1), holiday_run_manifest.json
Kỳ đánh giá (mục tiêu = THÁNG ĐƯỢC DỰ BÁO, horizon 1): chon_2024H2 = 2024-07..2024-12 ; holdout_2025 = cả năm --eval-year.
LƯU Ý: kế hoạch ngày lễ PB (ngày lễ nào cho danh mục nào) là cố định trong file này; không rõ nó được chọn dựa trên kỳ nào,
       nếu nó từng được chọn bằng cách xem kết quả 2025 thì holdout_2025 không còn là kiểm tra độc lập.
"""
import argparse, hashlib, json, os, platform, sys, time, warnings
import pandas as pd
warnings.filterwarnings("ignore")
HERE = os.path.dirname(os.path.abspath(__file__)); sys.path.insert(0, os.path.dirname(HERE))
from app.services import hybrid_forecast_holidays as hf
ap = argparse.ArgumentParser()
ap.add_argument("--folder", required=True); ap.add_argument("--out", required=True); ap.add_argument("--inbound", default=None, help="file nhập kho có cột category để hiện tên danh mục")
ap.add_argument("--eval-year", type=int, default=2025); ap.add_argument("--first-origin", default=None); ap.add_argument("--variants", default="A,B,C")
ap.add_argument("--exclude-months", default="2023-01")
a = ap.parse_args(); os.makedirs(a.out, exist_ok=True); T0 = time.time()
EY = a.eval_year; END = pd.Timestamp(f"{EY+1}-01-01"); first = pd.Timestamp(a.first_origin or f"{EY-1}-06-01")
pr = pd.read_csv(os.path.join(a.folder, "noai_raw.csv"), encoding="utf-8-sig").set_index("id")
ob = pd.read_csv(os.path.join(a.folder, "stock_outbound_items_export.csv"), encoding="utf-8-sig")
ob["created"] = pd.to_datetime(ob["created"], format="mixed", dayfirst=True); ob = ob[ob.created < END]; ob["ds"] = ob.created.dt.to_period("M").dt.to_timestamp()
long = ob.groupby(["product_id", "ds"]).quantity.sum().reset_index().rename(columns={"quantity": "y"})
M = hf.build_matrix(long, END, hf.parse_months(a.exclude_months)); tet = hf.parse_months(",".join(hf.DEFAULT_TET_MONTHS))
PB = {"total": ("noel", "sale_1111_bf"), "cat:1": ("trung_thu", "noel"), "cat:2": ("sale_1111_bf", "noel"), "cat:3": ("noel", "thieu_nhi", "trung_thu"),
      "cat:4": ("trung_thu",), "cat:5": ("phu_nu",), "cat:6": ("phu_nu", "sale_1111_bf"), "cat:7": ("noel", "sale_1111_bf"), "cat:8": ("khai_giang", "nha_giao")}
PC = {k: ("tet_window",) + v for k, v in PB.items()}
CFG = {"A": (True, None), "B": (True, PB), "C": (False, PC)}          # (use_tet theo tháng, plan ngày lễ)
n_or = len(M.columns) - M.columns.get_loc(first); res = {}
for v in a.variants.split(","):
    use_tet, plan = CFG[v]
    bt = hf.walk_forward(M, pr["category_id"], first, n_or, tet, use_tet, {}, lambda i, n, v=v: print(f"  {v} Prophet {i}/{n}  {time.time()-T0:.0f}s", flush=True), plan)
    bt["category_id"] = bt.product_id.map(pr.category_id); bt.to_csv(os.path.join(a.out, f"bt_{v}.csv"), index=False); res[v] = bt; print("XONG", v, f"{time.time()-T0:.0f}s")
names = {}
if a.inbound:
    ib = pd.read_csv(a.inbound, encoding="utf-8-sig", usecols=["product_id", "category"]).drop_duplicates("product_id")
    names = ib.assign(cid=ib.product_id.map(pr.category_id)).dropna(subset=["cid"]).drop_duplicates("cid").set_index("cid").category.to_dict()
def wape(bt, lo, hi, cid=None):
    d = bt[(bt.h == 1)].copy(); d["target"] = d.origin       # h=1: tháng mục tiêu = tháng mốc
    d = d[(d.target >= lo) & (d.target <= hi)].dropna(subset=["forecast", "actual"])
    if cid is not None: d = d[d.category_id == cid]
    return round(100 * (d.forecast - d.actual).abs().sum() / d.actual.sum(), 2) if len(d) and d.actual.sum() > 0 else float("nan")
periods = {"chon_2024H2": (pd.Timestamp("2024-07-01"), pd.Timestamp("2024-12-01")), "holdout_2025": (pd.Timestamp(f"{EY}-01-01"), pd.Timestamp(f"{EY}-12-01"))}
rows = []
for p, (lo, hi) in periods.items():
    for cid in [None] + sorted(pr.category_id.unique()):
        r = {"period": p, "category": "ALL" if cid is None else names.get(cid, f"cat:{cid}")}
        for v, bt in res.items(): r[v] = wape(bt, lo, hi, cid)
        rows.append(r)
pd.DataFrame(rows).to_csv(os.path.join(a.out, "holiday_ablation_wape.csv"), index=False, encoding="utf-8-sig")
sha = lambda p: hashlib.sha256(open(p, "rb").read()).hexdigest()
json.dump(dict(finished=time.strftime("%Y-%m-%d %H:%M:%S"), runtime_sec=round(time.time() - T0), model_version=hf.MODEL_VERSION, hybrid_forecast_holidays_sha256=sha(hf.__file__),
               script_sha256=sha(os.path.abspath(__file__)), args=vars(a), inputs={n: sha(os.path.join(a.folder, n)) for n in ("noai_raw.csv", "stock_outbound_items_export.csv")},
               libs=dict(python=platform.python_version(), pandas=pd.__version__, prophet=__import__("prophet").__version__, sklearn=__import__("sklearn").__version__)),
          open(os.path.join(a.out, "holiday_run_manifest.json"), "w", encoding="utf-8"), ensure_ascii=False, indent=2)
print(pd.DataFrame(rows).to_string(index=False)); print("XONG ->", a.out)
