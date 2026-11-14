# BƯỚC 1 — chỉ xuất THUỘC TÍNH CÓ SẴN trong hệ thống (không tính toán gì). Chạy tại C:\xampp\htdocs\smartware
#   powershell -ExecutionPolicy Bypass -File .\step1_export_raw.ps1
# Kết quả trong .\raw\ :
#   noai_raw.csv                    products + suppliers.lead_time_days   (cột nguyên gốc của 2 bảng; min_stock/max_stock = ngưỡng No-AI nhập tay)
#   stock_inbound_items_export.csv  giao dịch nhập kèm mfg_date, exp_date theo lô (dùng chung cho No-AI và AI; step4_evaluate_v2 dùng hạn dùng để mô phỏng FEFO)
#   stock_outbound_items_export.csv giao dịch xuất (dùng chung cho No-AI và AI)
# (ai_raw.csv KHÔNG xuất từ DB: nó là đầu ra của mô hình, tạo ở bước 2 bằng step2_run_ai.py)
$ErrorActionPreference = "Stop"; $C = "smartware_postgres"; $OUT = ".\raw"
New-Item -ItemType Directory -Force $OUT | Out-Null
function Export-Q($name, $sql) {
  $sql = ($sql -replace "\s+", " ").Trim()
  docker exec $C psql -U postgres -d smartware -c "\copy ($sql) TO '/tmp/$name.csv' CSV HEADER ENCODING 'UTF8'"
  docker cp "${C}:/tmp/$name.csv" "$OUT\$name.csv"
}
Export-Q "noai_raw" "
  SELECT p.id, p.sku, p.name, p.category_id, p.supplier_id, p.unit, p.price, p.cost_price, p.min_stock, p.max_stock, p.status,
         s.lead_time_days
  FROM public.products p JOIN public.suppliers s ON s.id = p.supplier_id
  WHERE p.deleted_at IS NULL ORDER BY p.id"
Export-Q "stock_inbound_items_export" "
  SELECT i.id, i.inbound_id, i.product_id, i.batch_no, i.mfg_date, i.exp_date, i.quantity, i.unit_price,
         to_char(i.created,'DD/MM/YYYY HH24:MI:SS') AS created
  FROM public.stock_inbound_items i JOIN public.stock_inbounds b ON b.id = i.inbound_id
  WHERE b.status='completed' AND b.deleted_at IS NULL AND i.created < '2026-01-01' ORDER BY i.id"
Export-Q "stock_outbound_items_export" "
  SELECT o.id, o.outbound_id, o.product_id, o.batch_no, o.quantity,
         to_char(o.created,'DD/MM/YYYY HH24:MI:SS') AS created
  FROM public.stock_outbound_items o JOIN public.stock_outbounds s ON s.id = o.outbound_id
  WHERE s.status='completed' AND s.deleted_at IS NULL AND o.created < '2026-01-01' ORDER BY o.id"
Get-ChildItem $OUT | Select-Object Name, @{n='KB';e={[math]::Round($_.Length/1KB)}}
