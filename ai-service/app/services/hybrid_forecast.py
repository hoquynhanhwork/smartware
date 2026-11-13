# app/services/hybrid_forecast.py
"""
Hybrid forecaster: Prophet (chuỗi TỔNG + từng CATEGORY) + GBM toàn cục (từng SP).

Phân công vai trò:
  - Prophet fit trên 9 chuỗi tổng hợp (1 tổng + mỗi category 1 chuỗi): chuỗi
    mượt, ít nhiễu, có regressor Tết. Đầu ra = TỶ LỆ
        dự_báo_tháng_đích / trung_bình_12_tháng_gần_nhất
    ("tháng đó nên cao/thấp hơn bình thường bao nhiêu").
  - GBM (HistGradientBoostingRegressor) huấn luyện CHUNG cho mọi SP, mỗi
    horizon h=1,2,3 một model (direct multi-horizon). Nhận tỷ lệ của Prophet
    làm đặc trưng cùng lag, mùa vụ toàn kho, category, mức nhu cầu.
    Dự báo = mức_12_tháng × tỷ_lệ_model_dự_đoán.

File này KHÔNG phụ thuộc DB hay settings — dùng được từ script CSV
(scripts/run_hybrid_csv.py) lẫn từ FastAPI (hybrid_service.py).

Đã đo trên data thật (309 SP, walk-forward 2025, h=1): WAPE ~19.6% so với
~27.2% (trung bình train / No-AI) và ~30.0% (Prophet từng SP). Chưa tinh
chỉnh siêu tham số; mới kiểm tra 1 năm; mùa vụ trong data có phần do người
dùng fill — xem README đi kèm.
"""
from __future__ import annotations

import hashlib
import logging
import warnings
from dataclasses import dataclass, field
from datetime import datetime

import numpy as np
import pandas as pd

logger = logging.getLogger(__name__)

HORIZONS = (1, 2, 3)
MODEL_VERSION = "hybrid-1.0"
WINDOW = 12                     # cửa sổ "mức nhu cầu" (tháng)
MIN_TRAIN_ROWS = 200            # ít hơn → không huấn luyện (data quá ngắn)

# Tháng dương lịch có chứa mùng 1 Tết Nguyên đán. Lấy theo hiểu biết chung,
# CẦN KIỂM TRA LẠI trước khi dùng cho báo cáo. Có thể ghi đè qua settings.
DEFAULT_TET_MONTHS = ("2023-01", "2024-02", "2025-01", "2026-02", "2027-02", "2028-01")


# ================================================================
#  Tiện ích
# ================================================================

def parse_months(value) -> set[pd.Timestamp]:
    """'2023-01, 2024-02' (hoặc list) → {Timestamp('2023-01-01'), ...}."""
    if value is None:
        return set()
    items = value.split(",") if isinstance(value, str) else list(value)
    out = set()
    for it in items:
        it = str(it).strip()
        if it:
            out.add(pd.Timestamp(it).to_period("M").to_timestamp())
    return out


def infer_end_exclusive(max_date: pd.Timestamp, as_of: pd.Timestamp | None = None) -> pd.Timestamp:
    """
    Mốc kết thúc (loại trừ) của dữ liệu huấn luyện = đầu tháng KẾ TIẾP của tháng
    cuối cùng được coi là ĐỦ dữ liệu.

    Tháng chứa `max_date` (giao dịch cuối cùng) được coi là đủ nếu:
      - giao dịch cuối nằm trong 3 ngày cuối tháng, HOẶC
      - `as_of` ("hôm nay"/ngày đóng băng) cũng ở trong CÙNG tháng đó và nằm trong
        3 ngày cuối tháng (tránh coi là thiếu chỉ vì vài ngày cuối không phát sinh xuất kho).
    Ngược lại tháng đó bị coi là dở dang và bỏ ra khỏi huấn luyện.
    """
    max_date = pd.Timestamp(max_date)
    month_start = max_date.to_period("M").to_timestamp()
    dim = max_date.days_in_month
    complete = max_date.day >= dim - 2
    if as_of is not None:
        as_of = pd.Timestamp(as_of)
        if as_of.to_period("M") == max_date.to_period("M") and as_of.day >= dim - 2:
            complete = True
    return month_start + pd.DateOffset(months=1) if complete else month_start


