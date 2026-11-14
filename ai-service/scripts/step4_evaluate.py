#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
BƯỚC 4 (v2) — mô phỏng chính sách tồn kho No-AI / mean / AI bằng nhu cầu thật.
Khác bản cũ:
  1. KHÔNG dùng giá (price): mọi chỉ số tính theo đơn vị, ngày tồn kho, tỉ lệ ngày thiếu hàng, số đơn đặt.
  2. FEFO thật cho sản phẩm có hạn dùng (lô sinh ra khi hàng về, hết hạn thì bị loại và tính là hao hụt, xuất lô hạn sớm nhất trước).
  3. Lead time THỰC có thể trễ hơn lead time kế hoạch (chính sách vẫn dùng lead time kế hoạch).
  4. Thiếu hàng: 'lost' (mất doanh số, như bản cũ) hoặc 'backorder' (giao bù khi hàng về).
  5. Chi phí đặt hàng: không có dữ liệu -> xuất số đơn và điểm hoà vốn K/h (đơn vị: đơn vị-ngày tồn kho trên mỗi đơn),
     không bịa số tiền. Nếu truyền --order-cost K --hold-per-unit-day h thì tính thêm tổng chi phí.
Đọc : noai_raw.csv, stock_outbound_items_export.csv (--folder), derived_inputs_monthly.csv (--derived),
      file nhập kho CÓ cột mfg_date, exp_date (--inbound)
Ghi : eval_by_product.csv, eval_summary.csv, eval_ci.csv, eval_breakeven.csv (--out)
Giả định (tham số, KHÔNG ước lượng từ dữ liệu vì thiếu dữ liệu):
  lt_mild   : 20% đơn trễ thêm 1..50% lead time;  lt_severe: 40% đơn trễ thêm 1..100% lead time.
  Ngày sản xuất = ngày nhập - OFF ngày (OFF lấy trung vị từ dữ liệu), hạn dùng theo từng sản phẩm lấy từ dữ liệu.
