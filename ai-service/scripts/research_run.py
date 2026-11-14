#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
research_run.py — chạy THẬT mô hình hybrid của dự án (app/services/hybrid_forecast.py, KHÔNG sửa)
trên 4 file CSV của bạn, rồi mô phỏng chính sách tồn kho No-AI vs AI và xuất toàn bộ kết quả.

Chạy từ thư mục ai-service (cần prophet, scikit-learn, pandas, numpy đã cài như khi chạy hybrid):
    python scripts/research_run.py --folder C:\\path\\to\\csv --out C:\\path\\to\\research_out

Đầu vào (trong --folder): products.csv, suppliers.csv, stock_inbound_items_export.csv, stock_outbound_items_export.csv
Đầu ra  (trong --out):
    01_backtest_forecasts.csv    dự báo walk-forward của hybrid + baseline, từng (mốc, horizon, sản phẩm)
    02_accuracy_summary.csv      WAPE/bias theo horizon và theo mô hình
    03_thresholds_monthly.csv    ngưỡng ROP/Max từng sản phẩm-tháng của từng kịch bản (Z chính, chu kỳ nhập thật)
    04_replay_by_product.csv     kết quả mô phỏng từng sản phẩm x kịch bản x Z x chu kỳ nhập
    05_replay_summary.csv        tổng hợp từ 04 (cùng công thức với file Excel)
    06_run_manifest.json         bằng chứng chạy thật: hash code + dữ liệu, phiên bản thư viện, tham số, thời gian
