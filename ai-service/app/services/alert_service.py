# app/services/alert_service.py
import logging
from datetime import datetime, timedelta
from sqlalchemy.orm import Session
from sqlalchemy import text

logger = logging.getLogger(__name__)

async def generate_alerts(db: Session):
    """Tạo alert tự động dựa trên trạng thái hiện tại."""
    alerts_created = 0

    # 1. Low stock: tồn kho < min_stock
    low_stock_sql = text("""
        INSERT INTO alerts (product_id, supplier_id, type, severity, message, created)
        SELECT 
            p.id,
            p.supplier_id,
            'low_stock',
            CASE 
                WHEN (p.min_stock - COALESCE(ib.total_stock, 0)) > p.min_stock * 0.5 THEN 'high'
                ELSE 'medium'
            END,
            CONCAT('Tồn kho sản phẩm "', p.name, '" chỉ còn ', COALESCE(ib.total_stock, 0), ' đơn vị, dưới ngưỡng tối thiểu (', p.min_stock, ').'),
            public.app_now()
        FROM products p
        LEFT JOIN (
            SELECT product_id, SUM(quantity) AS total_stock
            FROM inventory_batches
            GROUP BY product_id
        ) ib ON ib.product_id = p.id
        WHERE p.deleted_at IS NULL
          AND COALESCE(ib.total_stock, 0) < p.min_stock
          AND p.min_stock > 0
        ON CONFLICT DO NOTHING
    """)
    db.execute(low_stock_sql)
    alerts_created += db.rowcount

    # 2. Overstock: tồn kho > max_stock
    overstock_sql = text("""
        INSERT INTO alerts (product_id, supplier_id, type, severity, message, created)
        SELECT 
            p.id,
            p.supplier_id,
            'overstock',
            'medium',
            CONCAT('Tồn kho sản phẩm "', p.name, '" là ', COALESCE(ib.total_stock, 0), ' đơn vị, vượt ngưỡng tối đa (', p.max_stock, ').'),
            public.app_now()
        FROM products p
        LEFT JOIN (
            SELECT product_id, SUM(quantity) AS total_stock
            FROM inventory_batches
            GROUP BY product_id
        ) ib ON ib.product_id = p.id
        WHERE p.deleted_at IS NULL
          AND COALESCE(ib.total_stock, 0) > p.max_stock
          AND p.max_stock > 0
        ON CONFLICT DO NOTHING
    """)
    db.execute(overstock_sql)
    alerts_created += db.rowcount

    # 3. Near expiry: exp_date < 30 ngày
    near_expiry_sql = text("""
        INSERT INTO alerts (product_id, supplier_id, type, severity, message, created)
        SELECT 
            ib.product_id,
            ib.supplier_id,
            'near_expiry',
            CASE 
                WHEN ib.exp_date - public.app_today() < 7 THEN 'high'
                ELSE 'medium'
            END,
            CONCAT('Lô hàng số ', ib.batch_no, ' của sản phẩm "', p.name, '" sẽ hết hạn vào ngày ', ib.exp_date, ' (còn ', ib.exp_date - public.app_today(), ' ngày).'),
            public.app_now()
        FROM inventory_batches ib
        JOIN products p ON p.id = ib.product_id
        WHERE ib.exp_date BETWEEN public.app_today() AND public.app_today() + INTERVAL '30 days'
          AND p.deleted_at IS NULL
        ON CONFLICT DO NOTHING
    """)
    db.execute(near_expiry_sql)
    alerts_created += db.rowcount

    # 4. Slow movement: không xuất trong 90 ngày
    slow_movement_sql = text("""
        INSERT INTO alerts (product_id, supplier_id, type, severity, message, created)
        SELECT 
            p.id,
            p.supplier_id,
            'slow_movement',
            'low',
            CONCAT('Sản phẩm "', p.name, '" không có giao dịch xuất trong 90 ngày qua.'),
            public.app_now()
        FROM products p
        LEFT JOIN (
            SELECT soi.product_id, MAX(so.created) AS last_export
            FROM stock_outbound_items soi
            JOIN stock_outbounds so ON so.id = soi.outbound_id
            WHERE so.deleted_at IS NULL
            GROUP BY soi.product_id
        ) le ON le.product_id = p.id
        WHERE p.deleted_at IS NULL
          AND (le.last_export IS NULL OR le.last_export < public.app_now() - INTERVAL '90 days')
        ON CONFLICT DO NOTHING
    """)
    db.execute(slow_movement_sql)
    alerts_created += db.rowcount

    # 5. Overdue storage: lưu trữ > 180 ngày (tính từ ngày tạo batch)
    overdue_storage_sql = text("""
        INSERT INTO alerts (product_id, supplier_id, type, severity, message, created)
        SELECT 
            ib.product_id,
            ib.supplier_id,
            'overdue_storage',
            'low',
            CONCAT('Lô hàng số ', ib.batch_no, ' của sản phẩm "', p.name, '" đã lưu trữ ', 
                   EXTRACT(DAY FROM (public.app_now() - ib.created)), ' ngày (vượt 180 ngày).'),
            public.app_now()
        FROM inventory_batches ib
        JOIN products p ON p.id = ib.product_id
        WHERE ib.created < public.app_now() - INTERVAL '180 days'
          AND p.deleted_at IS NULL
        ON CONFLICT DO NOTHING
    """)
    db.execute(overdue_storage_sql)
    alerts_created += db.rowcount

    db.commit()
    return {"alerts_created": alerts_created}