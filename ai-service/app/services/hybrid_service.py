# app/services/hybrid_service.py
"""
Lớp DB/FastAPI cho hybrid (Prophet tổng hợp + GBM). Phần tính toán nằm ở
hybrid_forecast.py (thuần, không DB).

Việc file này làm:
  1. Đọc lịch sử xuất kho theo tháng từ PostgreSQL (1 query cho TẤT CẢ SP).
  2. Huấn luyện bundle (lần đầu ~1-2 phút vì phải fit Prophet cho 9 chuỗi x
     mọi tháng mốc; các lần sau chỉ fit thêm tháng mới nhờ cache).
  3. Cache: RAM (theo TTL settings.cache_ttl) + đĩa (settings.forecast_model_dir),
     tự huấn luyện lại khi dữ liệu thay đổi (so chữ ký dữ liệu).
  4. Tra dự báo 1 SP / xuất CSV / lưu vào bảng forecasts.
"""
from __future__ import annotations

import logging
import os
import threading
import time
from datetime import date

import joblib
import pandas as pd
from sqlalchemy import text
from sqlalchemy.orm import Session

from app.config import settings
from app.services import hybrid_forecast as hf
from app.services.app_time import app_today

logger = logging.getLogger(__name__)

_LOCK = threading.Lock()
_MEM: dict[str, tuple[hf.HybridBundle, float]] = {}   # key: đầu tháng giới hạn -> (bundle, thời điểm kiểm tra)


# ================================================================
#  Đọc dữ liệu
# ================================================================

def _cap(db: Session, train_end_date: date | None) -> pd.Timestamp:
    """
    Mốc LOẠI TRỪ của dữ liệu huấn luyện (chỉ lấy giao dịch có created < mốc này).
      - có train_end_date  → chính ngày đó (giống get_monthly_sales: created < train_end_date)
      - không có           → ngày 'hôm nay' của hệ thống (app_today, tôn trọng ngày đóng băng) + 1 ngày,
                             tức là giao dịch trong chính ngày đó vẫn được tính.
    """
    if train_end_date is not None:
        return pd.Timestamp(train_end_date)
    return pd.Timestamp(app_today(db)) + pd.Timedelta(days=1)


def _load_long(db: Session, cap: pd.Timestamp):
    """(long_df[product_id, ds, y], cat_by_pid, max_date) của mọi SP, chỉ dữ liệu TRƯỚC `cap`."""
    rows = db.execute(text("""
        SELECT soi.product_id,
               DATE_TRUNC('month', so.created)::date AS ds,
               SUM(soi.quantity)::float              AS y,
               MAX(so.created)                       AS last_ts
        FROM stock_outbound_items soi
        JOIN stock_outbounds so ON so.id = soi.outbound_id
        WHERE so.status = 'completed'
          AND so.deleted_at IS NULL
          AND so.created < :cap
        GROUP BY soi.product_id, DATE_TRUNC('month', so.created)
    """), {"cap": cap.to_pydatetime()}).fetchall()
    if not rows:
        raise ValueError("Không có dữ liệu xuất kho (completed) để huấn luyện.")
    long_df = pd.DataFrame(rows, columns=["product_id", "ds", "y", "last_ts"])
    max_date = pd.Timestamp(long_df["last_ts"].max())
    long_df["ds"] = pd.to_datetime(long_df["ds"])
    long_df = long_df.drop(columns="last_ts")

    cats = db.execute(text("SELECT id, category_id FROM products")).fetchall()
    cat_by_pid = pd.Series({r[0]: r[1] for r in cats}, dtype="float64")
    return long_df, cat_by_pid, max_date


# ================================================================
#  Lưu/nạp trên đĩa
# ================================================================

def _model_dir() -> str:
    d = settings.forecast_model_dir
    os.makedirs(d, exist_ok=True)
    return d


def _bundle_path(end_excl: pd.Timestamp) -> str:
    return os.path.join(_model_dir(), f"hybrid_{end_excl.strftime('%Y-%m')}.joblib")


def _cache_path() -> str:
    return os.path.join(_model_dir(), "hybrid_prophet_cache.joblib")


def _load_prophet_cache() -> dict:
    p = _cache_path()
    if os.path.exists(p):
        try:
            return joblib.load(p)
        except Exception as e:
            logger.warning("Không đọc được prophet cache (%s) — sẽ tính lại.", e)
    return {}


# ================================================================
#  API chính
# ================================================================

