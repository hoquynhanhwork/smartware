from pydantic_settings import BaseSettings, SettingsConfigDict

class Settings(BaseSettings):
    # Database
    database_url: str

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

    # App config
    debug:      bool = False
    cache_ttl:  int  = 3600
    max_tokens: int  = 1000

    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

settings = Settings()