def seasonal_index(total: pd.Series) -> np.ndarray:
    """Chỉ số mùa vụ theo tháng (mảng 13 phần tử, dùng index 1..12), trung bình = 1."""
    a = total.dropna()
    yr = a.groupby(a.index.year).transform("mean")
    r = (a / yr).groupby(a.index.month).mean().reindex(range(1, 13)).fillna(1.0)
    r = r / r.mean()
    out = np.ones(13)
    out[1:] = r.values
    return out


# ================================================================
#  Dựng ma trận SP x tháng
# ================================================================

def build_matrix(
    long_df: pd.DataFrame,
    end_exclusive: pd.Timestamp,
    exclude_months: set[pd.Timestamp] | None = None,
) -> pd.DataFrame:
    """
    long_df: cột [product_id, ds (đầu tháng), y]  (y = tổng xuất trong tháng).
    Trả về DataFrame index=product_id, cột = các đầu tháng liên tục, giá trị:
      - số lượng xuất (0 nếu tháng đó không có giao dịch SAU khi SP đã bắt đầu bán)
      - NaN cho: các tháng TRƯỚC lần bán đầu tiên của SP, và các tháng trong
        `exclude_months` (vd tháng đầu data bị ramp-up/thiếu ngày) — NaN nghĩa
        là "không dùng", khác với 0.
    """
    d = long_df[long_df["ds"] < end_exclusive]
    if d.empty:
        raise ValueError("Không có dữ liệu xuất kho để huấn luyện.")
    M = d.pivot_table(index="product_id", columns="ds", values="y", aggfunc="sum")
    full = pd.date_range(M.columns.min(), end_exclusive - pd.DateOffset(months=1), freq="MS")
    M = M.reindex(columns=full)
    vals = M.values.astype(float)
    n = vals.shape[1]
    first = np.argmax(~np.isnan(vals), axis=1)
    before = np.arange(n)[None, :] < first[:, None]
    vals = np.where(np.isnan(vals) & ~before, 0.0, vals)
    vals[before] = np.nan
    M = pd.DataFrame(vals, index=M.index, columns=full)
    for ex in (exclude_months or set()):
        if ex in M.columns:
            M[ex] = np.nan
    return M


def _aggregate_series(M: pd.DataFrame, cat_by_pid: pd.Series) -> dict[str, pd.Series]:
    series = {"total": M.sum(axis=0, min_count=1)}
    cats = cat_by_pid.reindex(M.index)
    for c in sorted(cats.dropna().unique()):
        ids = cats.index[cats == c]
        series[f"cat:{c}"] = M.loc[ids].sum(axis=0, min_count=1)
    return series


# ================================================================
#  Prophet trên chuỗi tổng hợp (có cache theo (chuỗi, tháng mốc))
# ================================================================

