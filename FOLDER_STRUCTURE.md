# 📁 Cấu Trúc Thư Mục — Smartware

> Được tạo tự động ngày: 2026-10-23  
> Đường dẫn gốc: `c:\xampp\htdocs\smartware`

---

## Tổng Quan

```
smartware/
├── .vscode/
├── ai-service/
├── app/
├── config/
├── css/
├── data/
├── db/
├── db_export/
├── img/
├── js/
├── layout/
├── output/
├── pages/
├── pgvector-master/
├── vendor/
└── [files at root]
```

---

## 📁 .vscode/
```
.vscode/
└── settings.json
```

---

## 📁 ai-service/
> Python FastAPI service — AI backend (forecast, OCR, RAG, replenishment...)

```
ai-service/
├── app/
│   ├── __pycache__/
│   ├── db/
│   │   └── postgresql.py
│   ├── models/
│   │   ├── assessment.py
│   │   ├── request.py
│   │   └── response.py
│   ├── routers/
│   │   ├── alerts.py
│   │   ├── analyze.py
│   │   ├── assessment.py
│   │   ├── batch_analyze.py
│   │   ├── explain.py
│   │   ├── forecast.py
│   │   ├── forecast_advanced.py
│   │   ├── forecast_hybrid.py
│   │   ├── ocr.py
│   │   ├── replenishment.py
│   │   ├── risks.py
│   │   ├── text_to_sql.py
│   │   └── trends.py
│   ├── services/
│   │   ├── alert_service.py
│   │   ├── app_time.py
│   │   ├── assessment_service.py
│   │   ├── batch_service.py
│   │   ├── chunker.py
│   │   ├── embedding_service.py
│   │   ├── gpt_service.py
│   │   ├── hybrid_forecast.py
│   │   ├── hybrid_service.py
│   │   ├── ocr_service.py
│   │   ├── prophet_service.py
│   │   ├── rag_service.py
│   │   ├── replenishment_service.py
│   │   ├── risk_service.py
│   │   ├── sql_guard.py
│   │   ├── text_to_sql_service.py
│   │   └── trend_service.py
│   ├── config.py
│   ├── database.py (via __pycache__)
│   ├── main.py
│   └── security.py
├── models/                        (thư mục rỗng / lưu model files)
├── php_client/
│   └── AiService.php
├── policies/                      (tài liệu chính sách công ty)
│   ├── Bang_gia_van_chuyen_duong_air.docx
│   ├── Chinh_sach_bao_hiem_den_bu.docx
│   ├── Chinh_sach_bao_mat.docx
│   ├── Chinh_sach_boi_thuong.docx
│   ├── Chinh_sach_dong_goi_tieu_chuan.docx
│   ├── chinh_sach_gia_cuoc.pdf
│   ├── Chinh_sach_ho_tro_sau_giao_dich.docx
│   ├── Chinh_sach_hop_dong.docx
│   ├── Chinh_sach_hop_dong_20-4-2026.docx
│   ├── Chinh_sach_hop_dong_20-4-2026.png
│   ├── chinh_sach_huy_don_hoan_tien.pdf
│   ├── Chinh_sach_mien_tru_trach_nhiem.docx
│   ├── Chinh_sach_minh_bach_trach_nhiem.docx
│   ├── Chinh_sach_phan_loai_hang_hoa.docx
│   ├── Chinh_sach_phi_dich_vu_thanh_toan.docx
│   ├── Chinh_sach_xu_ly_khieu_nai.docx
│   ├── Danh_muc_hang_cam_han_che.docx
│   ├── Danh_muc_hang_cam_han_che_15-4-2026.docx
│   ├── Danh_muc_hang_cam_han_che_15-4-2026.png
│   ├── Dieu_khoan_su_dung_dich_vu.docx
│   ├── Dieu_khoan_su_dung_dich_vu_25-4-2026.docx
│   ├── Dieu_khoan_su_dung_dich_vu_25-4-2026.png
│   ├── Quy_dinh_quy_cach_dong_goi.docx
│   ├── Quy_dinh_ve_khieu_nai.docx
│   ├── Quy_dinh_ve_khieu_nai_8-1-2026.docx
│   ├── Quy_dinh_ve_khieu_nai_8-1-2026.png
│   ├── quy_trinh_dat_hang.pdf
│   ├── Quy_trinh_van_hanh.docx
│   ├── SOP_kho_10-4-2026.docx
│   ├── SOP_kho_10-4-2026.png
│   ├── Trach_nhiem_cong_ty_trong_giao_dich.docx
│   └── Truong_hop_hang_den_tre.docx
├── scripts/
│   ├── assessment_AI_backtest.py
│   ├── ingest_inventory.py
│   ├── ingest_policy.py
│   ├── mae_mape_analysis.py
│   ├── run_hybrid_csv.py
│   └── score_reorder_need.py
├── venv/                          (Python virtual environment — bỏ qua)
├── docker-compose.yml
├── Dockerfile
├── Dockerfile.php
├── HYBRID_README.md
├── init.sql
├── init_data.sql/
└── requirements.txt
```

