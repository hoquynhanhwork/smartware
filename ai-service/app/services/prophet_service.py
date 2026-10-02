# app/services/prophet_service.py
import pandas as pd
import numpy as np
from prophet import Prophet
from sqlalchemy.orm import Session
from sqlalchemy import text
from datetime import datetime, date
from app.services.gpt_service import explain_forecast
from app.services.app_time import app_today

# ================================================================
#  BƯỚC 1 — Query lịch sử xuất kho từ DB
# ================================================================

def get_monthly_sales(
    db: Session,
    product_id: int,
    supplier_id: int,
    train_end_date: date | None = None,
) -> pd.DataFrame:
    """
    Lấy lịch sử xuất kho theo tháng từ PostgreSQL.
    Trả về DataFrame với 2 cột: ds (date), y (quantity)

    train_end_date: nếu có, chỉ lấy data trước ngày này (dùng để tách
    tập train/test khi backtest — vd train_end_date = 2025-01-01 để
    train trên 2023-2024 và giữ 2025 lại làm tập so sánh).
    """
    where_extra = ""
    params = {"product_id": product_id, "supplier_id": supplier_id}
    if train_end_date is not None:
        where_extra = "AND soi.created < :train_end_date"
        params["train_end_date"] = train_end_date

    sql = text(f"""
        SELECT
            DATE_TRUNC('month', soi.created)::date AS ds,
            SUM(soi.quantity)::float               AS y
        FROM stock_outbound_items soi
        JOIN stock_outbounds so ON so.id = soi.outbound_id
        WHERE soi.product_id  = :product_id
          AND so.supplier_id  = :supplier_id
          AND so.status       = 'completed'
          {where_extra}
        GROUP BY DATE_TRUNC('month', soi.created)
        ORDER BY ds
    """)

    rows = db.execute(sql, params).fetchall()

    if not rows:
        return pd.DataFrame(columns=['ds', 'y'])

    df = pd.DataFrame(rows, columns=['ds', 'y'])
    df['ds'] = pd.to_datetime(df['ds'])
    return df


def get_daily_sales(
    db: Session,
    product_id: int,
    supplier_id: int,
    train_end_date: date | None = None,
) -> pd.DataFrame:
    """
    Lấy lịch sử xuất kho theo NGÀY (không phải tháng) — dùng riêng để
    tính avg_daily_demand thực tế và độ lệch chuẩn σ cho Safety Stock
    (Z x σ x √lead_time). Ngày không có giao dịch → 0 (fill đủ dải ngày).
    """
    where_extra = ""
    params = {"product_id": product_id, "supplier_id": supplier_id}
    if train_end_date is not None:
        where_extra = "AND soi.created < :train_end_date"
        params["train_end_date"] = train_end_date

    sql = text(f"""
        SELECT
            soi.created::date AS ds,
            SUM(soi.quantity)::float AS y
        FROM stock_outbound_items soi
        JOIN stock_outbounds so ON so.id = soi.outbound_id
        WHERE soi.product_id  = :product_id
          AND so.supplier_id  = :supplier_id
          AND so.status       = 'completed'
          {where_extra}
        GROUP BY soi.created::date
        ORDER BY ds
    """)

    rows = db.execute(sql, params).fetchall()

    if not rows:
        return pd.DataFrame(columns=['ds', 'y'])

    df = pd.DataFrame(rows, columns=['ds', 'y'])
    df['ds'] = pd.to_datetime(df['ds'])

    # Fill đủ các ngày không giao dịch = 0, để avg/std phản ánh đúng nhu cầu hàng ngày
    full_range = pd.date_range(start=df['ds'].min(), end=df['ds'].max(), freq='D')
    df = df.set_index('ds').reindex(full_range, fill_value=0).reset_index()
    df.columns = ['ds', 'y']
    return df


def get_lead_time_days(db: Session, supplier_id: int, default_days: int = 7) -> int:
    """Lấy lead_time_days từ bảng suppliers, fallback default nếu NULL."""
    row = db.execute(
        text("SELECT lead_time_days FROM public.suppliers WHERE id = :sid"),
        {"sid": supplier_id},
    ).fetchone()
    if row and row[0]:
        return int(row[0])
    return default_days