def _prophet_ratios(s: pd.Series, t: int, tet_months: set[pd.Timestamp], use_tet: bool) -> np.ndarray:
    """
    Fit Prophet trên s[:t+1] (chỉ dữ liệu đã biết tới tháng mốc t) và trả về
    mảng 3 phần tử: dự_báo(t+h) / trung_bình_12_tháng_gần_nhất, h=1..3.
    NaN nếu không đủ dữ liệu/Prophet lỗi.
    """
    nan3 = np.full(len(HORIZONS), np.nan)
    y = s.iloc[: t + 1].dropna()
    trail = s.iloc[max(0, t - WINDOW + 1): t + 1].mean()
    if len(y) < WINDOW or not (trail > 0):
        return nan3
    try:
        from prophet import Prophet  # import trễ: thư viện nặng
        logging.getLogger("cmdstanpy").setLevel(logging.CRITICAL)
        logging.getLogger("prophet").setLevel(logging.CRITICAL)
        df = pd.DataFrame({"ds": y.index, "y": y.values})
        last = s.index[t]
        fut_ds = [last + pd.DateOffset(months=k) for k in HORIZONS]
        fut = pd.DataFrame({"ds": list(df["ds"]) + fut_ds})
        m = Prophet(
            growth="flat",               # data ramp-up đầu kỳ làm trend tuyến tính ngoại suy lệch
            yearly_seasonality=4,
            weekly_seasonality=False,
            daily_seasonality=False,
            seasonality_mode="additive",
            interval_width=0.95,
        )
        if use_tet:
            flag = lambda d: 1.0 if d.to_period("M").to_timestamp() in tet_months else 0.0
            df["tet"] = df["ds"].map(flag)
            fut["tet"] = fut["ds"].map(flag)
            m.add_regressor("tet")
        with warnings.catch_warnings():
            warnings.simplefilter("ignore")
            m.fit(df)
            yhat = m.predict(fut)["yhat"].tail(len(HORIZONS)).values
        return yhat / trail
    except Exception as e:  # 1 chuỗi lỗi không được làm hỏng cả lượt huấn luyện
        logger.warning("Prophet lỗi ở chuỗi mốc %s: %s", s.index[t].date(), e)
        return nan3


def ensure_prophet_features(
    series: dict[str, pd.Series],
    origins: range,
    cache: dict,
    tet_months: set[pd.Timestamp],
    use_tet: bool,
    progress=None,
) -> None:
    """Điền vào `cache[(tên_chuỗi, tháng_mốc)] = ratios(3)` cho các mốc còn thiếu."""
    todo = []
    any_s = next(iter(series.values()))
    for t in origins:
        for name in series:
            key = (name, any_s.index[t], use_tet)
            if key not in cache:
                todo.append((name, t, key))
    for i, (name, t, key) in enumerate(todo):
        cache[key] = _prophet_ratios(series[name], t, tet_months, use_tet)
        if progress and (i + 1) % 25 == 0:
            progress(i + 1, len(todo))


# ================================================================
#  Đặc trưng + huấn luyện GBM
# ================================================================

def _features(Y, t, h, gidx, months, cat_codes, ap_total, ap_cat):
    """Ma trận đặc trưng cho mọi SP tại mốc t, dự báo tháng t+h. Trả về (X, lvl)."""
    w = Y[:, t - WINDOW + 1: t + 1]
    lvl = w.mean(axis=1)                       # NaN nếu cửa sổ có tháng không hợp lệ
    lvl = np.where(lvl > 0, lvl, np.nan)
    tgt = t + h
    mo = months[tgt].month if tgt < len(months) else (months[-1] + pd.DateOffset(months=tgt - len(months) + 1)).month
    lag12 = Y[:, tgt - 12] if tgt - 12 >= 0 else np.full(len(lvl), np.nan)
    n = len(lvl)
    X = np.column_stack([
        Y[:, t] / lvl,
        Y[:, t - 1] / lvl,
        lag12 / lvl,
        Y[:, t - 2: t + 1].mean(axis=1) / lvl,
        np.full(n, gidx[mo]),
        np.full(n, np.sin(2 * np.pi * mo / 12)),
        np.full(n, np.cos(2 * np.pi * mo / 12)),
        cat_codes,                              # cột 7 = categorical
        np.log1p(lvl),
        np.full(n, ap_total),
        ap_cat,
    ])
    return X, lvl


def _new_gbm(seed: int = 0):
    from sklearn.ensemble import HistGradientBoostingRegressor
    return HistGradientBoostingRegressor(
        max_depth=3, learning_rate=0.05, max_iter=200, min_samples_leaf=40,
        categorical_features=[7], loss="absolute_error", random_state=seed,
    )


@dataclass
class HybridBundle:
    version: str
    origin_month: pd.Timestamp                 # tháng cuối cùng đã biết (đầu tháng)
    target_months: list                        # 3 tháng được dự báo
    forecasts: pd.DataFrame                    # index=product_id, cột 1,2,3 (float, NaN nếu không dự báo được)
    months_valid: pd.Series                    # số tháng dữ liệu hợp lệ của từng SP
    models: dict = field(default_factory=dict)
    n_train_rows: dict = field(default_factory=dict)
    trained_at: str = ""
    signature: str = ""
    config: dict = field(default_factory=dict)


