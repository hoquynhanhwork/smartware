# app/services/app_time.py
from datetime import date, datetime
from sqlalchemy.orm import Session
from sqlalchemy import text


def _get_frozen_date_str(db: Session) -> str | None:
    row = db.execute(
        text("SELECT value FROM public.app_settings WHERE key = 'frozen_date'")
    ).fetchone()
    return row[0] if row and row[0] else None


def app_today(db: Session) -> date:
    """Ngày 'hiện tại' của hệ thống — frozen_date nếu có cấu hình, ngược lại ngày thật."""
    frozen = _get_frozen_date_str(db)
    return date.fromisoformat(frozen) if frozen else date.today()


def app_now(db: Session) -> datetime:
    """Timestamp 'hiện tại' của hệ thống — frozen_date lúc 00:00:00 nếu có cấu hình, ngược lại giờ thật."""
    frozen = _get_frozen_date_str(db)
    return datetime.combine(date.fromisoformat(frozen), datetime.min.time()) if frozen else datetime.now()