"""
import argparse, hashlib, json, os, platform, subprocess, sys, time, warnings
import numpy as np, pandas as pd
warnings.filterwarnings("ignore")

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.dirname(HERE))                 # = thư mục ai-service
from app.services import hybrid_forecast as hf            # mô hình CỦA BẠN, dùng nguyên bản

ap = argparse.ArgumentParser()
ap.add_argument("--folder", required=True); ap.add_argument("--out", required=True)
ap.add_argument("--eval-year", type=int, default=2025)
ap.add_argument("--first-origin", default=None, help="mốc dự báo đầu tiên (mặc định: tháng 6 năm trước eval-year)")
ap.add_argument("--exclude-months", default="2023-01", help="tháng đầu data bị thiếu/ramp-up (NaN=không dùng)")
ap.add_argument("--tet-months", default=",".join(hf.DEFAULT_TET_MONTHS))
ap.add_argument("--z-list", default="0,0.84,1.28,1.65,2.05,2.33"); ap.add_argument("--z-main", type=float, default=1.65)
ap.add_argument("--cycles", default="7,14,30", help="chu kỳ nhập (ngày) cần thử thêm, ngoài chu kỳ nhập THẬT của kho")
a = ap.parse_args()
os.makedirs(a.out, exist_ok=True); T0 = time.time()
Z = [float(x) for x in a.z_list.split(",")]; CYCLES = [float(x) for x in a.cycles.split(",") if x]
EY = a.eval_year; first_origin = pd.Timestamp(a.first_origin or f"{EY-1}-06-01")
END = pd.Timestamp(f"{EY+1}-01-01"); EV = pd.Timestamp(f"{EY}-01-01"); START = first_origin + pd.DateOffset(months=1)

# ---------------- đọc dữ liệu ----------------
def rd(n): return pd.read_csv(os.path.join(a.folder, n), encoding="utf-8-sig")
pr = rd("products.csv").set_index("id"); su = rd("suppliers.csv").set_index("id")
ib = rd("stock_inbound_items_export.csv"); ob = rd("stock_outbound_items_export.csv")
for x in (ib, ob): x["created"] = pd.to_datetime(x["created"], format="mixed", dayfirst=True)
ib = ib[ib.created < END]; ob = ob[ob.created < END]
ob["ds"] = ob.created.dt.to_period("M").dt.to_timestamp()
long = ob.groupby(["product_id", "ds"]).quantity.sum().reset_index().rename(columns={"quantity": "y"})
excl = hf.parse_months(a.exclude_months); tet = hf.parse_months(a.tet_months)
M = hf.build_matrix(long, END, excl); cat = pr["category_id"]
print(f"[1/5] Ma trận {M.shape[0]} SP x {M.shape[1]} tháng ({M.columns[0].date()}..{M.columns[-1].date()})", flush=True)

# ---------------- 1) walk-forward của hybrid ----------------
n_or = len(M.columns) - M.columns.get_loc(first_origin)
cache = {}
bt = hf.walk_forward(M, cat, first_origin, n_or, tet, True, cache,
                     lambda i, n: print(f"      Prophet {i}/{n}  {time.time()-T0:.0f}s", flush=True))
mons = list(M.columns); rows = []
for (o, h), g in bt.groupby(["origin", "h"]):
    i = mons.index(o); tm = i - 1 + h
    tot = M.iloc[:, :i].sum(axis=0, min_count=1); gi = hf.seasonal_index(tot)
    lvl = M.iloc[:, i - 12:i].mean(axis=1) if i >= 12 else pd.Series(np.nan, index=M.index)
    d = g.set_index("product_id")
    d["trailing12"] = lvl.reindex(d.index)
    d["seasonal_level"] = d.trailing12 * (gi[mons[tm].month] if tm < len(mons) else np.nan)
    d["target_month"] = mons[tm] if tm < len(mons) else pd.NaT
    rows.append(d.reset_index())
bt = pd.concat(rows, ignore_index=True)
bt["model_version"] = hf.MODEL_VERSION
bt[["origin", "target_month", "h", "product_id", "forecast", "actual", "mean_baseline", "trailing12", "seasonal_level", "model_version"]] \
    .to_csv(os.path.join(a.out, "01_backtest_forecasts.csv"), index=False)
acc = []
full = bt.dropna(subset=["actual", "forecast", "mean_baseline", "trailing12", "seasonal_level"])
for scope, d0 in (("eval_year", full[full.origin >= EV]), ("all_origins", full)):
    for h, g in d0.groupby("h"):
        s = g.actual.sum()
        for mname in ("forecast", "mean_baseline", "trailing12", "seasonal_level"):
            acc.append(dict(scope=scope, h=h, model=mname, n=len(g), WAPE_pct=100 * (g[mname] - g.actual).abs().sum() / s,
                            bias_pct=100 * (g[mname].sum() - s) / s, RMSE=float(np.sqrt(((g[mname] - g.actual) ** 2).mean()))))
pd.DataFrame(acc).round(3).to_csv(os.path.join(a.out, "02_accuracy_summary.csv"), index=False)
print(f"[2/5] Walk-forward xong ({len(bt)} dòng)  {time.time()-T0:.0f}s", flush=True)

# ---------------- 2) tham số theo (sản phẩm, tháng) ----------------
b1 = bt[bt.h == 1]
F_h = b1.pivot(index="product_id", columns="origin", values="forecast")
F_m = b1.pivot(index="product_id", columns="origin", values="mean_baseline")
months = pd.date_range(START, END - pd.DateOffset(months=1), freq="MS"); nm = len(months)
days = pd.date_range(M.columns[0], END - pd.Timedelta(days=1), freq="D"); di = {d: i for i, d in enumerate(days)}
dem = {}
for pid, s in ob.groupby(["product_id", ob.created.dt.normalize()]).quantity.sum().groupby(level=0):
    v = np.zeros(len(days)); v[[di[d] for d in s.index.get_level_values(1)]] = s.values; dem[pid] = v
inb = {pid: sorted(set(x.created.dt.normalize())) for pid, x in ib.groupby("product_id")}

def err_sigma(pid, F, m):
    es = []
    for k in pd.date_range(first_origin, m - pd.DateOffset(months=1), freq="MS"):
        f = F.at[pid, k] if (pid in F.index and k in F.columns) else np.nan
        ac = M.at[pid, k] if (pid in M.index and k in M.columns) else np.nan
        if np.isfinite(f) and np.isfinite(ac): es.append(f - ac)
    return float(np.sqrt(np.mean(np.square(es)))) if len(es) >= 3 else np.nan
def daily_stats(pid, m):
    v = dem.get(pid); nz = np.nonzero(v)[0] if v is not None else []
    if len(nz) == 0: return 0.0, 1.0
    seg = v[nz[0]:di[m]]
    return (float(seg.std(ddof=1)), float((seg > 0).mean())) if len(seg) >= 2 else (0.0, 1.0)
def cycle(pid, m, LT):
    ds = [d for d in inb.get(pid, []) if d < m]
    if len(ds) < 2: return float(LT)
    g = sorted((ds[i + 1] - ds[i]).days for i in range(len(ds) - 1)); n = len(g)
    return float(g[n // 2] if n % 2 else (g[n // 2 - 1] + g[n // 2]) / 2)

PIDS = [p for p in pr.index if p in M.index]
P = {}
for pid in PIDS:
    LT = int(su.at[pr.at[pid, "supplier_id"], "lead_time_days"]); dim = np.array([m.days_in_month for m in months], float)
    am = np.array([M.at[pid, m] if np.isfinite(M.at[pid, m]) else 0.0 for m in months])
    fm = np.array([F_m.at[pid, m] if (pid in F_m.index and m in F_m.columns and np.isfinite(F_m.at[pid, m])) else 0.0 for m in months])
    fh = np.array([F_h.at[pid, m] if (pid in F_h.index and m in F_h.columns and np.isfinite(F_h.at[pid, m])) else np.nan for m in months])
    fh = np.where(np.isfinite(fh), fh, fm)                       # hybrid không dự báo được → dùng trung bình
    sd, fr = zip(*[daily_stats(pid, m) for m in months])
    P[pid] = dict(LT=LT, dim=dim, d={"mean": fm / dim, "hybrid": fh / dim, "oracle": am / dim},
                  se={"mean": np.array([err_sigma(pid, F_m, m) for m in months]), "hybrid": np.array([err_sigma(pid, F_h, m) for m in months]),
                      "oracle": np.zeros(nm)},
                  sd=np.array(sd), fr=np.array(fr), cyc=np.array([cycle(pid, m, LT) for m in months]))
tr = ob[ob.created < EV].groupby(["product_id", "ds"]).quantity.sum().groupby(level=0).mean()   # đúng assessment_notAI.py
print(f"[3/5] Tham số xong  {time.time()-T0:.0f}s", flush=True)

# ---------------- 3) mô phỏng ----------------
ND = (END - START).days; E0 = (EV - START).days
mi = np.array([(d.year - START.year) * 12 + d.month - START.month for d in pd.date_range(START, periods=ND, freq="D")])
def monthly_thr(pid, scen, z, C):
    p = P[pid]; LT = p["LT"]
    if scen == "noai":
        dd = tr.get(pid, 0.0) / 30; r = np.full(nm, dd * LT + int(pr.at[pid, "min_stock"] * 0.2)); return r, np.full(nm, float(pr.at[pid, "max_stock"])), np.full(nm, dd), np.full(nm, int(pr.at[pid, "min_stock"] * 0.2), float)
    cyc = p["cyc"] if C is None else np.full(nm, C)
    if scen == "hybrid_repo":                                     # đúng công thức assessment_AI_hybrid: σ bán theo ngày × hệ số tần suất
        dd = p["d"]["hybrid"]; ss = z * p["sd"] * np.sqrt(LT) * np.minimum(1 + (1 - p["fr"]), 2.0)
    else:                                                         # đề xuất: σ = sai số dự báo của chính mô hình
        dd = p["d"][scen]; se = p["se"][scen]; se = np.where(np.isfinite(se), se, p["sd"] * np.sqrt(p["dim"]))
        ss = z * se * np.sqrt(LT / 30.0)
    r = dd * LT + ss; return r, r + dd * cyc, dd, ss
def sim(pid, rop, mx):
    v = dem.get(pid, np.zeros(len(days))); dm = v[di[START]:di[START] + ND]; LT = P[pid]["LT"]
    stock = mx[0]; arr = np.zeros(ND + LT + 2); onord = 0.0; ful = lost = stk = oq = 0.0; sod = orders = 0
    for i in range(ND):
        stock += arr[i]; onord -= arr[i]; x = dm[i]; s = min(stock, x); stock -= s; l = x - s
        ip = stock + onord
        if ip <= rop[i]:
            q = np.ceil(mx[i] - ip)
            if q > 0:
                arr[i + LT] += q; onord += q
                if i >= E0: orders += 1; oq += q
        if i >= E0:
            ful += s; lost += l; stk += stock; sod += l > 0
    return ful, lost, sod, stk / (ND - E0), orders, oq

jobs = [("noai", 0.0, "none")] + [("oracle", 0.0, "natural")] + [(s, z, "natural") for s in ("mean", "hybrid", "hybrid_repo") for z in Z] \
     + [(s, z, c) for s in ("mean", "hybrid") for c in CYCLES for z in Z] + [("oracle", 0.0, c) for c in CYCLES]
out, thr = [], []
for k, (scen, z, cyc) in enumerate(jobs):
    C = None if cyc in ("natural", "none") else float(cyc)
    for pid in PIDS:
        r_m, x_m, d_m, s_m = monthly_thr(pid, scen, z, C)
        f, l, sod, avg, no, oq = sim(pid, r_m[mi], x_m[mi])
        out.append(dict(scenario=scen, z=z, cycle=cyc, product_id=pid, price=pr.at[pid, "price"], demand=f + l, fulfilled=f, lost=l,
                        stockout_days=int(sod), avg_stock=avg, orders=no, ordered_qty=oq))
        if scen in ("noai", "mean", "hybrid", "hybrid_repo") and cyc in ("natural", "none") and (scen == "noai" or abs(z - a.z_main) < 1e-9):
            for j, m in enumerate(months):
                thr.append(dict(scenario=scen, z=z if scen != "noai" else None, product_id=pid, month=m.date(), lead_time_days=P[pid]["LT"],
                                demand_per_day=d_m[j], safety_stock=s_m[j], rop=r_m[j], max_level=x_m[j]))
    print(f"[4/5] {k+1}/{len(jobs)} {scen} z={z} cycle={cyc}  {time.time()-T0:.0f}s", flush=True)
R = pd.DataFrame(out); R.to_csv(os.path.join(a.out, "04_replay_by_product.csv"), index=False)
pd.DataFrame(thr).to_csv(os.path.join(a.out, "03_thresholds_monthly.csv"), index=False)
R["lost_val"] = R.lost * R.price; R["dem_val"] = R.demand * R.price; R["stk_val"] = R.avg_stock * R.price
S = R.groupby(["scenario", "z", "cycle"]).apply(lambda x: pd.Series({
    "n_products": len(x), "fill_units_pct": 100 * x.fulfilled.sum() / x.demand.sum(), "fill_value_pct": 100 * (1 - x.lost_val.sum() / x.dem_val.sum()),
    "avg_stock_units": x.avg_stock.sum(), "avg_stock_value": x.stk_val.sum(), "stockout_day_pct": 100 * x.stockout_days.sum() / (len(x) * 365),
    "orders_per_product": x.orders.mean()})).reset_index()
S.round(4).to_csv(os.path.join(a.out, "05_replay_summary.csv"), index=False)

# ---------------- 4) manifest ----------------
def sha(path): return hashlib.sha256(open(path, "rb").read()).hexdigest()
def ver(m):
    try: return __import__(m).__version__
    except Exception: return None
try: commit = subprocess.check_output(["git", "-C", os.path.dirname(HERE), "rev-parse", "HEAD"], stderr=subprocess.DEVNULL).decode().strip()
except Exception: commit = None
man = dict(finished=time.strftime("%Y-%m-%d %H:%M:%S"), runtime_sec=round(time.time() - T0), git_commit=commit,
           model_version=hf.MODEL_VERSION, hybrid_forecast_sha256=sha(hf.__file__), research_run_sha256=sha(os.path.abspath(__file__)),
           constants=dict(HORIZONS=list(hf.HORIZONS), WINDOW=hf.WINDOW, MIN_TRAIN_ROWS=hf.MIN_TRAIN_ROWS),
           args=vars(a), data_signature=hf.data_signature(M, extra=a.tet_months),
           inputs={n: dict(sha256=sha(os.path.join(a.folder, n)), rows=len(rd(n))) for n in ("products.csv", "suppliers.csv", "stock_inbound_items_export.csv", "stock_outbound_items_export.csv")},
           libs=dict(python=platform.python_version(), pandas=ver("pandas"), numpy=ver("numpy"), prophet=ver("prophet"), sklearn=ver("sklearn"), cmdstanpy=ver("cmdstanpy")),
           platform=platform.platform(), n_products=len(PIDS), months=[str(M.columns[0].date()), str(M.columns[-1].date())],
           sim=dict(start=str(START.date()), eval_from=str(EV.date()), eval_to=str((END - pd.Timedelta(days=1)).date()), policy="order-up-to Max khi (tồn+đang về) <= ROP; thiếu hàng = mất doanh số; tồn đầu = Max"))
json.dump(man, open(os.path.join(a.out, "06_run_manifest.json"), "w", encoding="utf-8"), ensure_ascii=False, indent=2)
print(f"[5/5] XONG sau {time.time()-T0:.0f}s -> {a.out}", flush=True)
