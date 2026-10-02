# app/services/risk_service.py
import json
from sqlalchemy.orm import Session
from sqlalchemy import text
from datetime import datetime
from app.services.gpt_service import _post_chat
from app.config import settings

def calculate_risk_score(
    current_stock:       int,
    min_stock:           int,
    max_stock:           int,
    avg_daily_out:       float,
    estimated_days_left: float | None,
    days_to_nearest_exp: int | None,
    sales_history:       list[int],
) -> tuple[float, list[str]]:
    """
    Trả về (risk_score 0-100, danh sách yếu tố rủi ro).
    Tính hoàn toàn offline, không gọi LLM.
    """
    score = 0.0
    factors = []

    if current_stock == 0:
        score += 30
        factors.append("Hết hàng hoàn toàn")
    elif current_stock <= min_stock:
        ratio = current_stock / max(min_stock, 1)
        score += 30 * (1 - ratio)
        factors.append(f"Tồn kho ({current_stock}) dưới mức tối thiểu ({min_stock})")
    elif estimated_days_left is not None and estimated_days_left <= 7:
        score += 25
        factors.append(f"Chỉ còn ~{estimated_days_left:.0f} ngày tồn kho")
    elif estimated_days_left is not None and estimated_days_left <= 14:
        score += 15
        factors.append(f"Sắp hết hàng trong ~{estimated_days_left:.0f} ngày")

    if days_to_nearest_exp is not None:
        if days_to_nearest_exp <= 0:
            score += 25
            factors.append("Có lô hàng đã hết hạn")
        elif days_to_nearest_exp <= 7:
            score += 22
            factors.append(f"Lô hàng hết hạn sau {days_to_nearest_exp} ngày")
        elif days_to_nearest_exp <= 30:
            score += 15
            factors.append(f"Lô hàng hết hạn sau {days_to_nearest_exp} ngày")
        elif days_to_nearest_exp <= 60:
            score += 7
            factors.append(f"Lô hàng hết hạn sau {days_to_nearest_exp} ngày")

    if max_stock > 0 and current_stock > max_stock:
        ratio = min((current_stock - max_stock) / max(max_stock, 1), 1.0)
        score += 20 * ratio
        factors.append(f"Tồn kho ({current_stock}) vượt mức tối đa ({max_stock})")

    if len(sales_history) >= 3:
        import statistics
        try:
            mean = statistics.mean(sales_history)
            if mean > 0:
                cv = statistics.stdev(sales_history) / mean
                if cv > 0.8:
                    score += 15
                    factors.append("Nhu cầu biến động rất mạnh")
                elif cv > 0.5:
                    score += 8
                    factors.append("Nhu cầu biến động khá cao")
        except Exception:
            pass

    if len(sales_history) >= 4:
        recent  = sum(sales_history[-2:]) / 2
        older   = sum(sales_history[:2])  / 2
        if older > 0 and recent < older * 0.7:
            score += 10
            factors.append("Xu hướng tiêu thụ giảm rõ rệt")
        elif older > 0 and recent < older * 0.85:
            score += 5

    score = min(100.0, round(score, 2))

    if not factors:
        factors.append("Tồn kho ổn định, không có rủi ro đáng kể")

    return score, factors


def score_to_level(score: float) -> str:
    if score >= 35:
        return "high"
    elif score >= 20:
        return "medium"
    return "low"

async def generate_risk_description(
    product_name: str,
    risk_level:   str,
    risk_score:   float,
    factors:      list[str],
    current_stock: int,
    min_stock:    int,
    estimated_days_left: float | None,
) -> tuple[str, int]:
    """LM Studio viết 2-3 câu giải thích rủi ro bằng tiếng Việt tự nhiên."""

    factors_text = "\n".join(f"- {f}" for f in factors)
    prompt = f"""Phân tích rủi ro tồn kho sau và viết 2-3 câu giải thích ngắn gọn cho quản lý:

Sản phẩm: {product_name}
Mức rủi ro: {risk_level.upper()} (điểm: {risk_score:.0f}/100)
Tồn kho hiện tại: {current_stock} (tối thiểu: {min_stock})
{f"Số ngày tồn kho ước tính: {estimated_days_left:.0f} ngày" if estimated_days_left else ""}
Các yếu tố rủi ro:
{factors_text}

Viết 2-3 câu tự nhiên, rõ ràng, hướng dẫn hành động cụ thể. Không cần JSON."""

    try:
        content, tokens = await _post_chat(
            base_url    = settings.lm_base_url,
            api_key     = settings.lm_api_key,
            model       = settings.lm_model,
            messages    = [
                {"role": "system", "content": "Bạn là chuyên gia kho hàng, viết đánh giá rủi ro ngắn gọn bằng tiếng Việt."},
                {"role": "user",   "content": prompt},
            ],
            max_tokens  = 200,
            temperature = 0.2,
            timeout     = 30.0,
        )
        return content.strip(), tokens
    except Exception:
        desc = f"Sản phẩm {product_name} có mức rủi ro {risk_level} với điểm {risk_score:.0f}/100. "
        desc += f"Nguyên nhân: {factors[0]}. Cần theo dõi và xử lý kịp thời."
        return desc, 0

def save_risk(
    db: Session,
    supplier_id: int,
    product_id: int,
    risk_level: str,
    risk_score: float,
    description: str,
) -> None:
    db.execute(text("""
        INSERT INTO public.risk_analysis
            (supplier_id, product_id, risk_level, risk_score, description)
        VALUES
            (:supplier_id, :product_id, :risk_level, :risk_score, :description)
    """), {
        "supplier_id": supplier_id,
        "product_id": product_id,
        "risk_level": risk_level,
        "risk_score": risk_score,
        "description": description,
    })
    db.commit()

async def analyze_risk(
    product_id: int,
    product_name: str,
    supplier_id: int,
    current_stock: int,
    min_stock: int,
    max_stock: int,
    avg_daily_out: float,
    estimated_days_left: float | None,
    days_to_nearest_exp: int | None,
    sales_history: list[int],
    db: Session,
) -> tuple[dict, int]:
    """
    Full pipeline: tính score → LM Studio → lưu DB.
    Returns (result_dict, tokens_used).
    """
    risk_score, factors = calculate_risk_score(
        current_stock, min_stock, max_stock,
        avg_daily_out, estimated_days_left,
        days_to_nearest_exp, sales_history,
    )
    risk_level = score_to_level(risk_score)

    description, tokens = await generate_risk_description(
        product_name, risk_level, risk_score,
        factors, current_stock, min_stock, estimated_days_left,
    )

    save_risk(db, supplier_id, product_id, risk_level, risk_score, description)

    return {
        "risk_level":  risk_level,
        "risk_score":  risk_score,
        "description": description,
        "factors":     factors,
    }, tokens