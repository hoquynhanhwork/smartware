from pydantic import BaseModel
from typing import Optional, List

# ── /predict ──────────────────────────────────────────────────
class PredictionResponse(BaseModel):
    product_id:       int
    product_name:     str
    predicted_demand: int
    reorder_quantity: Optional[int] = None
    warning:          Optional[str] = None
    recommendation:   Optional[str] = None
    tokens_used:      int  = 0
    cached:           bool = False

# ── /forecast ─────────────────────────────────────────────────
class ForecastResponse(BaseModel):
    product_id:      int
    product_name:    str
    next_month:      int
    next_2_months:   int
    next_3_months:   int
    suggested_order: int
    trend:           str
    warning:         Optional[str] = None
    recommendation:  str
    tokens_used:     int  = 0
    cached:          bool = False

# ── /forecast/advanced ────────────────────────────────────────
class ForecastAdvancedResponse(BaseModel):
    product_id:      int
    product_name:    str
    next_month:      int
    next_2_months:   int
    next_3_months:   int
    suggested_order: int
    trend:           str
    warning:         Optional[str] = None
    explanation:     str
    months_trained:  int
    confidence:      float
    # ── MỚI: output riêng cho ROP / Safety Stock theo Z x σ x √LT ──
    rop:                Optional[int]   = None
    safety_stock:       Optional[int]   = None
    max_ai:             Optional[int]   = None
    demand_std_dev:     Optional[float] = None
    lead_time_days:     Optional[int]   = None
    avg_daily_demand:   Optional[float] = None
    growth_pct:         Optional[float] = None
    frequency_pct:      Optional[float] = None
    tokens_used:     int  = 0
    cached:          bool = False

# ── /risks ────────────────────────────────────────────────────
class RiskResponse(BaseModel):
    product_id:   int
    product_name: str
    risk_level:   str
    risk_score:   float
    description:  str
    factors:      List[str]
    tokens_used:  int  = 0
    cached:       bool = False

# ── /trends ───────────────────────────────────────────────────
class TrendResponse(BaseModel):
    product_id:       int
    product_name:     str
    direction:        str
    pct_change:       float
    avg_monthly:      float
    peak_month:       Optional[int] = None
    low_month:        Optional[int] = None
    months_analyzed:  int
    insight:          str
    tokens_used:      int  = 0
    cached:           bool = False

# ── /replenishment ────────────────────────────────────────────
class ReplenishmentResponse(BaseModel):
    product_id:       int
    product_name:     str
    priority:         str
    suggested_quantity: int
    urgency_days:     int
    estimated_cost:   Optional[float] = None
    reason:           str
    # ── MỚI: output riêng cho ROP / Safety Stock ──
    rop:              Optional[int] = None
    safety_stock:     Optional[int] = None
    tokens_used:      int  = 0
    cached:           bool = False

# ── /analyze/batch ────────────────────────────────────────────
class BatchProductResult(BaseModel):
    product_id:   int
    product_name: str
    stock_status: str
    risk_level:   str
    risk_score:   float
    priority:     str
    suggested_quantity: int
    warning:      Optional[str] = None


class BatchAnalyzeResponse(BaseModel):
    supplier_id: Optional[int] = None  # Cho phép None
    total_products: int
    analyzed: int
    summary: dict
    results: List[BatchProductResult]
    tokens_used: int = 0
    cached: bool = False

# ── /chat ─────────────────────────────────────────────────────
class ChatResponse(BaseModel):
    answer:      str
    tokens_used: int