def get_reorder_cycle_days(
    db: Session,
    product_id: int,
    train_end_date: date | None = None,
) -> float | None:
    """
    Chu kỳ đặt hàng lại THẬT (trung vị khoảng cách giữa các lần nhập hàng
    liên tiếp, tính theo ngày) — dùng cho phần Max trong
    calculate_ai_thresholds(), THAY cho giả định "chu kỳ ≈ lead_time".

    Đã kiểm chứng bằng reorder_cycle_analysis.py trên data thật: TOÀN BỘ
    309/309 SP có chu kỳ nhập thật > 4x lead_time (trung vị 7.62x, SP thấp
    nhất cũng 4.10x) — giả định cũ hoàn toàn không khớp cách kho này vận
    hành (nhập theo lô lớn, cách xa nhau hơn lead_time rất nhiều).

    Dùng TRUNG VỊ (không phải trung bình) — chuẩn cho phân phối lệch phải
    (khoảng nghỉ dài bất thường kéo trung bình lệch lên), đúng cách đã
    chốt khi bàn công thức.

    Trả về None nếu không đủ ≥2 lần nhập để tính khoảng cách (SP mới/ít
    giao dịch) — nơi gọi cần tự fallback về lead_time_days khi đó.
    """
    where_extra = ""
    params = {"product_id": product_id}
    if train_end_date is not None:
        where_extra = "AND si.created < :train_end_date"
        params["train_end_date"] = train_end_date

    sql = text(f"""
        SELECT DISTINCT si.created::date AS inbound_date
        FROM stock_inbound_items sii
        JOIN stock_inbounds si ON si.id = sii.inbound_id
        WHERE sii.product_id = :product_id
          AND si.deleted_at IS NULL
          AND si.status = 'completed'
          {where_extra}
        ORDER BY inbound_date
    """)
    rows = db.execute(sql, params).fetchall()
    dates = [r[0] for r in rows]

    if len(dates) < 2:
        return None

    gaps = sorted((dates[i + 1] - dates[i]).days for i in range(len(dates) - 1))
    n = len(gaps)
    median = gaps[n // 2] if n % 2 == 1 else (gaps[n // 2 - 1] + gaps[n // 2]) / 2
    return float(median)


def calculate_frequency_pct(daily_df: pd.DataFrame) -> float:
    """
    Tần suất xuất hàng của 1 SP = % số ngày (trong kỳ train) có giao dịch
    xuất > 0. Đây là feature "tần suất xuất hàng ở mỗi SP" trong sơ đồ gốc
    (Feature Engineering) — dùng để điều chỉnh Safety Stock cho SP có nhu
    cầu thưa/không đều (demand càng rời rạc thì càng cần dự phòng nhiều
    hơn, vì σ tính trên chuỗi nhiều ngày = 0 dễ đánh giá thấp rủi ro thực).
    Trả về giá trị 0-1. Không đủ data → coi như đều (1.0, không điều chỉnh).
    """
    if daily_df.empty or len(daily_df) == 0:
        return 1.0
    days_with_txn = int((daily_df['y'] > 0).sum())
    total_days = len(daily_df)
    return days_with_txn / total_days


def calculate_ai_thresholds(
    avg_daily_demand: float,
    daily_std_dev: float,
    lead_time_days: int,
    z: float = 1.65,
    frequency_pct: float | None = None,
    reorder_cycle_days: float | None = None,
) -> dict:
    """
    Công thức chuẩn đã chốt cho nhánh AI:
      Safety Stock = Z x σ x √lead_time x hệ_số_tần_suất
      ROP          = avg_daily_demand x lead_time + Safety Stock
      Max          = ROP + avg_daily_demand x chu_kỳ_đặt_hàng_THẬT
    Z=1.65 (~95% service level) là giả định do thiếu khảo sát chi phí
    thiếu hàng/tồn kho thực tế — nêu rõ trong báo cáo.

    hệ_số_tần_suất: công thức tuyến tính đã chốt (không dùng ngưỡng bậc
    thang tự đặt, để tránh phải giải trình các mốc cắt tùy tiện):
        hệ_số = min(1 + (1 - frequency_pct), 2.0)
    frequency_pct=1.0 (xuất đều mỗi ngày) → hệ_số=1.0 (không đổi).
    frequency_pct→0 (rất thưa)          → hệ_số tiến tới 2.0 (chặn trên).
    frequency_pct=None (không có data)   → hệ_số=1.0 (không điều chỉnh).

    reorder_cycle_days: chu kỳ đặt hàng lại THẬT (trung vị khoảng cách
    giữa các lần nhập hàng liên tiếp — xem get_reorder_cycle_days()).
    TRƯỚC ĐÂY Max dùng lead_time_days cho phần này (giả định chu kỳ đặt
    hàng ≈ lead_time) — đã kiểm chứng SAI trên data thật qua
    reorder_cycle_analysis.py: 309/309 SP có chu kỳ thật >4x lead_time
    (trung vị 7.62x). Nay dùng chu kỳ thật đo được; chỉ fallback về
    lead_time_days khi SP không đủ ≥2 lần nhập để đo (reorder_cycle_days
    =None — SP mới/ít giao dịch).
    """
    freq_factor = 1.0
    if frequency_pct is not None:
        freq_factor = min(1 + (1 - frequency_pct), 2.0)

    safety_stock = z * daily_std_dev * (lead_time_days ** 0.5) * freq_factor
    rop = avg_daily_demand * lead_time_days + safety_stock

    max_coverage_days = reorder_cycle_days if reorder_cycle_days is not None else lead_time_days
    max_ai = rop + avg_daily_demand * max_coverage_days

    return {
        "safety_stock": round(safety_stock),
        "rop":          round(rop),
        "max_ai":       round(max_ai),
        "z":            z,
        "freq_factor":  round(freq_factor, 3),
        "max_coverage_days_used": round(max_coverage_days, 1),
    }


# ================================================================
#  BƯỚC 2 — Pre-processing
# ================================================================

def preprocess(df: pd.DataFrame) -> pd.DataFrame:
    """
    1. Fill missing months (tháng không có giao dịch → 0)
    2. Cap outliers (giá trị > mean + 3*std → thay bằng mean)
    3. Đảm bảo đúng format Prophet (ds, y)
    """
    if df.empty:
        return df

    # Fill missing months
    full_range = pd.date_range(
        start=df['ds'].min(),
        end=df['ds'].max(),
        freq='MS'  # Month Start
    )
    df = df.set_index('ds').reindex(full_range, fill_value=0).reset_index()
    df.columns = ['ds', 'y']

    # Cap outliers — tháng bất thường (ví dụ COVID, lũ lụt)
    mean = df['y'].mean()
    std  = df['y'].std()
    upper = mean + 3 * std
    df['y'] = df['y'].clip(upper=upper)

    # Đảm bảo không có giá trị âm
    df['y'] = df['y'].clip(lower=0)

    return df


def check_sufficient_data(df: pd.DataFrame, min_months: int = 12) -> bool:
    """Kiểm tra đủ data để Prophet chạy chính xác."""
    return len(df) >= min_months


# ================================================================
#  BƯỚC 3 — Chạy Prophet
# ================================================================

def run_prophet(
    df: pd.DataFrame,
    periods: int = 3,
    seasonality_mode: str = 'additive',
    yearly_order: int | bool = 4,
) -> dict:
    """
    Chạy Prophet dự báo {periods} tháng tới.

    Cấu hình mặc định đã điều chỉnh cho chỉ ~24 tháng train (2 chu kỳ
    năm — Prophet cảnh báo "under-identified" khi <730 ngày):
      - seasonality_mode='additive' (trước: 'multiplicative').
      - yearly_order=4 = số Fourier terms của mùa vụ năm (trước:
        yearly_seasonality=True → mặc định 10): ít chu kỳ để học thì
        giảm số chi tiết cần học, tránh "học thuộc" đúng 2 lần lặp.
    LƯU Ý: additive có thể làm dự báo âm nhiều hơn với SP số liệu nhỏ
    (mùa vụ trừ theo đơn vị tuyệt đối) — không mặc định coi là tốt
    hơn; mae_mape_analysis.py chạy CV so sánh cấu hình cũ/mới để quyết
    định bằng số liệu. Truyền seasonality_mode='multiplicative',
    yearly_order=True để tái tạo cấu hình cũ.
    """
    model = Prophet(
        yearly_seasonality=yearly_order,
        weekly_seasonality=False,
        daily_seasonality=False,
        seasonality_mode=seasonality_mode,
        changepoint_prior_scale=0.05,
        interval_width=0.95,
    )

    model.fit(df)

    # Tạo dataframe tương lai
    future = model.make_future_dataframe(periods=periods, freq='MS')
    forecast = model.predict(future)

    # Lấy 3 tháng cuối (tương lai)
    future_forecast = forecast.tail(periods)

    # Tính trend
    recent_avg = df['y'].tail(3).mean()
    older_avg  = df['y'].head(3).mean()
    if recent_avg > older_avg * 1.1:
        trend = "tăng"
    elif recent_avg < older_avg * 0.9:
        trend = "giảm"
    else:
        trend = "ổn định"

    # Lấy giá trị dự báo (không âm)
    # round() thay vì int(): int() cắt phần thập phân (0.6 → 0), khiến dự
    # báo nhỏ bị lệch hẳn về 0 dù thực chất gần bằng 1 — round() phản ánh
    # đúng giá trị dự báo hơn (0.6 → 1), tránh ROP/Safety Stock/Max bị
    # sụp về cùng 1 giá trị khi demand dự báo trở thành 0 một cách giả tạo.
    predictions = [max(0, round(row['yhat'])) for _, row in future_forecast.iterrows()]

    return {
        "next_month":     predictions[0] if len(predictions) > 0 else 0,
        "next_2_months":  predictions[1] if len(predictions) > 1 else 0,
        "next_3_months":  predictions[2] if len(predictions) > 2 else 0,
        "trend":          trend,
        "confidence":     0.95,
        "months_trained": len(df),
    }


def run_prophet_cv(
    df: pd.DataFrame,
    initial_months: int = 12,
    horizon_months: int = 3,
    step_months: int = 3,
    seasonality_mode: str = 'additive',
    yearly_order: int | bool = 4,
) -> dict:
    """
    Cross-validation kiểu rolling-origin — CHỈ trong phạm vi df truyền
    vào (khi backtest, truyền df đã lọc train_end_date=2025-01-01 →
    không đụng 2025, giữ đúng nguyên tắc train/test tách biệt).

    Cách làm: train initial_months tháng đầu, dự báo horizon_months tháng
    tiếp theo, so với giá trị THẬT đã biết (vẫn nằm trong kỳ train) → tính
    sai số. Mở rộng cửa sổ train thêm step_months, lặp lại tới khi hết
    data. Đây cũng chính là số liệu MAE/MAPE đánh giá độ chính xác forecast
    (mục còn thiếu trong sơ đồ gốc).

    df có thể thiếu các tháng không có giao dịch (get_monthly_sales chỉ
    trả tháng có bán) → fill về đủ dải tháng (=0) TRƯỚC khi cắt fold, để
    "tháng kế tiếp" của cửa sổ train đúng là tháng lịch kế tiếp.

    Trả về: mae; mape (%, chỉ tính các tháng actual>0 — chia cho 0 vô
    nghĩa; MAPE bị thổi phồng khi actual nhỏ); wape (%, = Σ|sai số| /
    Σactual — không bị thổi phồng bởi tháng bán ít); n_folds; n_points;
    errors. mae/mape/wape=None nếu không chạy nổi fold nào.
    """
    empty = {"mae": None, "mape": None, "wape": None,
             "n_folds": 0, "n_points": 0, "errors": []}
    if df.empty:
        return empty

    full_range = pd.date_range(start=df['ds'].min(), end=df['ds'].max(), freq='MS')
    df = df.set_index('ds').reindex(full_range, fill_value=0).reset_index()
    df.columns = ['ds', 'y']

    n = len(df)
    errors = []

    origin = initial_months
    while origin + horizon_months <= n:
        train_df = df.iloc[:origin].reset_index(drop=True)
        actual_df = df.iloc[origin: origin + horizon_months].reset_index(drop=True)

        if not check_sufficient_data(train_df, min_months=12):
            origin += step_months
            continue

        try:
            train_clean = preprocess(train_df)
            result = run_prophet(
                train_clean, periods=horizon_months,
                seasonality_mode=seasonality_mode, yearly_order=yearly_order,
            )
        except Exception:
            # 1 fold lỗi (vd Stan không hội tụ) — bỏ qua fold đó, không
            # làm hỏng toàn bộ CV của sản phẩm
            origin += step_months
            continue

        preds = [
            result.get("next_month", 0),
            result.get("next_2_months", 0),
            result.get("next_3_months", 0),
        ][:horizon_months]

        for i in range(len(actual_df)):
            actual = float(actual_df["y"].iloc[i])
            predicted = preds[i] if i < len(preds) else 0
            abs_err = abs(actual - predicted)
            errors.append({
                "fold_origin": origin,
                "month_index": i,
                "actual": actual,
                "predicted": predicted,
                "abs_error": abs_err,
                "pct_error": (abs_err / actual) if actual > 0 else None,
            })

        origin += step_months

    if not errors:
        return empty

    err_df = pd.DataFrame(errors)
    mae = err_df["abs_error"].mean()
    valid_pct = err_df["pct_error"].dropna()
    mape = (valid_pct.mean() * 100) if not valid_pct.empty else None
    total_actual = err_df["actual"].sum()
    wape = (err_df["abs_error"].sum() / total_actual * 100) if total_actual > 0 else None

    return {
        "mae":      round(float(mae), 2),
        "mape":     round(float(mape), 2) if mape is not None else None,
        "wape":     round(float(wape), 2) if wape is not None else None,
        "n_folds":  int(err_df["fold_origin"].nunique()),
        "n_points": int(len(err_df)),
        "errors":   errors,
    }


# ================================================================
#  BƯỚC 4 — Lưu kết quả vào DB
# ================================================================

def calculate_growth_pct(monthly_df: pd.DataFrame) -> float:
    """
    growth = (demand_new - demand_old) / demand_old
    Chia đôi giai đoạn train (nửa đầu vs nửa sau), lấy trung bình mỗi nửa.
    Đây là 1 feature/insight phụ (Feature Engineering) — KHÔNG dùng làm
    bộ dự báo chính cho ROP/Safety Stock (bộ dự báo chính là Prophet).
    """
    if monthly_df.empty or len(monthly_df) < 2:
        return 0.0

    half = len(monthly_df) // 2
    if half == 0:
        return 0.0

    demand_old = monthly_df['y'].iloc[:half].mean()
    demand_new = monthly_df['y'].iloc[-half:].mean()

    if demand_old <= 0:
        return 0.0

    return round(((demand_new - demand_old) / demand_old) * 100, 2)


def save_forecast(
    db: Session,
    supplier_id: int,
    product_id: int,
    prophet_result: dict,
):
    """Lưu kết quả Prophet vào bảng forecasts."""
    today = app_today(db)

    # Lưu dự báo 3 tháng
    for i, key in enumerate(['next_month', 'next_2_months', 'next_3_months'], start=1):
        period_start = pd.Timestamp(today).to_period('M').to_timestamp()
        period_end   = (pd.Timestamp(today) + pd.DateOffset(months=i)).to_pydatetime().date()

        db.execute(text("""
            INSERT INTO forecasts
                (supplier_id, product_id, period_start, period_end,
                 predicted_quantity, confidence, model_version)
            VALUES
                (:supplier_id, :product_id, :period_start, :period_end,
                 :predicted_quantity, :confidence, :model_version)
        """), {
            "supplier_id": supplier_id,
            "product_id": product_id,
            "period_start": period_start.date(),
            "period_end": period_end,
            "predicted_quantity": prophet_result[key],
            "confidence": prophet_result["confidence"] * 100,
            "model_version": "prophet-1.0",
        })

    db.commit()

def calculate_order(
    prophet_result: dict,
    current_stock: int,
    min_stock: int,
    max_stock: int,
    rop: int | None = None,
    safety_stock: int | None = None,
) -> dict:
    """
    Tính số lượng cần nhập và cảnh báo dựa trên kết quả Prophet.

    rop/safety_stock: khi có (đã tính từ calculate_ai_thresholds), dùng
    làm ngưỡng cảnh báo/đệm an toàn THAY cho min_stock — vì ROP mới là
    điểm đặt hàng lại thật sự của nhánh AI, min_stock chỉ còn là ngưỡng
    tĩnh cấu hình sẵn. Nếu không truyền (fallback / gọi ngoài compute_ai_forecast),
    giữ nguyên hành vi cũ dùng min_stock.
    """
    reorder_threshold = rop if rop is not None else min_stock
    buffer_stock = safety_stock if safety_stock is not None else min_stock

    next_month_demand = prophet_result['next_month']
    max_3_months = max(
        prophet_result['next_month'],
        prophet_result['next_2_months'],
        prophet_result['next_3_months'],
    )

    days_left = (current_stock / (next_month_demand / 30)
                 if next_month_demand > 0 else float('inf'))

    needed = max_3_months + buffer_stock - current_stock
    suggested_order = max(0, int(needed))

    if max_stock > 0:
        room = max_stock - current_stock
        suggested_order = min(suggested_order, max(0, room))
    warning = None

    if current_stock == 0:
        warning = f"Hết hàng! Cần nhập ngay {suggested_order} đơn vị."

    elif current_stock <= reorder_threshold:
        warning = (
            f"Tồn kho ({current_stock}) dưới điểm đặt hàng lại ROP ({reorder_threshold}). "
            f"Cần nhập ít nhất {suggested_order} đơn vị."
        )

    elif days_left <= 7:
        warning = (
            f"Tồn kho chỉ đủ dùng khoảng {days_left:.0f} ngày. "
            f"Nên nhập {suggested_order} đơn vị sớm."
        )

    elif days_left <= 30:
        warning = f"Tồn kho đủ dùng khoảng {days_left:.0f} ngày — theo dõi chặt."

    elif suggested_order > 0:
        warning = (
            f"Xu hướng {prophet_result['trend']} — "
            f"nên chuẩn bị nhập thêm {suggested_order} đơn vị trong tháng tới."
        )

    prophet_result['suggested_order'] = suggested_order
    prophet_result['warning']         = warning
    prophet_result['days_left']       = round(days_left, 1) if days_left != float('inf') else None

    return prophet_result


def resolve_avg_daily_demand(prophet_result: dict, monthly_df: pd.DataFrame) -> tuple[float, str]:
    """
    Lấy avg_daily_demand từ dự báo Prophet, có xử lý khi tháng đầu bị
    nhiễu (yhat âm/0 do model chưa ổn định với chỉ ~24 tháng train —
    xem cảnh báo "Yearly seasonality... under-identified" khi chạy).

    Thứ tự ưu tiên (không thêm hệ số/ngưỡng tự đặt — chỉ tận dụng data
    đã có sẵn):
      1. next_month > 0                              → dùng thẳng
      2. next_month <= 0, nhưng trung bình 3 tháng > 0 → dùng trung bình
         3 tháng (làm mượt nhiễu 1 tháng đơn lẻ, dữ liệu đã có sẵn)
      3. Cả 3 tháng đều <= 0                          → fallback về
         trung bình lịch sử thật của chính SP (giống nhánh fallback_avg
         khi Prophet không chạy được — áp dụng thêm cho trường hợp
         Prophet chạy được nhưng ra kết quả vô lý)

    Trả về (avg_daily_demand, nguồn) — nguồn để biết đang dùng cách nào.
    """
    next_month = prophet_result.get("next_month", 0)
    if next_month > 0:
        return next_month / 30.0, "next_month"

    three_month_avg = (
        prophet_result.get("next_month", 0)
        + prophet_result.get("next_2_months", 0)
        + prophet_result.get("next_3_months", 0)
    ) / 3.0
    if three_month_avg > 0:
        return three_month_avg / 30.0, "3_month_avg"

    historical_avg = monthly_df["y"].mean() if not monthly_df.empty else 0.0
    return (historical_avg / 30.0 if historical_avg > 0 else 0.0), "historical_fallback"


def compute_ai_forecast(
    db: Session,
    product_id: int,
    supplier_id: int,
    current_stock: int,
    min_stock: int,
    max_stock: int = 0,
    train_end_date: date | None = None,
) -> dict:
    """
    Phần lõi: DB → Prophet → ROP/Safety Stock/Max (AI). KHÔNG gọi LLM,
    KHÔNG lưu DB — để dùng lại được cho cả API /forecast/advanced (cần
    thêm giải thích LLM) lẫn assessment_service (chạy batch nhiều SP,
    không nên gọi LLM mỗi SP vì quá chậm/tốn token).

    train_end_date: optional, dùng khi backtest (vd 2025-01-01 để chỉ
    train trên data 2023-2024 và giữ 2025 lại so sánh với No-AI).

    Raise ValueError nếu không đủ ≥12 tháng data.
    """
    df = get_monthly_sales(db, product_id, supplier_id, train_end_date=train_end_date)

    if not check_sufficient_data(df, min_months=12):
        raise ValueError(
            f"Chỉ có {len(df)} tháng data, cần ít nhất 12 tháng để dự báo chính xác."
        )

    growth_pct = calculate_growth_pct(df)

    df_clean = preprocess(df)
    prophet_result = run_prophet(df_clean)

    # ── Feature Engineering từ data theo NGÀY (độ lệch chuẩn σ + tần suất) ──
    daily_df = get_daily_sales(db, product_id, supplier_id, train_end_date=train_end_date)
    daily_std_dev = float(daily_df['y'].std()) if not daily_df.empty else 0.0
    frequency_pct = calculate_frequency_pct(daily_df)

    # avg_daily_demand dùng cho ROP/Safety Stock = dự báo Prophet tháng
    # tới quy đổi ra/ngày (bộ dự báo chính đã chốt = Prophet, không
    # phải growth đơn giản)
    avg_daily_demand_forecast, demand_source = resolve_avg_daily_demand(prophet_result, df_clean)
    lead_time_days = get_lead_time_days(db, supplier_id)
    reorder_cycle_days = get_reorder_cycle_days(db, product_id, train_end_date=train_end_date)

    thresholds = calculate_ai_thresholds(
        avg_daily_demand   = avg_daily_demand_forecast,
        daily_std_dev      = daily_std_dev,
        lead_time_days     = lead_time_days,
        frequency_pct      = frequency_pct,
        reorder_cycle_days = reorder_cycle_days,
    )

    # calculate_order() chạy SAU khi đã có rop/safety_stock, để warning/
    # suggested_order thật sự dùng ngưỡng ROP của AI thay vì min_stock cũ
    # (trước đây bị đảo thứ tự, khiến rop/safety_stock chỉ là field hiển
    # thị, không ảnh hưởng quyết định — đã sửa)
    prophet_result = calculate_order(
        prophet_result, current_stock, min_stock, max_stock,
        rop=thresholds["rop"], safety_stock=thresholds["safety_stock"],
    )

    prophet_result["rop"]              = thresholds["rop"]
    prophet_result["safety_stock"]     = thresholds["safety_stock"]
    prophet_result["max_ai"]           = thresholds["max_ai"]
    prophet_result["demand_std_dev"]   = round(daily_std_dev, 2)
    prophet_result["lead_time_days"]   = lead_time_days
    prophet_result["avg_daily_demand"] = round(avg_daily_demand_forecast, 2)
    prophet_result["growth_pct"]       = growth_pct
    prophet_result["frequency_pct"]    = round(frequency_pct, 4)
    prophet_result["reorder_cycle_days_used"] = thresholds["max_coverage_days_used"]
    # Nguồn demand thực tế đã dùng (next_month / 3_month_avg / historical_fallback)
    # — khớp cách gán ở assessment_AI_backtest.py, để đo được tỷ lệ fallback
    # khi chạy production qua assessment_service.py.
    prophet_result["forecast_source"] = demand_source

    return prophet_result


async def forecast_advanced(
    db: Session,
    product_id: int,
    product_name: str,
    supplier_id: int,
    current_stock: int,
    min_stock: int,
    max_stock: int = 0,
    train_end_date: date | None = None,
) -> tuple[dict, str, int]:
    """
    Full pipeline: compute_ai_forecast() → LM Studio giải thích → lưu forecasts.
    Returns: (prophet_result, explanation_text, tokens_used)
    """
    prophet_result = compute_ai_forecast(
        db, product_id, supplier_id, current_stock, min_stock, max_stock,
        train_end_date=train_end_date,
    )

    explanation, tokens = await explain_forecast(
        product_name   = product_name,
        prophet_result = prophet_result,
        current_stock  = current_stock,
        min_stock      = min_stock,
    )

    save_forecast(db, supplier_id, product_id, prophet_result)
    return prophet_result, explanation, tokens