#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
BƯỚC 3 — TÍNH các trường dẫn xuất từ dữ liệu thô (không chạy mô hình, không mô phỏng).
Đọc (trong --folder): noai_raw.csv, stock_inbound_items_export.csv, stock_outbound_items_export.csv, ai_raw.csv
Ghi (trong --out):
  derived_inputs_monthly.csv      1 dòng / sản phẩm / tháng (đầu vào của bước 4)
  derived_forecast_accuracy.csv   sai số dự báo theo sản phẩm và tổng, cho 3 phương pháp: noai / mean / ai

Định nghĩa từng trường dẫn xuất (tất cả tính trong file này):
  actual_units           tổng xuất kho thực tế trong tháng (cộng quantity của stock_outbound_items)
  days_in_month          số ngày của tháng
  noai_demand_per_day    TB tháng (chỉ tháng có phát sinh xuất, TRƯỚC năm đánh giá) / 30      — đúng assessment_notAI.py
  noai_safety_stock      int(min_stock x 20%)                                                  — đúng assessment_notAI.py
  mean_forecast_units    TB các tháng hợp lệ TRƯỚC tháng đó (ước lượng đơn giản, nhánh đối chứng)
  ai_forecast_units      predicted_quantity (horizon 1) của ai_raw; nếu mô hình không dự báo được thì dùng mean_forecast_units (ai_fallback=1)
  sigma_err_mean/_ai     RMSE sai số dự báo (đơn vị/tháng) của các tháng TRƯỚC tháng đó; cần >= 3 tháng, nếu không để trống
  sigma_daily, freq_ratio  độ lệch chuẩn xuất kho theo ngày, tỉ lệ ngày có xuất (từ ngày xuất đầu tiên đến hết tháng trước)
  cycle_days_natural     trung vị khoảng cách giữa các ngày nhập (chỉ dữ liệu nhập TRƯỚC tháng đó); <2 lần nhập → = lead_time_days
