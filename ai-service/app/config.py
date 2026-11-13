from pydantic_settings import BaseSettings, SettingsConfigDict

class Settings(BaseSettings):
    # Database
    database_url: str
    # Role chỉ-đọc cho text-to-SQL (smartware_ai_ro). Để trống = tắt text-to-SQL.
    database_url_ro: str = ""

    # ── LM Studio — dữ liệu nhạy cảm, offline ───────────────────
    lm_api_key:  str = "smartware"
    lm_base_url: str = "http://host.docker.internal:1234/v1"
    lm_model:    str = "qwen2.5-14b-instruct" 

    # ── GPT-4o mini — giải thích Prophet, text-to-sql, RAG ───────
    gpt_mini_api_key:  str = ""
    gpt_mini_base_url: str = "https://api.openai.com/v1"
    gpt_mini_model:    str = "gpt-4o-mini"

    # ── GPT-4o — OCR hóa đơn, OCR chính sách ─────────────────────
    gpt_api_key:  str = ""
    gpt_base_url: str = "https://api.openai.com/v1"
    gpt_model:    str = "gpt-4o"

    # Security
    api_secret_key: str

    # CORS: danh sách origin cho phép, cách nhau bằng dấu phẩy. Để trống = không cho trình duyệt gọi chéo origin.
    cors_origins: str = ""

    # ── Dự báo: engine + cấu hình hybrid (Prophet tổng hợp + GBM) ──
    # 'hybrid' (mặc định) hoặc 'prophet' (Prophet từng SP — cấu hình cũ, giữ làm đối chứng).
    forecast_engine: str = "hybrid"
    # Thư mục lưu model/cache hybrid (nên mount volume để không mất khi rebuild container).
    forecast_model_dir: str = "models"
    # Tháng bỏ khỏi dữ liệu huấn luyện (vd tháng đầu data bị ramp-up/thiếu ngày), cách nhau bằng dấu phẩy.
    forecast_exclude_months: str = "2023-01"
    # Tháng dương lịch có mùng 1 Tết. CẦN KIỂM TRA LẠI; thêm năm mới khi cần.
    forecast_tet_months: str = "2023-01,2024-02,2025-01,2026-02,2027-02,2028-01"
    forecast_use_tet: bool = True

    # App config
    debug:      bool = False
    cache_ttl:  int  = 3600
    max_tokens: int  = 1000

    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

settings = Settings()