def data_signature(M: pd.DataFrame, extra: str = "") -> str:
    h = hashlib.sha1()
    h.update(np.nan_to_num(M.values, nan=-1.0).tobytes())
    h.update(",".join(str(c.date()) for c in M.columns).encode())
    h.update(extra.encode())
    return h.hexdigest()


def train_bundle(
    M: pd.DataFrame,
    cat_by_pid: pd.Series,
    tet_months: set[pd.Timestamp] | None = None,
    use_tet: bool = True,
    prophet_cache: dict | None = None,
    progress=None,
) -> HybridBundle:
    """Huấn luyện toàn bộ (Prophet tổng hợp + 3 GBM) với dữ liệu ma trận M, dự báo 3 tháng sau tháng cuối của M."""
    tet_months = tet_months if tet_months is not None else parse_months(DEFAULT_TET_MONTHS)
    cache = prophet_cache if prophet_cache is not None else {}
    months = M.columns
    T = len(months) - 1
    if T < WINDOW + 2:
        raise ValueError(f"Chỉ có {T + 1} tháng dữ liệu, cần ít nhất {WINDOW + 3} để huấn luyện hybrid.")

    Y = M.values.astype(float)
    pids = M.index.values
    cats = cat_by_pid.reindex(M.index)
    cat_names = [f"cat:{c}" if pd.notna(c) else "total" for c in cats.values]
    uniq = sorted({c for c in cat_names if c != "total"})
    code_of = {c: i for i, c in enumerate(uniq)}
    cat_codes = np.array([code_of.get(c, len(uniq)) for c in cat_names], dtype=float)

    series = _aggregate_series(M, cat_by_pid)
    # SP không có category → dùng chuỗi 'total' cho đặc trưng category
    ensure_prophet_features(series, range(WINDOW - 1, T + 1), cache, tet_months, use_tet, progress)
    gidx = seasonal_index(series["total"])

    def ap(t, h, name):
        return cache[(name, months[t], use_tet)][h - 1]

    models, n_rows, preds = {}, {}, {}
    for h in HORIZONS:
        Xs, ys = [], []
        for t in range(WINDOW - 1, T - h + 1):
            apc = np.array([ap(t, h, nm) for nm in cat_names])
            X, lvl = _features(Y, t, h, gidx, months, cat_codes, ap(t, h, "total"), apc)
            tgt = Y[:, t + h] / lvl
            ok = np.isfinite(lvl) & np.isfinite(tgt)
            if ok.any():
                Xs.append(X[ok]); ys.append(tgt[ok])
        if not Xs or sum(len(y) for y in ys) < MIN_TRAIN_ROWS:
            raise ValueError("Không đủ dòng huấn luyện cho GBM (data quá ngắn/quá ít SP).")
        Xtr, ytr = np.vstack(Xs), np.concatenate(ys)
        model = _new_gbm().fit(Xtr, ytr)
        models[h], n_rows[h] = model, int(len(ytr))

        apc = np.array([ap(T, h, nm) for nm in cat_names])
        X, lvl = _features(Y, T, h, gidx, months, cat_codes, ap(T, h, "total"), apc)
        p = np.full(len(lvl), np.nan)
        ok = np.isfinite(lvl)
        if ok.any():
            p[ok] = np.maximum(0.0, model.predict(X[ok]) * lvl[ok])
        preds[h] = p

    origin = months[T]
    return HybridBundle(
        version=MODEL_VERSION,
        origin_month=origin,
        target_months=[origin + pd.DateOffset(months=h) for h in HORIZONS],
        forecasts=pd.DataFrame(preds, index=pids),
        months_valid=pd.Series(np.sum(~np.isnan(Y), axis=1), index=pids),
        models=models,
        n_train_rows=n_rows,
        trained_at=datetime.now().isoformat(timespec="seconds"),
        signature=data_signature(M, extra=f"tet={use_tet}|{sorted(str(m.date()) for m in tet_months)}"),
        config={"use_tet": use_tet, "window": WINDOW, "horizons": list(HORIZONS)},
    )


