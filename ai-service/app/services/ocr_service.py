from __future__ import annotations
import asyncio
import base64
import io
import json
import math
import re
from dataclasses import dataclass, field, asdict
from datetime import datetime, timedelta, timezone

from app.services.gpt_service import call_gpt
from app.config import settings


# ================================================================
#  ERRORS
# ================================================================

class OcrError(Exception):
    """Lỗi phía dịch vụ AI (thiếu API key, GPT lỗi/timeout/hết quota, thiếu poppler...).
    Router trả 502/503 — khác ValueError (lỗi do file người dùng → 400)."""


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
    raw_text:        str         = ""   # (dự phòng, hiện chưa dùng)
    confidence:      float       = 0.0  # 0-1, GPT TỰ đánh giá (không phải độ chính xác đo được)
    warnings:        list[str]   = field(default_factory=list)
    pages_total:     int         = 0    # số trang đã gửi đi đọc
    pages_failed:    int         = 0    # số trang đọc lỗi

    def to_dict(self) -> dict:
        return asdict(self)


# ================================================================
#  TIỆN ÍCH PARSE
# ================================================================

_VN_TZ = timezone(timedelta(hours=7))   # cố định UTC+7, không phụ thuộc tzdata trong image


def _today_str() -> str:
    return datetime.now(_VN_TZ).strftime("%Y-%m-%d")


_THOUSANDS_DOT   = re.compile(r"^-?\d{1,3}(\.\d{3})+$")
_THOUSANDS_COMMA = re.compile(r"^-?\d{1,3}(,\d{3})+$")


def _to_number(value, default: float = 0.0) -> float:
    """
    Chuẩn hóa số về float, chịu được định dạng Việt Nam.
      1234.5 / "1234.5"      → 1234.5
      "1.234.000"            → 1234000   (dấu chấm = phân cách nghìn)
      "1,5"                  → 1.5       (dấu phẩy = thập phân)
      "1.234,56" / "1,234.56"→ 1234.56   (ký tự xuất hiện SAU CÙNG là dấu thập phân)
      "12.500 ₫" / "VND"     → 12500
    Quy ước mơ hồ: "1.500" được hiểu là 1500 (hóa đơn VN), không phải 1.5.
    Không parse được → trả default (không ném lỗi).
    """
    if value is None or isinstance(value, bool):
        return default
    if isinstance(value, (int, float)):
        return float(value) if math.isfinite(value) else default

    s = str(value).strip()
    if not s:
        return default
    s = re.sub(r"[^\d.,\-]", "", s.replace("\u00a0", " "))
    if not s or s in {"-", ".", ","}:
        return default
    neg = s.startswith("-")
    s = s.replace("-", "")

    if "." in s and "," in s:
        dec = "." if s.rfind(".") > s.rfind(",") else ","
        thou = "," if dec == "." else "."
        s = s.replace(thou, "").replace(dec, ".")
    elif "," in s:
        s = s.replace(",", "") if _THOUSANDS_COMMA.match(s) else s.replace(",", ".")
    elif "." in s:
        if _THOUSANDS_DOT.match(s):
            s = s.replace(".", "")
        elif s.count(".") > 1:
            s = s.replace(".", "")
    try:
        n = float(s)
    except ValueError:
        return default
    n = -n if neg else n
    return n if math.isfinite(n) else default


def _clean_date(value) -> str:
    """Trả 'YYYY-MM-DD' hợp lệ hoặc ''."""
    s = str(value or "").strip()
    if not s:
        return ""
    try:
        return datetime.strptime(s[:10], "%Y-%m-%d").strftime("%Y-%m-%d")
    except ValueError:
        return ""


def _extract_json(raw: str) -> dict:
    """
    Lấy object JSON từ phản hồi của model, chịu được:
    markdown ```json ... ```, lời dẫn trước/sau JSON.
    Ném json.JSONDecodeError / ValueError nếu không có JSON object hợp lệ.
    """
    raw = (raw or "").strip()
    candidates = [raw]
    m = re.search(r"```(?:json)?\s*(.*?)```", raw, re.DOTALL | re.IGNORECASE)
    if m:
        candidates.append(m.group(1).strip())
    lo, hi = raw.find("{"), raw.rfind("}")
    if lo != -1 and hi > lo:
        candidates.append(raw[lo:hi + 1])

    last_err: Exception = ValueError("Phản hồi rỗng")
    for c in candidates:
        try:
            data = json.loads(c)
        except json.JSONDecodeError as e:
            last_err = e
            continue
        if isinstance(data, list) and len(data) == 1 and isinstance(data[0], dict):
            data = data[0]
        if isinstance(data, dict):
            return data
        last_err = ValueError("JSON không phải object")
    raise last_err


