#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
BƯỚC 2 — chạy mô hình AI THẬT của dự án (app/services/hybrid_forecast.py, không sửa) và ghi ĐẦU RA CỦA MÔ HÌNH
vào ai_raw.csv, đúng tên cột của bảng `forecasts`. Không tính chỉ số đánh giá nào ở đây.

Chạy từ thư mục ai-service:
    python scripts/step2_run_ai.py --folder C:\\xampp\\htdocs\\smartware\\raw --out C:\\xampp\\htdocs\\smartware\\raw

ai_raw.csv (1 dòng = 1 dự báo của 1 sản phẩm cho 1 tháng, từ 1 mốc dữ liệu):
    product_id, supplier_id, period_start, period_end, predicted_quantity, lower_bound, upper_bound, model_version, created
    created = ngày cắt dữ liệu (chỉ dùng giao dịch TRƯỚC ngày này); lower/upper_bound để trống (hybrid chỉ cho dự báo điểm)
ai_run_manifest.json: bằng chứng chạy thật (hash code + dữ liệu, phiên bản thư viện, tham số).
"""
import argparse, hashlib, json, os, platform, subprocess, sys, time, warnings
import pandas as pd
warnings.filterwarnings("ignore")
HERE = os.path.dirname(os.path.abspath(__file__)); sys.path.insert(0, os.path.dirname(HERE))
from app.services import hybrid_forecast as hf
ap = argparse.ArgumentParser()
ap.add_argument("--folder", required=True); ap.add_argument("--out", required=True)
ap.add_argument("--eval-year", type=int, default=2025); ap.add_argument("--first-origin", default=None)
ap.add_argument("--exclude-months", default="2023-01"); ap.add_argument("--tet-months", default=",".join(hf.DEFAULT_TET_MONTHS))
a = ap.parse_args(); os.makedirs(a.out, exist_ok=True); T0 = time.time()
EY = a.eval_year; END = pd.Timestamp(f"{EY+1}-01-01"); first_origin = pd.Timestamp(a.first_origin or f"{EY-1}-06-01")
pr = pd.read_csv(os.path.join(a.folder, "noai_raw.csv"), encoding="utf-8-sig").set_index("id")
ob = pd.read_csv(os.path.join(a.folder, "stock_outbound_items_export.csv"), encoding="utf-8-sig")
ob["created"] = pd.to_datetime(ob["created"], format="mixed", dayfirst=True); ob = ob[ob.created < END]
ob["ds"] = ob.created.dt.to_period("M").dt.to_timestamp()
long = ob.groupby(["product_id", "ds"]).quantity.sum().reset_index().rename(columns={"quantity": "y"})
M = hf.build_matrix(long, END, hf.parse_months(a.exclude_months)); tet = hf.parse_months(a.tet_months)
n_or = len(M.columns) - M.columns.get_loc(first_origin)
bt = hf.walk_forward(M, pr["category_id"], first_origin, n_or, tet, True, {},
                     lambda i, n: print(f"  Prophet {i}/{n}  {time.time()-T0:.0f}s", flush=True))
mons = list(M.columns); rows = []
for r in bt.itertuples():
    tm = mons.index(r.origin) - 1 + r.h
    if tm >= len(mons) or pd.isna(r.forecast): continue
    ps = mons[tm]
    rows.append(dict(product_id=r.product_id, supplier_id=pr.at[r.product_id, "supplier_id"], period_start=ps.date(),
                     period_end=(ps + pd.offsets.MonthEnd(0)).date(), predicted_quantity=r.forecast, lower_bound="", upper_bound="",
                     model_version=hf.MODEL_VERSION, created=r.origin.date()))
pd.DataFrame(rows).to_csv(os.path.join(a.out, "ai_raw.csv"), index=False)
sha = lambda p: hashlib.sha256(open(p, "rb").read()).hexdigest()
def ver(m):
    try: return __import__(m).__version__
    except Exception: return None
try: commit = subprocess.check_output(["git", "-C", os.path.dirname(HERE), "rev-parse", "HEAD"], stderr=subprocess.DEVNULL).decode().strip()
except Exception: commit = None
json.dump(dict(finished=time.strftime("%Y-%m-%d %H:%M:%S"), runtime_sec=round(time.time() - T0), git_commit=commit, model_version=hf.MODEL_VERSION,
               hybrid_forecast_sha256=sha(hf.__file__), step2_sha256=sha(os.path.abspath(__file__)), args=vars(a), data_signature=hf.data_signature(M, extra=a.tet_months),
               inputs={n: sha(os.path.join(a.folder, n)) for n in ("noai_raw.csv", "stock_outbound_items_export.csv")},
               libs=dict(python=platform.python_version(), pandas=ver("pandas"), numpy=ver("numpy"), prophet=ver("prophet"), sklearn=ver("sklearn")),
               rows=len(rows)), open(os.path.join(a.out, "ai_run_manifest.json"), "w", encoding="utf-8"), ensure_ascii=False, indent=2)
print(f"XONG: {len(rows)} dòng -> {os.path.join(a.out, 'ai_raw.csv')}  ({time.time()-T0:.0f}s)")