def predict_product(bundle: HybridBundle, product_id: int) -> dict:
    """Tra dự báo 3 tháng của 1 SP — cùng dạng dict với run_prophet() cũ. ValueError nếu không dự báo được."""
    if product_id not in bundle.forecasts.index:
        raise ValueError(f"Sản phẩm {product_id} không có lịch sử xuất kho để dự báo.")
    row = bundle.forecasts.loc[product_id]
    if row.isna().any():
        raise ValueError(
            f"Sản phẩm {product_id} chưa có đủ {WINDOW} tháng dữ liệu liên tục để dự báo hybrid."
        )
    r = [int(max(0, round(float(row[h])))) for h in HORIZONS]
    return {
        "next_month": r[0],
        "next_2_months": r[1],
        "next_3_months": r[2],
        "months_trained": int(bundle.months_valid.loc[product_id]),
        "model_version": bundle.version,
    }


# ================================================================
#  Walk-forward backtest (dùng cho script CSV / kiểm tra độ chính xác)
# ================================================================

def walk_forward(
    M_full: pd.DataFrame,
    cat_by_pid: pd.Series,
    first_origin: pd.Timestamp,
    n_origins: int = 12,
    tet_months: set[pd.Timestamp] | None = None,
    use_tet: bool = True,
    prophet_cache: dict | None = None,
    progress=None,
) -> pd.DataFrame:
    """
    Với mỗi mốc k (first_origin, +1 tháng, ...): chỉ dùng dữ liệu TRƯỚC mốc đó
    để huấn luyện, dự báo h=1..3, so với thực tế. Trả về dòng
    [origin, h, product_id, forecast, actual, mean_baseline].
    mean_baseline = trung bình mọi tháng hợp lệ trước mốc (tương đương nhánh No-AI).
    """
    cache = prophet_cache if prophet_cache is not None else {}
    months = M_full.columns
    start = months.get_loc(pd.Timestamp(first_origin).to_period("M").to_timestamp())
    rows = []
    for k in range(n_origins):
        o = start + k                                   # chỉ số tháng đầu tiên cần dự báo
        if o >= len(months):                            # hết dữ liệu để đối chiếu
            break
        M_k = M_full.iloc[:, :o]
        bundle = train_bundle(M_k, cat_by_pid, tet_months, use_tet, cache, progress)
        mean_base = M_k.mean(axis=1, skipna=True)
        for h in HORIZONS:
            if o - 1 + h >= len(months):
                continue
            actual = M_full.iloc[:, o - 1 + h]
            rows.append(pd.DataFrame({
                "origin": months[o], "h": h, "product_id": M_full.index,
                "forecast": bundle.forecasts[h].reindex(M_full.index).values,
                "actual": actual.values,
                "mean_baseline": mean_base.reindex(M_full.index).values,
            }))
    if not rows:
        raise ValueError("Không có mốc nào để backtest (first_origin quá gần cuối dữ liệu).")
    return pd.concat(rows, ignore_index=True)


def summarize_backtest(bt: pd.DataFrame) -> pd.DataFrame:
    """WAPE / bias theo horizon cho hybrid và baseline trung bình."""
    d = bt.dropna(subset=["forecast", "actual", "mean_baseline"])
    out = []
    for h, g in d.groupby("h"):
        a = g["actual"].sum()
        out.append({
            "h": h,
            "n": len(g),
            "WAPE_hybrid_%": 100 * (g["forecast"] - g["actual"]).abs().sum() / a,
            "bias_hybrid_%": 100 * (g["forecast"].sum() - a) / a,
            "WAPE_mean_baseline_%": 100 * (g["mean_baseline"] - g["actual"]).abs().sum() / a,
            "bias_mean_baseline_%": 100 * (g["mean_baseline"].sum() - a) / a,
        })
    return pd.DataFrame(out).round(2)