---

## 📁 app/
> PHP Application Layer — Repositories, Services, Contracts, Helpers

```
app/
├── AI/
│   ├── AiClient.php
│   └── AiResponseParser.php
├── Contracts/
│   ├── AuthRepositoryInterface.php
│   ├── CategoryRepositoryInterface.php
│   ├── DashboardRepositoryInterface.php
│   ├── InboundRepositoryInterface.php
│   ├── InventoryRepositoryInterface.php
│   ├── OutboundRepositoryInterface.php
│   ├── ProductRepositoryInterface.php
│   ├── ReportRepositoryInterface.php
│   ├── SettingRepositoryInterface.php
│   └── SupplierRepositoryInterface.php
├── Core/
│   └── Response.php
├── Helpers/
│   ├── AddressHelper.php
│   ├── AppTime.php
│   ├── Flash.php
│   ├── Formatter.php
│   └── Validator.php
├── Repositories/
│   ├── AnalyticsRepository.php
│   ├── AuthRepository.php
│   ├── BaseRepository.php
│   ├── CategoryRepository.php
│   ├── DashboardRepository.php
│   ├── InboundRepository.php
│   ├── InventoryRepository.php
│   ├── OutboundRepository.php
│   ├── ProductRepository.php
│   ├── ReportRepository.php
│   ├── SettingRepository.php
│   └── SupplierRepository.php
└── Services/
    ├── AuthService.php
    ├── CategoryService.php
    ├── CsrfService.php
    ├── DashboardService.php
    ├── ExportService.php
    ├── InboundService.php
    ├── InventoryService.php
    ├── OutboundService.php
    ├── ProductService.php
    ├── ReportService.php
    ├── SettingService.php
    └── SupplierService.php
```

---

## 📁 config/
```
config/
├── auth.php
└── db.php
```

---

## 📁 css/
```
css/
├── ai.css
├── auth.css
├── dashboard.css
├── inbound.css
├── inbound-create.css
├── inventory.css
├── layout.css
├── outbound.css
├── outbound-create.css
├── products.css
├── report.css
├── settings.css
├── supplier-detail.css
├── suppliers.css
└── theme.css
```

---

## 📁 data/
> Dữ liệu thô, dữ liệu đã xử lý và scripts ETL

```
data/
├── don_hang_theo_ngay/            (CSV đơn hàng theo ngày — 2023-01 đến 2025-11)
│   └── don_hang_YYYY-MM-DD.csv   (nhiều file)
├── processed/
│   ├── doi_soat_report/           (báo cáo đối soát tháng — 2023-01 đến 2025-11)
│   │   └── doi_soat_report_YYYY-MM.csv
│   ├── inventory_batches.csv
│   ├── products_price_update.csv
│   ├── stock_inbound_items.csv
│   ├── stock_inbounds.csv
│   ├── stock_outbound_items.csv
│   └── stock_outbounds.csv
├── generate_inbound.py
├── gpt4o_logistics_dataset_v2.csv
├── insert_db.log
├── insert_db.py
├── preprocess.log
├── preprocess.py
└── price_table.py
```

---

## 📁 db/
> Database migrations

```
db/
└── migrations/
    ├── 2026-10-05_expiry_tracking_by_category.sql
    ├── 2026-10-05_outbound_single_supplier.sql
    └── 2026-10-05_security_hardening.sql
```

---

## 📁 db_export/
> Export CSV của toàn bộ bảng trong database