# ================================================================
#  BƯỚC 1 — Convert PDF → list base64 ảnh
# ================================================================

def pdf_page_count(pdf_bytes: bytes) -> int:
    """Số trang PDF (0 nếu không xác định được — không ném lỗi)."""
    try:
        from pdf2image import pdfinfo_from_bytes
        return int(pdfinfo_from_bytes(pdf_bytes).get("Pages", 0))
    except Exception:
        return 0


def pdf_to_images_b64(pdf_bytes: bytes, dpi: int = 200, max_pages: int | None = None) -> list[str]:
    """
    Convert PDF → list ảnh JPEG base64.
    Chỉ convert `max_pages` trang đầu (last_page) thay vì convert hết rồi mới cắt —
    đo thực tế: PDF 40 trang 4.09s → 0.54s khi chỉ lấy 5 trang.
    Hàm này CHẠY ĐỒNG BỘ (nặng CPU) — gọi qua asyncio.to_thread.
    """
    from pdf2image import convert_from_bytes
    from pdf2image.exceptions import (
        PDFInfoNotInstalledError, PDFPageCountError,
        PDFSyntaxError, PDFPopplerTimeoutError,
    )

    kwargs = dict(dpi=dpi, fmt="jpeg", thread_count=2, timeout=60)
    if max_pages:
        kwargs.update(first_page=1, last_page=max_pages)

    try:
        images = convert_from_bytes(pdf_bytes, **kwargs)
    except PDFInfoNotInstalledError as e:
        raise OcrError("Máy chủ AI thiếu poppler-utils nên không đọc được PDF") from e
    except (PDFPageCountError, PDFSyntaxError) as e:
        raise ValueError("Không thể đọc PDF — file có thể bị hỏng hoặc được đặt mật khẩu") from e
    except PDFPopplerTimeoutError as e:
        raise ValueError("PDF quá phức tạp, xử lý quá thời gian cho phép") from e
    except Exception as e:                       # vd. PIL.Image.DecompressionBombError
        raise ValueError(f"Không thể đọc PDF: {e}") from e

    result = []
    for img in images:
        buf = io.BytesIO()
        img.convert("RGB").save(buf, format="JPEG", quality=85)
        result.append(base64.b64encode(buf.getvalue()).decode("utf-8"))
    return result


# ================================================================
#  BƯỚC 2 — GPT-4o Vision extract từng trang
# ================================================================

