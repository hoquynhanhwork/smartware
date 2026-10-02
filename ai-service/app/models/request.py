from pydantic import BaseModel, Field
from typing import List, Optional

# ── Dùng chung cho /predict và /forecast ──────────────────────
class InventoryItem(BaseModel):
    product_id:          int
    product_name:        str
    current_stock:       int
    min_stock:           int
    max_stock:           int = 0
    sales_history:       List[int]           # 6 tháng gần nhất, cũ → mới
    avg_daily_out:       float = 0.0
    estimated_days_left: Optional[float] = None
    nearest_exp_date:    Optional[str]  = None
    supplier_id:         Optional[int] = None  # thêm để rõ ràng (có thể không bắt buộc)

# ── /forecast/advanced — Prophet từ DB ────────────────────────
class ForecastAdvancedRequest(BaseModel):
    product_id:    int
    product_name:  str
    supplier_id:   int = 1        # đã đổi từ company_id
    current_stock: int
    min_stock:     int
    max_stock:     int = 0
    train_end_date: Optional[str] = None  # "YYYY-MM-DD" — cắt train khi backtest (vd "2025-01-01")

# ── /risks — phân tích rủi ro tồn kho ────────────────────────
class RiskRequest(BaseModel):
    product_id:          int
    product_name:        str
    supplier_id:         int = 1      # đã đổi
    current_stock:       int
    min_stock:           int
    max_stock:           int = 0
    avg_daily_out:       float = 0.0
    estimated_days_left: Optional[float] = None
    nearest_exp_date:    Optional[str]  = None
    days_to_nearest_exp: Optional[int]  = None
    sales_history:       List[int] = Field(default_factory=list)

# ── /trends — phân tích xu hướng tiêu thụ ────────────────────
class TrendRequest(BaseModel):
    product_id:   int
    product_name: str
    supplier_id:  int = 1       # đã đổi

# ── /replenishment — đề xuất bổ sung hàng ────────────────────
class ReplenishmentRequest(BaseModel):
    product_id:          int
    product_name:        str
    supplier_id:         int = 1      # đã đổi
    current_stock:       int
    min_stock:           int
    max_stock:           int = 0
    avg_daily_out:       float = 0.0
    estimated_days_left: Optional[float] = None
    nearest_exp_date:    Optional[str]  = None
    cost_price:          Optional[float] = None
    preferred_supplier_id: Optional[int] = None

# ── /analyze/batch — phân tích toàn bộ kho ───────────────────
class BatchAnalyzeRequest(BaseModel):
    supplier_id: Optional[int] = None   # None = tất cả
    limit: int = Field(default=50, ge=1, le=1000)
    stock_status: Optional[str] = None

# ── /chat — hỏi đáp về kho ───────────────────────────────────
class ChatRequest(BaseModel):
    message:    str
    context:    Optional[str] = None
    supplier_id: int = 1         # đã đổi (có thể dùng cho filter nếu cần)

class ExplainRequest(BaseModel):
    product_id:    int
    product_name:  str
    quantity:      float
    min_stock:     float
    supplier_name: str