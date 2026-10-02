import json
import httpx
from app.config import settings
async def _post_chat(
    base_url: str,
    api_key: str,
    model: str,
    messages: list[dict],
    max_tokens: int,
    temperature: float = 0.2,
    json_mode: bool = False,
    timeout: float = 60.0,
) -> tuple[str, int]:
    """
    Gọi OpenAI-compatible /v1/chat/completions với UTF-8 đúng chuẩn.
    Trả về (content_text, total_tokens).
    """
    url = base_url.rstrip("/") + "/chat/completions"

    payload: dict = {
        "model": model,
        "messages": messages,
        "max_tokens": max_tokens,
        "temperature": temperature,
    }
    if json_mode:
        payload["response_format"] = {"type": "json_object"}

    body_bytes = json.dumps(payload, ensure_ascii=False).encode("utf-8")

    headers = {
        "Authorization": f"Bearer {api_key}",
        "Content-Type": "application/json; charset=utf-8",
    }

    async with httpx.AsyncClient(timeout=timeout) as client:
        resp = await client.post(url, content=body_bytes, headers=headers)
        resp.raise_for_status()
        data = resp.json()

    content = data["choices"][0]["message"]["content"]
    tokens  = data.get("usage", {}).get("total_tokens", 0)
    return content, tokens


def _strip_markdown_json(raw: str) -> str:
    """Xóa ```json ... ``` wrapper nếu model trả về."""
    raw = raw.strip()
    if raw.startswith("```"):
        parts = raw.split("```")
        raw = parts[1] if len(parts) > 1 else raw
        if raw.startswith("json"):
            raw = raw[4:]
    return raw.strip()

SYSTEM_PROMPT = """Bạn là chuyên gia phân tích kho hàng với 10 năm kinh nghiệm.
Nhiệm vụ: phân tích dữ liệu tồn kho và đưa ra dự báo, cảnh báo chính xác.
Luôn trả lời bằng tiếng Việt. Chỉ trả về JSON đúng format được yêu cầu."""

CHAT_SYSTEM_PROMPT = """Bạn là trợ lý AI chuyên về quản lý kho hàng, thân thiện và chuyên nghiệp.
Trả lời câu hỏi bằng ngôn ngữ tự nhiên, rõ ràng, dễ hiểu.
Quy tắc:
- Luôn dùng tiếng Việt tự nhiên, KHÔNG trả về JSON hay code
- Ngắn gọn, súc tích, đi thẳng vào vấn đề
- Nếu có dữ liệu tồn kho được cung cấp, dựa vào đó để trả lời cụ thể
- Dùng gạch đầu dòng khi liệt kê nhiều mục"""

async def predict_inventory(
    product_name: str,
    sales_history: list,
    current_stock: int,
    min_stock: int,
    max_stock: int = 0,
    avg_daily_out: float = 0,
    estimated_days_left: float = None,
    nearest_exp_date: str = None,
) -> tuple[dict, int]:
    """Dự đoán tồn kho — gọi LM Studio (dữ liệu nhạy cảm)."""
    exp_info  = f"\n    Ngày hết hạn gần nhất: {nearest_exp_date}" if nearest_exp_date else ""
    days_info = f"\n    Số ngày tồn kho ước tính: {estimated_days_left:.1f} ngày" if estimated_days_left else ""

    prompt = f"""Phân tích dữ liệu tồn kho sau và đưa ra dự báo:

    Sản phẩm: {product_name}
    Tồn kho hiện tại: {current_stock} đơn vị
    Tồn kho tối thiểu: {min_stock} đơn vị
    Tồn kho tối đa: {max_stock} đơn vị
    Xuất trung bình/ngày: {avg_daily_out} đơn vị{days_info}{exp_info}
    Doanh số 6 tháng gần nhất (tháng cũ → mới): {sales_history}

    Chỉ trả về JSON thuần túy, không giải thích, không markdown, không ```json:
    {{
        "predicted_demand": <số nguyên - dự đoán nhu cầu tháng tới>,
        "reorder_quantity": <số nguyên - đề xuất nhập thêm bao nhiêu, 0 nếu không cần>,
        "warning": <chuỗi cảnh báo cụ thể nếu có vấn đề, null nếu bình thường>,
        "recommendation": <chuỗi đề xuất hành động cụ thể>
    }}"""

    try:
        raw, tokens_used = await _post_chat(
            base_url    = settings.lm_base_url,
            api_key     = settings.lm_api_key,
            model       = settings.lm_model,
            messages    = [
                {"role": "system", "content": SYSTEM_PROMPT},
                {"role": "user",   "content": prompt},
            ],
            max_tokens  = settings.max_tokens,
            temperature = 0.2,
            timeout     = 60.0,
        )
        result = json.loads(_strip_markdown_json(raw))
        result.setdefault("predicted_demand", 0)
        result.setdefault("reorder_quantity", 0)
        result.setdefault("warning",          None)
        result.setdefault("recommendation",   "Không có đề xuất")
        return result, tokens_used
    except json.JSONDecodeError as e:
        raise ValueError(f"LM Studio trả về JSON không hợp lệ: {e}")
    except Exception as e:
        raise RuntimeError(f"Lỗi gọi LM Studio: {e}")


