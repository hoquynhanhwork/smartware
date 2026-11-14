# Hybrid forecast (Prophet tổng hợp + GBM) — hướng dẫn nhanh

## Chạy
1. `pip install -r requirements.txt` (đã thêm scikit-learn, joblib).
2. Chạy migration `db/migrations/2026-10-22_forecasts_confidence_nullable.sql` (cho phép `forecasts.confidence` NULL).
3. `docker compose up`. Lần gọi đầu tiên tự huấn luyện (~35 giây trên 309 SP x 36 tháng, máy 1 core); model lưu ở `ai-service/models/`.
4. Gọi (header `X-API-Key`):
   - `GET  /api/v1/forecast/hybrid/status`
   - `GET  /api/v1/forecast/hybrid/forecasts`  (JSON, 309 SP x 3 tháng)
   - `GET  /api/v1/forecast/hybrid/export.csv` (tải CSV)
   - `POST /api/v1/forecast/hybrid/retrain`  body `{"train_end_date":"2025-01-01","force":false}`
   - `POST /api/v1/forecast/hybrid/save`     (ghi vào bảng `forecasts`, model_version=hybrid-1.0)
   - `POST /api/v1/forecast/advanced` giữ nguyên hợp đồng; engine chọn bằng biến môi trường.

## Chọn engine
`FORECAST_ENGINE=hybrid` (mặc định) hoặc `prophet` (cấu hình cũ, giữ để đối chứng).
Các biến khác: `FORECAST_EXCLUDE_MONTHS=2023-01`, `FORECAST_TET_MONTHS=...`, `FORECAST_USE_TET=true`, `FORECAST_MODEL_DIR=models`.

## Chạy không cần DB (từ CSV)
`python scripts/run_hybrid_csv.py --folder <thư mục có products.csv + stock_outbound_items_export.csv> --backtest-from 2025-01-01`
