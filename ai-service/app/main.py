from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware

from app.db.postgresql import test_connection
from app.routers import analyze
from app.routers import forecast
from app.routers import forecast_advanced
from app.routers import risks
from app.routers import trends
from app.routers import replenishment
from app.routers import batch_analyze
from app.routers import ocr
from app.routers import explain
from app.routers import assessment      # thêm
from app.routers import alerts          # thêm
from app.routers import text_to_sql

app = FastAPI(
    title       = "SmartWare AI Service",
    description = "AI microservice cho hệ thống quản lý kho SmartWare",
    version     = "2.0.0",
)

# Cho phép PHP gọi từ localhost
app.add_middleware(
    CORSMiddleware,
    allow_origins  = ["*"],
    allow_methods  = ["*"],
    allow_headers  = ["*"],
)

# ── Routers (Giai đoạn 1 — có sẵn) ───────────────────────────
app.include_router(analyze.router,           prefix="/api/v1", tags=["Analyze"])
app.include_router(forecast.router,          prefix="/api/v1", tags=["Forecast"])
app.include_router(forecast_advanced.router, prefix="/api/v1", tags=["Forecast Advanced"])

# ── Routers (Giai đoạn 2 — mới xây) ──────────────────────────
app.include_router(risks.router,             prefix="/api/v1", tags=["Risk Analysis"])
app.include_router(trends.router,            prefix="/api/v1", tags=["Trend Analysis"])
app.include_router(replenishment.router,     prefix="/api/v1", tags=["Replenishment"])
app.include_router(batch_analyze.router,     prefix="/api/v1", tags=["Batch & Chat"])
app.include_router(ocr.router,              prefix="/api/v1", tags=["OCR"])
app.include_router(assessment.router,        prefix="/api/v1", tags=["Assessment"])   # thêm
app.include_router(alerts.router,            prefix="/api/v1", tags=["Alerts"])       # thêm
app.include_router(text_to_sql.router, prefix="/api/v1", tags=["Text-to-SQL"])

app.include_router(explain.router)

@app.get("/health", tags=["System"])
def health_check():
    db_ok = test_connection()
    return {
        "status":   "running",
        "version":  "2.0.0",
        "database": "connected" if db_ok is True else db_ok,
        "endpoints": [
            "POST /api/v1/predict",
            "POST /api/v1/forecast",
            "POST /api/v1/forecast/advanced",
            "POST /api/v1/risks",
            "POST /api/v1/trends",
            "POST /api/v1/replenishment",
            "POST /api/v1/analyze/batch",
            "POST /api/v1/chat",
            "POST /api/v1/ocr/invoice",
            "POST /api/v1/assess",
            "POST /api/v1/assess/batch",
            "POST /api/v1/alerts/generate",
        ],
    }