async def forecast_inventory(
    product_name: str,
    sales_history: list,
    current_stock: int,
    min_stock: int,
    max_stock: int = 0,
    avg_daily_out: float = 0,
    estimated_days_left: float = None,
) -> tuple[dict, int]:
    """Dự báo nhu cầu 3 tháng tới — gọi LM Studio."""
    days_info = f"\n    Số ngày tồn kho ước tính: {estimated_days_left:.1f} ngày" if estimated_days_left else ""

    prompt = f"""Dựa vào lịch sử bán hàng, hãy dự báo nhu cầu 3 tháng tới:

    Sản phẩm: {product_name}
    Tồn kho hiện tại: {current_stock} đơn vị
    Tồn kho tối thiểu: {min_stock} đơn vị
    Tồn kho tối đa: {max_stock} đơn vị
    Xuất trung bình/ngày: {avg_daily_out} đơn vị{days_info}
    Doanh số 6 tháng gần nhất (tháng cũ → mới): {sales_history}

    Chỉ trả về JSON thuần túy, không giải thích, không markdown, không ```json:
    {{
        "next_month": <số nguyên - dự báo nhu cầu tháng 1>,
        "next_2_months": <số nguyên - dự báo nhu cầu tháng 2>,
        "next_3_months": <số nguyên - dự báo nhu cầu tháng 3>,
        "suggested_order": <số nguyên - đề xuất nhập bao nhiêu ngay bây giờ>,
        "trend": <"tăng" hoặc "giảm" hoặc "ổn định">,
        "warning": <chuỗi cảnh báo nếu có, null nếu không>,
        "recommendation": <chuỗi đề xuất hành động cụ thể>
    }}"""

    try:
        raw, tokens_used = await _post_chat(
            base_url    = settings.lm_base_url,
            api_key     = settings.lm_api_key,
            model       = settings.lm_model,
            messages    = [
                {"role": "system", "content": SYSTEM_PROMPT},
                {"role": "user",   "content": prompt},
            ],
            max_tokens  = settings.max_tokens,
            temperature = 0.2,
            timeout     = 60.0,
        )
        result = json.loads(_strip_markdown_json(raw))
        result.setdefault("next_month",      0)
        result.setdefault("next_2_months",   0)
        result.setdefault("next_3_months",   0)
        result.setdefault("suggested_order", 0)
        result.setdefault("trend",           "ổn định")
        result.setdefault("warning",         None)
        result.setdefault("recommendation",  "Không có đề xuất")
        return result, tokens_used
    except json.JSONDecodeError as e:
        raise ValueError(f"LM Studio trả về JSON không hợp lệ: {e}")
    except Exception as e:
        raise RuntimeError(f"Lỗi gọi LM Studio: {e}")


async def chat_with_lm(
    message: str,
    context: str = None,
) -> tuple[str, int]:
    """Chat Q&A tồn kho — gọi LM Studio."""
    system_content = CHAT_SYSTEM_PROMPT
    if context:
        system_content += f"\n\nDữ liệu tồn kho hiện tại:\n{context}"
    try:
        return await _post_chat(
            base_url    = settings.lm_base_url,
            api_key     = settings.lm_api_key,
            model       = settings.lm_model,
            messages    = [
                {"role": "system", "content": system_content},
                {"role": "user",   "content": message},
            ],
            max_tokens  = 500,
            temperature = 0.3,
            timeout     = 60.0,
        )
    except Exception as e:
        raise RuntimeError(f"Lỗi gọi LM Studio: {e}")

async def explain_forecast(
    product_name: str,
    prophet_result: dict,
    current_stock: int,
    min_stock: int,
) -> tuple[str, int]:
    """
    LM Studio đọc kết quả Prophet → viết báo cáo tiếng Việt tự nhiên.
    Dùng LM Studio local thay GPT-4o mini — không cần API key.
    """
    prompt = f"""Dựa vào kết quả dự báo sau, viết báo cáo ngắn gọn bằng tiếng Việt tự nhiên cho quản lý kho:

Sản phẩm: {product_name}
Tồn kho hiện tại: {current_stock} (tối thiểu: {min_stock})
Kết quả dự báo Prophet:
- Tháng tới: {prophet_result.get('next_month')} đơn vị
- Tháng 2: {prophet_result.get('next_2_months')} đơn vị
- Tháng 3: {prophet_result.get('next_3_months')} đơn vị
- Xu hướng: {prophet_result.get('trend')}
- Đề xuất nhập: {prophet_result.get('suggested_order')} đơn vị

Viết 2-3 câu tự nhiên, dễ hiểu. Không cần JSON."""

    try:
        return await _post_chat(
            base_url    = settings.lm_base_url,
            api_key     = settings.lm_api_key,
            model       = settings.lm_model,
            messages    = [
                {"role": "system", "content": "Bạn là chuyên gia kho hàng, viết báo cáo ngắn gọn bằng tiếng Việt."},
                {"role": "user",   "content": prompt},
            ],
            max_tokens  = 300,
            temperature = 0.3,
            timeout     = 30.0,
        )
    except Exception as e:
        raise RuntimeError(f"Lỗi gọi LM Studio (explain): {e}")