```
db_export/
├── ai_cache.csv
├── ai_email_drafts.csv
├── alerts.csv
├── categories.csv
├── companies.csv
├── configurations.csv
├── forecasts.csv
├── inventory_batches.csv
├── inventory_chunks.csv
├── inventory_history.csv
├── login_attempts.csv
├── policy_chunks.csv
├── product_price_history.csv
├── products.csv
├── rag_chat_history.csv
├── replenishment_recommendations.csv
├── risk_analysis.csv
├── stock_inbound_items.csv
├── stock_inbound_items_export.csv
├── stock_inbound_items_export_old.csv
├── stock_inbound_items_export_old2.csv
├── stock_inbounds.csv
├── stock_inbounds_export.csv
├── stock_inbounds_export_old.csv
├── stock_inbounds_export_old2.csv
├── stock_outbound_items.csv
├── stock_outbound_items_export.csv
├── stock_outbound_items_export_old.csv
├── stock_outbound_items_export_old1.csv
├── stock_outbound_items_export_old2.csv
├── stock_outbounds.csv
├── stock_outbounds_export.csv
├── stock_outbounds_export_old.csv
├── stock_outbounds_export_old1.csv
├── stock_outbounds_export_old2.csv
├── suppliers.csv
├── trends.csv
├── units.csv
└── users.csv
```

---

## 📁 img/
```
img/
├── bgr-kho-bai.png
├── logo.jpg
├── Logo.png
├── logo1.png
└── test.jpg
```

---

## 📁 js/
```
js/
├── ai-dashboard.js
├── inbound.js
├── inventory.js
├── outbound.js
├── products.js
├── report.js
├── settings.js
├── supplier-detail.js
├── suppliers.js
├── utils.js
└── vn-address.json
```

---

## 📁 layout/
```
layout/
├── footer.php
├── header.php
└── topbar.php
```

---

## 📁 output/
> File Excel kết quả đánh giá (AI vs non-AI forecast)

```
output/
├── assessment_AI_2025.xlsx
├── assessment_AI_2025_2109.xlsx
├── assessment_AI_2025_old.xlsx
├── assessment_AI_2025_old1.xlsx
├── assessment_AI_2025_old2.xlsx
├── assessment_AI_2025_old3.xlsx
├── assessment_AI_2025_old4.xlsx
├── assessment_notAI_2023.xlsx
├── assessment_notAI_2023_old.xlsx
├── assessment_notAI_2023_old1.xlsx
├── assessment_notAI_2023_old2.xlsx
├── assessment_notAI_2024.xlsx
├── assessment_notAI_2024_old.xlsx
├── assessment_notAI_2024_old1.xlsx
├── assessment_notAI_2024_old2.xlsx
├── assessment_notAI_2025.xlsx
├── assessment_notAI_2025_old.xlsx
├── assessment_notAI_2025_old1.xlsx
├── assessment_notAI_2025_old2.xlsx
├── assessment_notAI_2026.xlsx
├── mae_mape_analysis.xlsx
└── reorder_cycle_analysis.xlsx
```

---

## 📁 pages/
> PHP Pages (Views + Controllers)

```
pages/
├── ai/
│   ├── dashboard.php
│   └── process.php
├── auth/
│   ├── logout.php
│   ├── process.php
│   └── SignIn.php
├── dashboard/
│   ├── index.php
│   └── process.php
├── export/
│   └── Export.php
├── inbound/
│   ├── create.php
│   ├── index.php
│   ├── ocr.php
│   ├── ocr_process.php
│   └── process.php
├── inventory/
│   ├── history.php
│   ├── index.php
│   └── process.php
├── outbound/
│   ├── create.php
│   ├── get_batches.php
│   ├── index.php
│   └── process.php
├── products/
│   ├── index.php
│   └── process.php
├── reports/
│   ├── inventory.php
│   ├── overview.php
│   ├── process.php
│   ├── products.php
│   └── suppliers.php
├── settings/
│   ├── _tabs.php
│   ├── alerts.php
│   ├── configurations.php
│   ├── index.php
│   ├── process.php
│   ├── profile.php
│   └── users.php
└── suppliers/
    ├── detail.php
    ├── index.php
    └── process.php
```

---

## 📁 pgvector-master/
> Thư viện pgvector (PostgreSQL vector extension) — source code C