def get_bundle(db: Session, train_end_date: date | None = None, force: bool = False) -> hf.HybridBundle:
    """
    Trả về bundle dự báo đã huấn luyện với dữ liệu TRƯỚC tháng của `train_end_date`
    (mặc định: tháng của 'hôm nay' theo app_today). Tự huấn luyện nếu chưa có / dữ liệu đã đổi.
    `force=True` bỏ qua mọi cache (kể cả cache Prophet trên đĩa) và huấn luyện lại từ đầu.
    """
    cap = _cap(db, train_end_date)
    as_of = cap - pd.Timedelta(days=1)       # ngày cuối cùng được phép dùng
    key = cap.strftime("%Y-%m-%d")

    ent = _MEM.get(key)
    if ent and not force and (time.time() - ent[1]) < settings.cache_ttl:
        return ent[0]

    with _LOCK:
        ent = _MEM.get(key)
        if ent and not force and (time.time() - ent[1]) < settings.cache_ttl:
            return ent[0]

        long_df, cat_by_pid, max_date = _load_long(db, cap)
        end_excl = hf.infer_end_exclusive(max_date, as_of)
        exclude = hf.parse_months(settings.forecast_exclude_months)
        tet = hf.parse_months(settings.forecast_tet_months)
        use_tet = settings.forecast_use_tet
        M = hf.build_matrix(long_df, end_excl, exclude)
        sig = hf.data_signature(M, extra=f"tet={use_tet}|{sorted(str(m.date()) for m in tet)}")

        if not force:
            if ent and ent[0].signature == sig:
                _MEM[key] = (ent[0], time.time())
                return ent[0]
            path = _bundle_path(end_excl)
            if os.path.exists(path):
                try:
                    b = joblib.load(path)
                    if b.signature == sig:
                        _MEM[key] = (b, time.time())
                        return b
                except Exception as e:
                    logger.warning("Không đọc được bundle %s (%s) — huấn luyện lại.", path, e)

        logger.info("Huấn luyện hybrid: %d SP x %d tháng (đến hết %s)...",
                    M.shape[0], M.shape[1], M.columns[-1].strftime("%Y-%m"))
        cache = {} if force else _load_prophet_cache()
        b = hf.train_bundle(M, cat_by_pid, tet, use_tet, cache)
        try:
            joblib.dump(b, _bundle_path(end_excl))
            joblib.dump(cache, _cache_path())
        except Exception as e:  # lỗi ghi đĩa không được làm hỏng dự báo
            logger.warning("Không lưu được model ra đĩa: %s", e)
        _MEM[key] = (b, time.time())
        return b


def predict_for_product(db: Session, product_id: int, train_end_date: date | None = None) -> dict:
    """Dict cùng dạng run_prophet() (next_month, next_2_months, next_3_months, months_trained, ...). ValueError nếu không đủ dữ liệu."""
    return hf.predict_product(get_bundle(db, train_end_date), product_id)


def forecasts_frame(db: Session, train_end_date: date | None = None, force: bool = False) -> tuple[pd.DataFrame, hf.HybridBundle]:
    """DataFrame dự báo 3 tháng cho mọi SP (kèm sku/name) + bundle."""
    b = get_bundle(db, train_end_date, force=force)
    info = pd.DataFrame(
        db.execute(text("SELECT id, sku, name, supplier_id FROM products")).fetchall(),
        columns=["product_id", "sku", "product_name", "supplier_id"],
    ).set_index("product_id")
    fc = b.forecasts.copy()
    fc.columns = [f"forecast_{d.strftime('%Y-%m')}" for d in b.target_months]
    fc = fc.round().astype("Int64")
    out = info.join(fc, how="right")
    out["months_valid"] = b.months_valid.reindex(out.index).values
    out.index.name = "product_id"
    return out.reset_index(), b


def save_forecasts(db: Session, bundle: hf.HybridBundle) -> int:
    """
    Ghi dự báo 3 tháng của mọi SP vào bảng `forecasts` (model_version = bundle.version).
    Mỗi SP 3 dòng, cùng `created` (cùng transaction) như save_forecast() cũ nên
    get_latest_forecast() vẫn đọc đúng. confidence = NULL vì hybrid không tính khoảng
    tin cậy (cột phải cho phép NULL — xem db/migrations/2026-10-22_forecasts_confidence_nullable.sql).
    """
    sup = dict(db.execute(text("SELECT id, supplier_id FROM products")).fetchall())
    n = 0
    for pid, row in bundle.forecasts.iterrows():
        if row.isna().any() or sup.get(pid) is None:
            continue
        for h, tm in zip(hf.HORIZONS, bundle.target_months):
            db.execute(text("""
                INSERT INTO forecasts
                    (supplier_id, product_id, period_start, period_end,
                     predicted_quantity, confidence, model_version)
                VALUES (:sid, :pid, :ps, :pe, :q, NULL, :mv)
            """), {
                "sid": int(sup[pid]), "pid": int(pid),
                "ps": tm.date(), "pe": (tm + pd.offsets.MonthEnd(0)).date(),
                "q": int(max(0, round(float(row[h])))), "mv": bundle.version,
            })
            n += 1
    db.commit()
    return n
