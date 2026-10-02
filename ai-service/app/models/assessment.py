from sqlalchemy import Column, Integer, String, Date, Numeric, ForeignKey, TIMESTAMP
from sqlalchemy.orm import relationship
from app.db.postgresql import Base

class WarehouseAssessment(Base):
    __tablename__ = "warehouse_assessment"
    
    id = Column(Integer, primary_key=True, index=True)
    product_id = Column(Integer, ForeignKey("products.id"), nullable=False)
    supplier_id = Column(Integer, ForeignKey("suppliers.id"), nullable=False)
    assessment_date = Column(Date, nullable=False)
    current_min_stock = Column(Integer)
    current_max_stock = Column(Integer)
    recommended_min = Column(Integer)
    recommended_max = Column(Integer)
    total_export_last_90d = Column(Integer)
    avg_monthly_export = Column(Numeric(10,2))
    avg_daily_export = Column(Numeric(10,2))
    assessment_label = Column(String)
    model_version = Column(String(50))
    created_at = Column(TIMESTAMP, server_default="CURRENT_TIMESTAMP")
    updated_at = Column(TIMESTAMP, server_default="CURRENT_TIMESTAMP")