"""
import argparse, bisect, os
import numpy as np, pandas as pd
ap = argparse.ArgumentParser()
ap.add_argument("--folder", required=True); ap.add_argument("--derived", required=True); ap.add_argument("--inbound", required=True); ap.add_argument("--out", required=True)
ap.add_argument("--eval-year", type=int, default=2025); ap.add_argument("--z-list", default="0,0.84,1.28,1.65,2.05,2.33")
ap.add_argument("--z-main", type=float, default=1.65); ap.add_argument("--cycles", default="7,14,30"); ap.add_argument("--boot", type=int, default=2000)
ap.add_argument("--assumptions", default="base,backorder,lt_mild,lt_severe"); ap.add_argument("--legacy", action="store_true", help="tắt FEFO để đối chiếu với bản cũ")
ap.add_argument("--order-cost", type=float, default=None); ap.add_argument("--hold-per-unit-day", type=float, default=None); ap.add_argument("--seed", type=int, default=1)
a = ap.parse_args(); os.makedirs(a.out, exist_ok=True)
Z = [float(x) for x in a.z_list.split(",")]; CYC = [float(x) for x in a.cycles.split(",") if x]; ASM = a.assumptions.split(",")
EY = a.eval_year; EV = pd.Timestamp(f"{EY}-01-01"); END = pd.Timestamp(f"{EY+1}-01-01")
pr = pd.read_csv(os.path.join(a.folder, "noai_raw.csv"), encoding="utf-8-sig").set_index("id")
ob = pd.read_csv(os.path.join(a.folder, "stock_outbound_items_export.csv"), encoding="utf-8-sig")
ob["created"] = pd.to_datetime(ob["created"], format="mixed", dayfirst=True); ob = ob[ob.created < END]
D = pd.read_csv(os.path.join(a.derived, "derived_inputs_monthly.csv")); D["month"] = pd.to_datetime(D.month)
# ---- hạn dùng theo sản phẩm (từ dữ liệu nhập) ----
ib = pd.read_csv(a.inbound, encoding="utf-8-sig"); ib["created"] = pd.to_datetime(ib["created"], format="mixed", dayfirst=True)
ib = ib[ib.exp_date.notna()]; ib["mfg"] = pd.to_datetime(ib.mfg_date); ib["exp"] = pd.to_datetime(ib.exp_date)
SHELF = {} if a.legacy else ib.assign(sh=(ib.exp - ib.mfg).dt.days).groupby("product_id").sh.median().astype(int).to_dict()
OFF = int(((ib.created.dt.normalize() - ib.mfg).dt.days).median()) if len(ib) else 0
months = sorted(D.month.unique()); START = pd.Timestamp(months[0]); nm = len(months); ND = (END - START).days; E0 = (EV - START).days
days = pd.date_range(START, END - pd.Timedelta(days=1), freq="D")
mi = np.array([(d.year - START.year) * 12 + d.month - START.month for d in days])
dem = {}
for pid, s in ob[ob.created >= START].groupby(["product_id", ob.created.dt.normalize()]).quantity.sum().groupby(level=0):
    v = np.zeros(ND); v[(s.index.get_level_values(1) - START).days] = s.values; dem[pid] = v
P = {pid: g.sort_values("month") for pid, g in D.groupby("product_id")}
def thr(pid, scen, z, C):
    g = P[pid]; LT = int(g.lead_time_days.iloc[0]); dim = g.days_in_month.values.astype(float)
    if scen == "noai":
        return g.noai_demand_per_day.values * LT + g.noai_safety_stock.values, np.full(nm, float(pr.at[pid, "max_stock"])), LT
    cyc = g.cycle_days_natural.values if C is None else np.full(nm, C)
    if scen == "ai_repo_sigma":
        d = g.ai_forecast_units.values / dim; ss = z * g.sigma_daily.values * np.sqrt(LT) * np.minimum(1 + (1 - g.freq_ratio.values), 2.0)
    else:
        d = (g.mean_forecast_units if scen == "mean" else g.ai_forecast_units).values / dim
        se = (g.sigma_err_mean if scen == "mean" else g.sigma_err_ai).values; se = np.where(np.isfinite(se), se, g.sigma_daily.values * np.sqrt(dim))
        ss = z * se * np.sqrt(LT / 30.0)
    r = d * LT + ss; return r, r + d * cyc, LT
ASM_PAR = {"base": (False, 0, 0), "backorder": (True, 0, 0), "lt_mild": (False, .2, .5), "lt_severe": (False, .4, 1.0)}   # (backorder, p_trễ, tỉ lệ trễ tối đa)
def delays(pid, LT, p, frac):
    if p == 0: return np.zeros(ND, dtype=int)
    rng = np.random.default_rng([a.seed, int(pid)]); mx = max(1, int(np.ceil(frac * LT)))
    return np.where(rng.random(ND) < p, rng.integers(1, mx + 1, ND), 0)        # cùng dãy trễ cho mọi kịch bản (common random numbers)
def sim(pid, rop, mx, LT, asm):
    bo_mode, p, frac = ASM_PAR[asm]; dl = delays(pid, LT, p, frac); sh = SHELF.get(pid); dm = dem.get(pid, np.zeros(ND))
    arr = np.zeros(ND + LT + int(dl.max()) + 3); onord = 0.0; stock = float(mx[0]); bo = 0.0
    lots = [[-OFF + sh, stock]] if sh else []
    ful = lost = stk = oq = expd = rec = near = late = 0.0; sod = orders = 0
    def take(q):                                           # FEFO: lấy từ lô hạn sớm nhất
        nonlocal stock
        stock -= q
        if sh:
            r = q
            while r > 1e-9 and lots:
                t = min(r, lots[0][1]); lots[0][1] -= t; r -= t
                if lots[0][1] <= 1e-9: lots.pop(0)
    for i in range(ND):
        ai = arr[i]
        if ai > 0:
            stock += ai; onord -= ai
            if sh: bisect.insort(lots, [i - OFF + sh, ai])
            if i >= E0: rec += ai
        if sh:                                             # loại lô quá hạn
            while lots and lots[0][0] < i:
                q_ = lots.pop(0)[1]; stock -= q_
                if i >= E0: expd += q_
        if bo > 0 and stock > 0:
            f = min(stock, bo); take(f); bo -= f
            if i >= E0: late += f
        x = dm[i]; s = min(stock, x); take(s); l = x - s
        if bo_mode: bo += l
        ip = stock + onord - bo
        if ip <= rop[i]:
            q = np.ceil(mx[i] - ip)
            if q > 0:
                arr[i + LT + dl[i]] += q; onord += q
                if i >= E0: orders += 1; oq += q
        if i >= E0:
            ful += s; lost += l; stk += stock; sod += l > 0
            if sh: near += sum(L[1] for L in lots if L[0] - i < 30)
    n = ND - E0
    return ful, lost, int(sod), stk / n, orders, oq, expd, rec, (near / stk if stk > 0 else 0.0), late, bo, int(bool(sh))
jobs = [("noai", 0.0, "none", "base")] + [("ai_repo_sigma", z, "natural", "base") for z in Z] + [(s, z, "natural", "base") for s in ("mean", "ai") for z in Z] \
     + [(s, z, str(c), "base") for s in ("mean", "ai") for c in CYC for z in Z]
for asm in ASM:
    if asm == "base": continue
    jobs += [("noai", 0.0, "none", asm)] + [(s, a.z_main, cy, asm) for s in ("mean", "ai") for cy in ["natural"] + [str(c) for c in CYC]]
cols = ["fulfilled", "lost", "stockout_days", "avg_stock", "orders", "ordered_qty", "expired_units", "received_units", "near_expiry_share", "late_filled", "backlog_end", "has_expiry"]
out = []
for k, (sc, z, cy, asm) in enumerate(jobs):
    C = None if cy in ("natural", "none") else float(cy)
    for pid in P:
        r, mx, LT = thr(pid, sc, z, C); res = sim(pid, r[mi], mx[mi], LT, asm)
        out.append(dict(assumption=asm, scenario=sc, z=z, cycle=cy, product_id=pid, demand=res[0] + res[1], **dict(zip(cols, res))))
    print(f"  {k+1}/{len(jobs)} {asm} {sc} z={z} cycle={cy}", flush=True)
R = pd.DataFrame(out); R["cover_days"] = np.where(R.demand > 0, R.avg_stock / (R.demand / 365), np.nan)
R["fill_prod"] = np.where(R.demand > 0, 100 * R.fulfilled / R.demand, np.nan); R.round(4).to_csv(os.path.join(a.out, "eval_by_product.csv"), index=False)
def summ(x):
    lot = x[x.has_expiry == 1]
    return pd.Series({"n_products": len(x), "fill_units_pct": 100 * x.fulfilled.sum() / x.demand.sum(), "fill_prodmean_pct": x.fill_prod.mean(),
        "median_cover_days": x.cover_days.median(), "avg_stock_units": x.avg_stock.sum(), "stockout_day_pct": 100 * x.stockout_days.sum() / (len(x) * (ND - E0)),
        "orders_per_product": x.orders.mean(), "expired_units": lot.expired_units.sum(),
        "expired_pct_of_received": 100 * lot.expired_units.sum() / lot.received_units.sum() if len(lot) and lot.received_units.sum() > 0 else np.nan,
        "near_expiry_share_pct": 100 * lot.near_expiry_share.mean() if len(lot) else np.nan,
        "late_filled_units": x.late_filled.sum(), "backlog_end_units": x.backlog_end.sum()})
S = R.groupby(["assumption", "scenario", "z", "cycle"]).apply(summ).reset_index()
S["stock_unit_days"] = S.avg_stock_units * (ND - E0)
if a.order_cost is not None and a.hold_per_unit_day is not None:
    S["total_cost"] = a.order_cost * S.orders_per_product * S.n_products + a.hold_per_unit_day * S.stock_unit_days
S.round(4).to_csv(os.path.join(a.out, "eval_summary.csv"), index=False)
# ---- bootstrap theo sản phẩm (delta = AI - đối chứng) ----
rng = np.random.default_rng(1); ci = []
def pair(b, a_, label, lot_only=False):
    ids = b.index.intersection(a_.index); B, A_ = b.loc[ids], a_.loc[ids]; n = len(ids); o = []; names = ["delta_fill_units_pts", "delta_fill_prodmean_pts", "delta_median_cover_pct", "delta_stockout_day_pts"]
    f = lambda t: (100 * (t[1].fulfilled.values.sum() / t[1].demand.values.sum()), np.nanmean(t[1].fill_prod.values), np.nanmedian(t[1].cover_days.values), 100 * t[1].stockout_days.values.sum() / (len(t[1]) * (ND - E0)))
    for _ in range(a.boot):
        s = rng.integers(0, n, n); x, y = f((0, B.iloc[s])), f((0, A_.iloc[s]))
        o.append((y[0] - x[0], y[1] - x[1], 100 * (y[2] / x[2] - 1), y[3] - x[3]))
    o = np.array(o)
    for j, nm_ in enumerate(names): ci.append(dict(comparison=label, metric=nm_, mean=o[:, j].mean(), ci95_low=np.percentile(o[:, j], 2.5), ci95_high=np.percentile(o[:, j], 97.5)))
sel = lambda sc, cy, asm: R[(R.assumption == asm) & (R.scenario == sc) & (np.isclose(R.z, a.z_main)) & (R.cycle == cy)].set_index("product_id")
for cy in ["natural"] + [str(c) for c in CYC]: pair(sel("mean", cy, "base"), sel("ai", cy, "base"), f"ai vs mean | chu kỳ {cy} | z={a.z_main} | base")
for asm in ASM: pair(R[(R.assumption == asm) & (R.scenario == "noai")].set_index("product_id"), sel("ai", "natural", asm), f"ai(natural, z={a.z_main}) vs noai | {asm}")
pd.DataFrame(ci).round(3).to_csv(os.path.join(a.out, "eval_ci.csv"), index=False)
# ---- ngưỡng chi phí đặt hàng K so với chi phí giữ hàng h (1 đơn vị-ngày): chi phí = K x số đơn + h x đơn vị-ngày tồn ----
be = []
def row(x, y, label):
    dsud = y.stock_unit_days - x.stock_unit_days; do = (y.orders_per_product - x.orders_per_product) * y.n_products
    if do < 0 < dsud: thr, rule = dsud / (-do), "AI rẻ hơn nếu K/h > ngưỡng"
    elif dsud < 0 < do: thr, rule = -dsud / do, "AI rẻ hơn nếu K/h < ngưỡng"
    elif do <= 0 and dsud <= 0: thr, rule = np.nan, "AI không đắt hơn ở mọi K, h"
    else: thr, rule = np.nan, "AI không rẻ hơn ở mọi K, h"
    be.append(dict(comparison=label, d_stock_unit_days=dsud, d_orders=do, threshold_K_over_h=thr, rule=rule))
g = lambda sc, cy, asm: S[(S.assumption == asm) & (S.scenario == sc) & ((np.isclose(S.z, a.z_main)) | (sc == "noai")) & (S.cycle == cy)].iloc[0]
for cy in ["natural"] + [str(c) for c in CYC]: row(g("mean", cy, "base"), g("ai", cy, "base"), f"ai vs mean | chu kỳ {cy}")
row(g("noai", "none", "base"), g("ai", "natural", "base"), "ai(natural) vs noai")
pd.DataFrame(be).round(2).to_csv(os.path.join(a.out, "eval_breakeven.csv"), index=False)
show = S[(S.scenario == "noai") | (np.isclose(S.z, a.z_main) & S.cycle.isin(["natural"] + [str(c) for c in CYC]))]
print(show.drop(columns=["z", "stock_unit_days"]).round(2).to_string(index=False)); print("XONG ->", a.out)