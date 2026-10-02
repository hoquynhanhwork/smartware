# app/services/app_time.py
"""
"Đồng hồ ảo" dùng chung cho phần Python của ai-service.

Data hiện chỉ có tới hết 2025, nhưng máy chủ chạy theo giờ thật (đã sang
2026) — nếu dùng date.today()/datetime.now() trực tiếp, mọi so sánh dựa
vào "hôm nay" (hạn dùng, lâu không xuất, kỳ dự báo...) sẽ bị lệch, coi
data 2025 như đã cũ/hết hạn từ lâu.

Giải pháp: đọc mốc "hôm nay" từ bảng public.app_settings (key='frozen_date')
thay vì dùng giờ thật. Đổi mốc đóng băng chỉ cần 1 câu UPDATE trong DB,
không cần sửa code/deploy lại. Xóa dòng frozen_date (hoặc để value rỗng)
thì tự động quay về dùng giờ thật.

Song song có 2 hàm SQL cùng tên (public.app_today() / public.app_now())
dùng trong các câu raw SQL — 2 bên đọc chung 1 bảng app_settings, luôn
đồng bộ với nhau. Xem migration_app_settings.sql.
"""
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