```
pgvector-master/
├── .github/
│   └── workflows/
│       └── build.yml
├── sql/                           (migration scripts v0.1.0 → v0.8.2)
│   ├── vector.sql
│   └── vector--x.x.x--x.x.x.sql  (nhiều file migration)
├── src/                           (source code C)
│   ├── bitutils.c / .h
│   ├── bitvec.c / .h
│   ├── halfutils.c / .h
│   ├── halfvec.c / .h
│   ├── hnsw.c / .h
│   ├── hnswbuild.c
│   ├── hnswinsert.c
│   ├── hnswscan.c
│   ├── hnswutils.c
│   ├── hnswvacuum.c
│   ├── ivfbuild.c
│   ├── ivfflat.c / .h
│   ├── ivfinsert.c
│   ├── ivfkmeans.c
│   ├── ivfscan.c
│   ├── ivfutils.c
│   ├── ivfvacuum.c
│   ├── sparsevec.c / .h
│   └── vector.c / .h
├── test/
│   ├── expected/                  (expected output .out files)
│   ├── perl/PostgreSQL/Test/      (Cluster.pm, Utils.pm)
│   ├── sql/                       (test SQL files)
│   └── t/                         (001_*.pl → 045_*.pl test scripts)
├── .editorconfig
├── .gitignore
├── CHANGELOG.md
├── Dockerfile
├── LICENSE
├── Makefile
├── Makefile.win
├── META.json
├── README.md
└── vector.control
```

---

## 📁 vendor/
> Composer dependencies (PHP)

```
vendor/
├── composer/
│   ├── autoload_classmap.php
│   ├── autoload_namespaces.php
│   ├── autoload_psr4.php
│   ├── autoload_real.php
│   ├── autoload_static.php
│   ├── ClassLoader.php
│   ├── installed.json
│   ├── installed.php
│   ├── InstalledVersions.php
│   └── LICENSE
└── autoload.php
```

---

## 📄 Files tại Root

| File | Mô tả |
|------|-------|
| `.gitignore` | Git ignore rules |
| `2026-10-05_backfill_inbound_total.sql` | Migration SQL |
| `2026-10-05_expiry_tracking_by_category.sql` | Migration SQL |
| `2026-10-05_fix_entity_origin_and_outbound_price.sql` | Migration SQL |
| `2026-10-05_outbound_single_supplier.sql` | Migration SQL |
| `2026-10-05_rebuild_stock_and_fix_logic.sql` | Migration SQL |
| `2026-10-05_security_hardening.sql` | Migration SQL |
| `AI_INVENTORY_ANALYSIS.md` | Phân tích AI inventory |
| `assessment_notAI.py` | Script đánh giá dự báo non-AI |
| `assessment_results_all.json` | Kết quả đánh giá JSON |
| `backup_2026-10-05.sql` | Database backup |
| `before_fix.dump` | Database dump trước khi sửa |
| `before_security.dump` | Database dump trước security fix |
| `categories.csv` | Dữ liệu categories |
| `code_fixes.patch` | Patch file |
| `composer.json` | PHP Composer config |
| `composer.lock` | Composer lockfile |
| `composer.phar` | Composer binary |
| `docker` | Docker file/script |
| `fix.patch` | Patch file |
| `fix_9.patch` | Patch file |
| `full_schema.sql` | Full database schema |
| `load_all_via_staging.sql` | SQL load script |
| `load_inventory.sql` | SQL load script |
| `load_new_data.sql` | SQL load script |
| `local-changes.diff` | Diff file |
| `products.csv` | Dữ liệu sản phẩm |
| `products_old.csv` | Dữ liệu sản phẩm cũ |
| `products_old1.csv` | Dữ liệu sản phẩm cũ v1 |
| `productsprocess.php` | PHP script xử lý sản phẩm |
| `schema.sql` | Database schema |
| `security_fixes.patch` | Security patch |
| `smartware.sql` | Database SQL chính |
| `stock_inbound_items_export.csv` | Export nhập kho items |
| `stock_inbounds_export.csv` | Export phiếu nhập kho |
| `stock_outbound_items_export.csv` | Export xuất kho items |
| `stock_outbounds_export.csv` | Export phiếu xuất kho |
| `suppliers.csv` | Dữ liệu nhà cung cấp |
| `test_guard.py` | Test SQL guard script |
| `FOLDER_STRUCTURE.md` | File này |
