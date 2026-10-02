"""
ocr_service.py
──────────────
OCR hóa đơn nhập hàng từ PDF:
  1. Convert PDF → list ảnh (pdf2image + poppler)
  2. Gửi từng trang lên GPT-4o Vision
  3. Parse JSON → chuẩn hóa thành InvoiceResult
  4. Merge kết quả nhiều trang

Dùng GPT-4o vì hóa đơn mỗi NCC khác format,
cần model hiểu ngữ cảnh linh hoạt.
"""

from __future__ import annotations
import base64
import json
import io
from dataclasses import dataclass, field, asdict
from app.services.gpt_service import call_gpt
from app.config import settings


# ================================================================
#  DATA MODELS
# ================================================================

@dataclass
class InvoiceItem:
    name:       str
    quantity:   float
    unit_price: float
    unit:       str   = ""      # đơn vị tính: hộp, cái, kg...
    sku:        str   = ""      # mã SP nếu có trên hóa đơn
    total:      float = 0.0
    note:       str   = ""


@dataclass
class InvoiceResult:
    supplier_name:   str         = ""
    supplier_phone:  str         = ""
    supplier_tax:    str         = ""   # mã số thuế
    invoice_no:      str         = ""
    invoice_date:    str         = ""   # YYYY-MM-DD
    total_amount:    float       = 0.0
    items:           list[InvoiceItem] = field(default_factory=list)
    raw_text:        str         = ""   # text thô GPT extract được
    confidence:      float       = 0.0  # 0-1, GPT tự đánh giá
    warnings:        list[str]   = field(default_factory=list)

    def to_dict(self) -> dict:
        d = asdict(self)
        return d


# ================================================================
#  BƯỚC 1 — Convert PDF → list base64 ảnh
# ================================================================

def pdf_to_images_b64(pdf_bytes: bytes, dpi: int = 200) -> list[str]:
    """
    Convert PDF → list ảnh JPEG base64.
    DPI 200 là đủ cho GPT-4o Vision, không cần cao hơn (tốn token).
    """
    from pdf2image import convert_from_bytes

    images = convert_from_bytes(
        pdf_bytes,
        dpi        = dpi,
        fmt        = "jpeg",
        thread_count = 2,
    )

    result = []
    for img in images:
        buf = io.BytesIO()
        img.save(buf, format="JPEG", quality=85)
        b64 = base64.b64encode(buf.getvalue()).decode("utf-8")
        result.append(b64)

    return result


# ================================================================
#  BƯỚC 2 — GPT-4o Vision extract từng trang
# ================================================================

OCR_SYSTEM = """Bạn là chuyên gia kế toán kho hàng, chuyên đọc hóa đơn nhập hàng.
Nhiệm vụ: trích xuất thông tin từ ảnh hóa đơn và trả về JSON chính xác.
Quy tắc:
- Chỉ trả về JSON thuần túy, không markdown, không giải thích
- Nếu không đọc được một trường, để chuỗi rỗng "" hoặc 0
- Số lượng và đơn giá luôn là số (không có dấu phẩy phân cách nghìn)
- Ngày tháng định dạng YYYY-MM-DD, nếu không rõ năm thì dùng năm hiện tại
- confidence: tự đánh giá độ chính xác từ 0.0 đến 1.0"""

OCR_PROMPT = """Đọc hóa đơn trong ảnh và trích xuất thông tin theo JSON sau:

{
  "supplier_name": "tên nhà cung cấp",
  "supplier_phone": "số điện thoại NCC",
  "supplier_tax": "mã số thuế NCC",
  "invoice_no": "số hóa đơn",
  "invoice_date": "YYYY-MM-DD",
  "total_amount": 0,
  "items": [
    {
      "name": "tên sản phẩm",
      "sku": "mã SP nếu có",
      "quantity": 0,
      "unit": "đơn vị tính",
      "unit_price": 0,
      "total": 0,
      "note": "ghi chú nếu có"
    }
  ],
  "confidence": 0.9,
  "warnings": ["cảnh báo nếu có chữ mờ, khó đọc"]
}"""