OCR_SYSTEM = """Bạn là chuyên gia kế toán kho hàng, chuyên đọc hóa đơn nhập hàng.
Nhiệm vụ: trích xuất thông tin từ ảnh hóa đơn và trả về JSON chính xác.
Quy tắc:
- Chỉ trả về JSON thuần túy, không markdown, không giải thích
- Chỉ ghi những gì NHÌN THẤY trên ảnh, không suy đoán, không bịa. Không đọc được → "" hoặc 0
- Số lượng, đơn giá, thành tiền luôn là SỐ JSON (ví dụ 1500000 hoặc 2.5),
  KHÔNG có dấu phân cách nghìn, dùng dấu chấm cho phần thập phân
- Hàng tặng / khuyến mãi vẫn liệt kê, ghi đúng số lượng trên hóa đơn và ghi chú trong "note"
- Ngày tháng định dạng YYYY-MM-DD
- total_amount: tổng tiền thanh toán của hóa đơn; chỉ điền nếu trang này có dòng tổng, ngược lại để 0
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


def build_prompt(page_num: int, total_pages: int = 1, today: str | None = None) -> str:
    today = today or _today_str()
    return (
        f"Đây là trang {page_num}/{total_pages} của hóa đơn. "
        f"Hôm nay là {today} (chỉ dùng để suy ra năm khi hóa đơn không ghi năm).\n"
        f"{OCR_PROMPT}"
    )


def _is_fatal(err: str) -> bool:
    """Lỗi chắc chắn không hết khi thử lại (sai key / hết quyền)."""
    return any(code in err for code in ("401", "403", "invalid_api_key"))


async def extract_page(image_b64: str, page_num: int, total_pages: int = 1, today: str | None = None) -> dict:
    """
    Gọi GPT-4o Vision cho 1 trang, trả về dict.
    Lỗi tạm thời (timeout / 429 / 5xx / JSON hỏng) được thử lại `ocr_retries` lần.
    Thất bại hẳn → {"_error": "...", "_tokens": 0}.
    """
    prompt   = build_prompt(page_num, total_pages, today)
    retries  = max(0, int(getattr(settings, "ocr_retries", 1)))
    last_err = "Lỗi không xác định"

    for attempt in range(retries + 1):
        try:
            raw, tokens = await call_gpt(
                prompt     = prompt,
                system     = OCR_SYSTEM,
                json_mode  = True,
                images     = [image_b64],
                max_tokens = int(getattr(settings, "ocr_max_tokens", 4096)),
                timeout    = float(getattr(settings, "ocr_timeout", 90.0)),
            )
            data = _extract_json(raw)
            data["_tokens"] = tokens
            return data
        except (json.JSONDecodeError, ValueError):
            last_err = (f"Trang {page_num}: phản hồi AI không phải JSON hoàn chỉnh "
                        f"(hóa đơn có thể quá dài hoặc ảnh khó đọc)")
        except Exception as e:
            last_err = str(e) or type(e).__name__
            if _is_fatal(last_err):
                break
        if attempt < retries:
            await asyncio.sleep(1.5 * (attempt + 1))

    return {"_error": last_err, "_tokens": 0}


# ================================================================
#  BƯỚC 3 — Merge kết quả nhiều trang
# ================================================================

_MAX_LINE_WARNINGS = 5


def merge_pages(pages: list[dict]) -> InvoiceResult:
    """
    Merge kết quả nhiều trang thành 1 InvoiceResult.
    - Header (NCC, SĐT, MST, số HĐ, ngày): mỗi trường lấy giá trị không rỗng đầu tiên
    - total_amount: lấy từ TRANG CUỐI CÓ GIÁ TRỊ (dòng tổng thường ở trang cuối;
      không dùng max vì có thể nhầm với tổng phụ/số lớn đọc sai)
    - Items gộp từ mọi trang; dòng bị bỏ đều có cảnh báo (không bỏ im lặng)
    - confidence: lấy mức THẤP NHẤT của các trang đọc được
    - Cảnh báo khi tổng các dòng lệch tổng hóa đơn / SL×đơn giá lệch thành tiền
    """
    result   = InvoiceResult()
    items: list[InvoiceItem] = []
    warnings: list[str] = []
    confs:    list[float] = []
    failed = 0
    bad_lines: list[str] = []

    for i, page in enumerate(pages):
        pno = i + 1
        if "_error" in page:
            failed += 1
            warnings.append(f"Trang {pno}: đọc lỗi — {page['_error']}")
            continue

        # Header — điền từng trường còn trống
        for attr, key in (("supplier_name", "supplier_name"), ("supplier_phone", "supplier_phone"),
                          ("supplier_tax", "supplier_tax"), ("invoice_no", "invoice_no")):
            if not getattr(result, attr):
                setattr(result, attr, str(page.get(key) or "").strip())

        raw_date = str(page.get("invoice_date") or "").strip()
        if raw_date and not result.invoice_date:
            clean = _clean_date(raw_date)
            if clean:
                result.invoice_date = clean
            else:
                warnings.append(f"Trang {pno}: ngày hóa đơn '{raw_date}' không hợp lệ, đã bỏ trống")

        confs.append(min(1.0, max(0.0, _to_number(page.get("confidence"), 0.5))))

        page_total = _to_number(page.get("total_amount"))
        if page_total > 0:
            result.total_amount = page_total

        # Items
        raw_items = page.get("items")
        for item_data in (raw_items if isinstance(raw_items, list) else []):
            if not isinstance(item_data, dict):
                continue
            name = str(item_data.get("name") or "").strip()
            qty  = _to_number(item_data.get("quantity"))
            if not name:
                if qty > 0:
                    warnings.append(f"Trang {pno}: có dòng SL {qty:g} nhưng không đọc được tên hàng")
                continue
            if qty <= 0:
                warnings.append(f"Trang {pno}: bỏ qua dòng '{name}' vì số lượng = 0 (hàng tặng/đọc lỗi?) — hãy đối chiếu hóa đơn")
                continue

            price = _to_number(item_data.get("unit_price"))
            line  = _to_number(item_data.get("total"))
            if line > 0 and price > 0 and abs(qty * price - line) > max(1.0, 0.01 * line):
                bad_lines.append(name)
            items.append(InvoiceItem(
                name       = name,
                sku        = str(item_data.get("sku") or "").strip(),
                quantity   = qty,
                unit       = str(item_data.get("unit") or "").strip(),
                unit_price = price,
                total      = line if line > 0 else round(qty * price, 2),
                note       = str(item_data.get("note") or "").strip(),
            ))

        for w in (page.get("warnings") if isinstance(page.get("warnings"), list) else []):
            if w:
                warnings.append(f"Trang {pno}: {w}")

    result.items        = items
    result.pages_total  = len(pages)
    result.pages_failed = failed

    # Tự tính total nếu AI không đọc được
    items_sum = sum(it.total for it in items)
    if result.total_amount == 0 and items:
        result.total_amount = items_sum
    elif result.total_amount > 0 and items:
        diff = abs(items_sum - result.total_amount)
        if diff > max(1.0, 0.01 * result.total_amount):
            warnings.append(
                f"Tổng các dòng hàng ({items_sum:,.0f}) khác tổng hóa đơn ({result.total_amount:,.0f}) "
                f"— có thể do VAT/chiết khấu/phí vận chuyển, hoặc đọc sai/thiếu dòng. Hãy đối chiếu."
            )

    if bad_lines:
        shown = ", ".join(f"'{n}'" for n in bad_lines[:_MAX_LINE_WARNINGS])
        more  = f" và {len(bad_lines) - _MAX_LINE_WARNINGS} dòng khác" if len(bad_lines) > _MAX_LINE_WARNINGS else ""
        warnings.append(f"SL × đơn giá khác thành tiền ở: {shown}{more}")

    if failed < len(pages) and not items:
        warnings.append("Không nhận dạng được dòng hàng nào — kiểm tra lại file hoặc nhập tay")

    # Confidence: thấp nhất các trang đọc được; hạ trần nếu có trang lỗi / không có dòng hàng
    conf = min(confs) if confs else 0.0
    if failed:
        conf = min(conf, 0.5)
    if not items:
        conf = min(conf, 0.3)
    result.confidence = round(conf, 2)
    result.warnings   = warnings
    return result


# ================================================================
#  PUBLIC API
# ================================================================

def _prepare_pdf(pdf_bytes: bytes) -> tuple[list[str], int]:
    max_pages = int(getattr(settings, "ocr_max_pages", 5))
    images = pdf_to_images_b64(pdf_bytes, dpi=int(getattr(settings, "ocr_dpi", 200)), max_pages=max_pages)
    return images, pdf_page_count(pdf_bytes)


async def ocr_invoice(pdf_bytes: bytes) -> InvoiceResult:
    """
    Full pipeline: PDF bytes → InvoiceResult. Gọi từ FastAPI router.
    - ValueError : lỗi do file (hỏng / mật khẩu / không có trang) → 400
    - OcrError   : lỗi phía dịch vụ (thiếu key, GPT lỗi mọi trang) → 502/503
    """
    if not getattr(settings, "gpt_api_key", ""):
        raise OcrError("Chưa cấu hình GPT_API_KEY cho dịch vụ AI")

    # Convert nặng CPU → chạy thread riêng, không chặn event loop của FastAPI
    images, total_pages = await asyncio.to_thread(_prepare_pdf, pdf_bytes)
    if not images:
        raise ValueError("Không thể đọc PDF — file có thể bị hỏng hoặc được bảo vệ")

    today = _today_str()
    pages = await asyncio.gather(*[
        extract_page(img, i + 1, len(images), today) for i, img in enumerate(images)
    ])

    result = merge_pages(list(pages))

    if result.pages_failed == len(images):
        first = next((p["_error"] for p in pages if "_error" in p), "không rõ nguyên nhân")
        raise OcrError(f"AI không đọc được hóa đơn: {first}")

    if total_pages > len(images):
        result.warnings.append(
            f"PDF có {total_pages} trang, chỉ đọc {len(images)} trang đầu — các trang sau chưa được xử lý"
        )
    return result