"""
import argparse, os, sys
import numpy as np, pandas as pd
HERE = os.path.dirname(os.path.abspath(__file__)); sys.path.insert(0, os.path.dirname(HERE))
from app.services import hybrid_forecast as hf          # chỉ dùng hàm dựng ma trận tháng build_matrix/parse_months của dự án
ap = argparse.ArgumentParser()
ap.add_argument("--folder", required=True); ap.add_argument("--out", required=True)
ap.add_argument("--eval-year", type=int, default=2025); ap.add_argument("--exclude-months", default="2023-01")
a = ap.parse_args(); os.makedirs(a.out, exist_ok=True)
EY = a.eval_year; EV = pd.Timestamp(f"{EY}-01-01"); END = pd.Timestamp(f"{EY+1}-01-01")
rd = lambda n: pd.read_csv(os.path.join(a.folder, n), encoding="utf-8-sig")
pr = rd("noai_raw.csv").set_index("id"); ib = rd("stock_inbound_items_export.csv"); ob = rd("stock_outbound_items_export.csv"); ai = rd("ai_raw.csv")
for x in (ib, ob): x["created"] = pd.to_datetime(x["created"], format="mixed", dayfirst=True)
ib = ib[ib.created < END]; ob = ob[ob.created < END]; ob["ds"] = ob.created.dt.to_period("M").dt.to_timestamp()
long = ob.groupby(["product_id", "ds"]).quantity.sum().reset_index().rename(columns={"quantity": "y"})
M = hf.build_matrix(long, END, hf.parse_months(a.exclude_months))
ai["period_start"] = pd.to_datetime(ai.period_start); ai["created"] = pd.to_datetime(ai.created)
first_origin = ai.created.min(); START = first_origin + pd.DateOffset(months=1)
F_ai = ai[ai.period_start == ai.created].pivot(index="product_id", columns="period_start", values="predicted_quantity")   # horizon 1
months = pd.date_range(START, END - pd.DateOffset(months=1), freq="MS")
F_mean = pd.DataFrame({m: M.loc[:, M.columns < m].mean(axis=1) for m in M.columns if m >= first_origin}, index=M.index)
days = pd.date_range(M.columns[0], END - pd.Timedelta(days=1), freq="D"); di = {d: i for i, d in enumerate(days)}
dem = {}
for pid, s in ob.groupby(["product_id", ob.created.dt.normalize()]).quantity.sum().groupby(level=0):
    v = np.zeros(len(days)); v[[di[d] for d in s.index.get_level_values(1)]] = s.values; dem[pid] = v
inb = {pid: sorted(set(x.created.dt.normalize())) for pid, x in ib.groupby("product_id")}
tr = ob[ob.created < EV].groupby(["product_id", "ds"]).quantity.sum().groupby(level=0).mean()
def g(F, pid, m): return F.at[pid, m] if (pid in F.index and m in F.columns) else np.nan
def err_sigma(F, pid, m):
    es = [g(F, pid, k) - M.at[pid, k] for k in pd.date_range(first_origin, m - pd.DateOffset(months=1), freq="MS")
          if np.isfinite(g(F, pid, k)) and np.isfinite(M.at[pid, k])]
    return float(np.sqrt(np.mean(np.square(es)))) if len(es) >= 3 else np.nan
def daily_stats(pid, m):
    v = dem.get(pid); nz = np.nonzero(v)[0] if v is not None else []
    if len(nz) == 0: return 0.0, 1.0
    seg = v[nz[0]:di[m]]
    return (float(seg.std(ddof=1)), float((seg > 0).mean())) if len(seg) >= 2 else (0.0, 1.0)
def cycle(pid, m, LT):
    ds = [d for d in inb.get(pid, []) if d < m]
    if len(ds) < 2: return float(LT)
    gp = sorted((ds[i + 1] - ds[i]).days for i in range(len(ds) - 1)); n = len(gp)
    return float(gp[n // 2] if n % 2 else (gp[n // 2 - 1] + gp[n // 2]) / 2)
rows = []
for pid in [p for p in pr.index if p in M.index]:
    LT = int(pr.at[pid, "lead_time_days"])
    for m in months:
        fm = g(F_mean, pid, m); fm = fm if np.isfinite(fm) else 0.0
        fa = g(F_ai, pid, m); fb = int(not np.isfinite(fa)); fa = fm if fb else fa
        sd, fr = daily_stats(pid, m)
        rows.append(dict(product_id=pid, month=m.date(), days_in_month=m.days_in_month, lead_time_days=LT,
            actual_units=(M.at[pid, m] if np.isfinite(M.at[pid, m]) else 0.0),
            noai_demand_per_day=tr.get(pid, 0.0) / 30, noai_safety_stock=int(pr.at[pid, "min_stock"] * 0.2),
            mean_forecast_units=fm, ai_forecast_units=fa, ai_fallback=fb,
            sigma_err_mean=err_sigma(F_mean, pid, m), sigma_err_ai=err_sigma(F_ai, pid, m),
            sigma_daily=sd, freq_ratio=fr, cycle_days_natural=cycle(pid, m, LT)))
D = pd.DataFrame(rows); D.to_csv(os.path.join(a.out, "derived_inputs_monthly.csv"), index=False)
# ---- sai số dự báo (năm đánh giá) ----
E = D[pd.to_datetime(D.month).dt.year == EY].copy()
E["forecast_noai"] = E.noai_demand_per_day * E.days_in_month; E["forecast_mean"] = E.mean_forecast_units; E["forecast_ai"] = E.ai_forecast_units
rows = []
for sc in ("noai", "mean", "ai"):
    E["abs_err"] = (E["forecast_" + sc] - E.actual_units).abs()
    pp = E.groupby("product_id").agg(actual_units=("actual_units", "sum"), forecast_units=("forecast_" + sc, "sum"), abs_error_units=("abs_err", "sum"))
    pp["scenario"] = sc; rows.append(pp.reset_index())
    tot = pp[["actual_units", "forecast_units", "abs_error_units"]].sum(); rows.append(pd.DataFrame([dict(product_id="ALL", scenario=sc, **tot)]))
A = pd.concat(rows, ignore_index=True)
A["wape_pct"] = 100 * A.abs_error_units / A.actual_units.replace(0, np.nan); A["bias_pct"] = 100 * (A.forecast_units - A.actual_units) / A.actual_units.replace(0, np.nan)
A[["scenario", "product_id", "actual_units", "forecast_units", "abs_error_units", "wape_pct", "bias_pct"]].round(3).to_csv(os.path.join(a.out, "derived_forecast_accuracy.csv"), index=False)
print(A[A.product_id == "ALL"][["scenario", "actual_units", "forecast_units", "wape_pct", "bias_pct"]].round(2).to_string(index=False))
print(f"XONG: {len(D)} dòng derived_inputs_monthly, tháng {months[0].date()}..{months[-1].date()}")