async def extract_page(image_b64: str, page_num: int) -> dict:
    """Gọi GPT-4o Vision cho 1 trang, trả về dict."""
    prompt = f"Trang {page_num}:\n{OCR_PROMPT}"

    try:
        raw, tokens = await call_gpt(
            prompt    = prompt,
            system    = OCR_SYSTEM,
            json_mode = False,
            images    = [image_b64],
        )

        # Strip markdown nếu có
        raw = raw.strip()
        if raw.startswith("```"):
            parts = raw.split("```")
            raw = parts[1] if len(parts) > 1 else raw
            if raw.startswith("json"):
                raw = raw[4:]
        raw = raw.strip()

        data = json.loads(raw)
        data["_tokens"] = tokens
        return data

    except json.JSONDecodeError:
        return {"_error": f"JSON parse error trang {page_num}", "_tokens": 0}
    except Exception as e:
        return {"_error": str(e), "_tokens": 0}


# ================================================================
#  BƯỚC 3 — Merge kết quả nhiều trang
# ================================================================

def merge_pages(pages: list[dict]) -> InvoiceResult:
    """
    Merge kết quả nhiều trang thành 1 InvoiceResult.
    - Header info (NCC, số HĐ, ngày) lấy từ trang đầu
    - Items gộp từ tất cả trang
    - Warnings gộp tất cả
    """
    result = InvoiceResult()
    all_items: list[InvoiceItem] = []
    total_tokens = 0
    warnings: list[str] = []

    for i, page in enumerate(pages):
        if "_error" in page:
            warnings.append(f"Trang {i+1}: {page['_error']}")
            continue

        total_tokens += page.get("_tokens", 0)

        # Header — chỉ lấy từ trang đầu hoặc trang có data
        if not result.supplier_name and page.get("supplier_name"):
            result.supplier_name  = page.get("supplier_name", "")
            result.supplier_phone = page.get("supplier_phone", "")
            result.supplier_tax   = page.get("supplier_tax", "")
            result.invoice_no     = page.get("invoice_no", "")
            result.invoice_date   = page.get("invoice_date", "")
            result.confidence     = float(page.get("confidence", 0.8))

        # Total amount — lấy giá trị lớn nhất (thường ở trang cuối)
        page_total = float(page.get("total_amount", 0) or 0)
        if page_total > result.total_amount:
            result.total_amount = page_total

        # Items
        for item_data in page.get("items", []):
            if not item_data.get("name"):
                continue
            try:
                item = InvoiceItem(
                    name       = str(item_data.get("name", "")),
                    sku        = str(item_data.get("sku", "") or ""),
                    quantity   = float(item_data.get("quantity", 0) or 0),
                    unit       = str(item_data.get("unit", "") or ""),
                    unit_price = float(item_data.get("unit_price", 0) or 0),
                    total      = float(item_data.get("total", 0) or 0),
                    note       = str(item_data.get("note", "") or ""),
                )
                if item.quantity > 0:
                    all_items.append(item)
            except (ValueError, TypeError):
                warnings.append(f"Trang {i+1}: Không parse được dòng '{item_data.get('name', '')}'")

        # Warnings từ GPT
        for w in page.get("warnings", []):
            if w:
                warnings.append(f"Trang {i+1}: {w}")

    result.items    = all_items
    result.warnings = warnings

    # Tự tính total nếu GPT không đọc được
    if result.total_amount == 0 and all_items:
        result.total_amount = sum(
            item.total or (item.quantity * item.unit_price)
            for item in all_items
        )

    return result


# ================================================================
#  PUBLIC API
# ================================================================

async def ocr_invoice(pdf_bytes: bytes) -> InvoiceResult:
    """
    Full pipeline: PDF bytes → InvoiceResult.
    Gọi từ FastAPI router.
    """
    import asyncio

    # Convert PDF → ảnh
    images = pdf_to_images_b64(pdf_bytes, dpi=200)
    if not images:
        raise ValueError("Không thể đọc PDF — file có thể bị hỏng hoặc được bảo vệ")

    # Giới hạn 5 trang đầu (hóa đơn thường không dài hơn)
    images = images[:5]

    # Extract từng trang song song
    tasks   = [extract_page(img, i + 1) for i, img in enumerate(images)]
    pages   = await asyncio.gather(*tasks)

    # Merge
    result = merge_pages(list(pages))
    return result