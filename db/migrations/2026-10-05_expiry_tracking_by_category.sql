-- Hạn dùng chỉ bắt buộc với danh mục có need_expiry_tracking = true.
-- Chạy được nhiều lần (idempotent). Kiểm tra trên bản sao dữ liệu trước khi chạy ở môi trường thật.

-- Lô và dòng phiếu nhập của danh mục không theo dõi hạn sẽ có exp_date = NULL.
-- inventory_batches.exp_date đã có NULL trong dữ liệu hiện tại; dòng dưới chỉ để chắc chắn cho cả bảng dòng phiếu.
ALTER TABLE inventory_batches   ALTER COLUMN exp_date DROP NOT NULL;
ALTER TABLE stock_inbound_items ALTER COLUMN exp_date DROP NOT NULL;

-- Danh mục luôn có cờ rõ ràng (mặc định: có theo dõi hạn, để không bỏ sót hàng có hạn).
ALTER TABLE categories ALTER COLUMN need_expiry_tracking SET DEFAULT TRUE;
UPDATE categories SET need_expiry_tracking = TRUE WHERE need_expiry_tracking IS NULL;
ALTER TABLE categories ALTER COLUMN need_expiry_tracking SET NOT NULL;