async def call_lm_generic(
    prompt: str,
    system: str = SYSTEM_PROMPT,
    json_mode: bool = False,
) -> tuple[str, int]:
    """
    Gọi LM Studio (Qwen 14B local) dùng chung.
    (Trước đây đặt tên call_gpt_mini() và comment ghi "Gọi GPT-4o mini",
    nhưng bên trong luôn dùng settings.lm_base_url/lm_api_key/lm_model
    — tức thực chất luôn gọi LM Studio, chưa từng gọi GPT-4o-mini thật.
    Đã đổi tên cho đúng thực tế, tránh nhầm lẫn khi viết báo cáo.
    LƯU Ý: hàm này hiện KHÔNG được gọi ở đâu trong codebase — text-to-sql
    và RAG đang tự gọi thẳng _post_chat(lm_base_url,...) chứ không qua
    hàm này. Có thể là dead code, hoặc dự định dùng sau — bạn xem lại.)
    """
    try:
        return await _post_chat(
            base_url    = settings.lm_base_url,
            api_key     = settings.lm_api_key,
            model       = settings.lm_model,
            messages    = [
                {"role": "system", "content": system},
                {"role": "user",   "content": prompt},
            ],
            max_tokens  = settings.max_tokens,
            temperature = 0.2,
            json_mode   = json_mode,
            timeout     = 30.0,
        )
    except Exception as e:
        raise RuntimeError(f"Lỗi gọi LM Studio: {e}")

async def call_gpt(
    prompt: str,
    system: str = SYSTEM_PROMPT,
    json_mode: bool = False,
    images: list[str] = None,
) -> tuple[str, int]:
    """Gọi GPT-4o — OCR và tác vụ nặng cần Vision."""
    if images:
        messages_content: list = [{"type": "text", "text": prompt}]
        for img_b64 in images:
            messages_content.append({
                "type": "image_url",
                "image_url": {"url": f"data:image/jpeg;base64,{img_b64}"},
            })
        user_message = {"role": "user", "content": messages_content}
    else:
        user_message = {"role": "user", "content": prompt}

    try:
        return await _post_chat(
            base_url    = settings.gpt_base_url,
            api_key     = settings.gpt_api_key,
            model       = settings.gpt_model,
            messages    = [
                {"role": "system", "content": system},
                user_message,
            ],
            max_tokens  = settings.max_tokens,
            temperature = 0.2,
            json_mode   = json_mode,
            timeout     = 30.0,
        )
    except Exception as e:
        raise RuntimeError(f"Lỗi gọi GPT-4o: {e}")

async def explain_and_draft_email(
    product_name: str,
    quantity: float,
    min_stock: float,
    supplier_name: str,
    seasonal_context: str,
    forecast_note: str = "",
) -> tuple[dict, int]:
    """Qwen 14B (LM Studio) giải thích tồn kho thấp + soạn email NCC."""
    prompt = f"""Bạn là chuyên gia quản lý kho hàng tại Việt Nam.

Thông tin tồn kho:
- Sản phẩm: {product_name}
- Tồn kho hiện tại: {quantity} đơn vị
- Ngưỡng tối thiểu: {min_stock} đơn vị
- Nhà cung cấp: {supplier_name}
- Bối cảnh mùa vụ: {seasonal_context}
{f"- Dự báo: {forecast_note}" if forecast_note else ""}

Chỉ trả về JSON thuần túy, không giải thích, không markdown, không ```json:
{{
  "explanation": "<2-3 câu giải thích tại sao cần nhập hàng ngay, có nhắc mùa vụ nếu liên quan>",
  "email_subject": "<tiêu đề email đặt hàng>",
  "email_draft": "<nội dung email chuyên nghiệp bằng tiếng Việt gửi nhà cung cấp>"
}}"""

    raw, tokens = await _post_chat(
        base_url=settings.lm_base_url,
        api_key=settings.lm_api_key,
        model=settings.lm_model,
        messages=[
            {"role": "system", "content": SYSTEM_PROMPT},
            {"role": "user", "content": prompt},
        ],
        max_tokens=800,
        temperature=0.3,
        json_mode=False,
        timeout=60.0,
    )
    result = json.loads(_strip_markdown_json(raw))
    return result, tokens