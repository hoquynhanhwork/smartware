-- Mỗi phiếu xuất thuộc đúng 1 nhà cung cấp (stock_outbounds.supplier_id).
-- Chạy được nhiều lần (idempotent). Kiểm tra trên bản sao dữ liệu trước khi chạy ở môi trường thật.

-- 1) Phiếu tạo từ web trước đây không ghi supplier_id (NULL). Điền lại từ sản phẩm trong phiếu
--    khi tất cả sản phẩm cùng một NCC.
UPDATE stock_outbounds so
SET    supplier_id = s.supplier_id
FROM  (
    SELECT soi.outbound_id, MIN(p.supplier_id) AS supplier_id
    FROM   stock_outbound_items soi
    JOIN   products p ON p.id = soi.product_id
    GROUP  BY soi.outbound_id
    HAVING COUNT(DISTINCT p.supplier_id) = 1
       AND COUNT(*) FILTER (WHERE p.supplier_id IS NULL) = 0
) s
WHERE  so.id = s.outbound_id
  AND  so.supplier_id IS NULL;

-- 2) Chỉ đặt NOT NULL khi không còn phiếu nào thiếu NCC; nếu còn thì báo để xử lý tay.
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM stock_outbounds WHERE supplier_id IS NULL) THEN
        RAISE NOTICE 'Còn % phiếu xuất chưa xác định được NCC (supplier_id NULL); chưa đặt NOT NULL.',
            (SELECT COUNT(*) FROM stock_outbounds WHERE supplier_id IS NULL);
    ELSE
        ALTER TABLE stock_outbounds ALTER COLUMN supplier_id SET NOT NULL;
    END IF;
END $$;

-- Các view ai_ro.stock_outbounds / ai_ro.stock_outbound_items lọc theo stock_outbounds.supplier_id
-- nên giữ nguyên: phiếu mới nay luôn có supplier_id nên không còn "biến mất" khỏi text-to-SQL.
