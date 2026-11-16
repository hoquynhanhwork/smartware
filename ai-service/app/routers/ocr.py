from fastapi import APIRouter, Depends, HTTPException, UploadFile, File
from app.services.ocr_service import ocr_invoice, OcrError
from app.security import verify_api_key

router = APIRouter()

# Giới hạn 20MB
MAX_SIZE = 20 * 1024 * 1024


@router.post("/ocr/invoice")
async def ocr_invoice_endpoint(
    file:    UploadFile = File(..., description="File PDF hóa đơn nhập hàng"),
    api_key: str        = Depends(verify_api_key),
):
    """
    OCR hóa đơn PDF → JSON.

    Response:
    {
      "supplier_name": "...",
      "invoice_no": "...",
      "invoice_date": "YYYY-MM-DD",
      "total_amount": 0,
      "items": [{"name", "sku", "quantity", "unit", "unit_price", "total"}],
      "confidence": 0.9,          # do AI tự đánh giá
      "warnings": [],
      "pages_total": 1,
      "pages_failed": 0
    }

    Mã lỗi:
      400 — file không hợp lệ (không phải PDF, rỗng, quá lớn, hỏng/có mật khẩu)
      502 — AI không đọc được hóa đơn (GPT lỗi/timeout/hết quota ở mọi trang)
      503 — dịch vụ AI chưa cấu hình (thiếu GPT_API_KEY / poppler)
    """
    filename = (file.filename or "").lower()
    if not filename.endswith(".pdf"):
        raise HTTPException(status_code=400, detail="Chỉ hỗ trợ file PDF")

    # Đọc tối đa MAX_SIZE + 1 byte để từ chối file quá lớn mà không nạp hết vào RAM
    pdf_bytes = await file.read(MAX_SIZE + 1)

    if len(pdf_bytes) == 0:
        raise HTTPException(status_code=400, detail="File PDF rỗng")

    if len(pdf_bytes) > MAX_SIZE:
        raise HTTPException(status_code=400, detail="File PDF quá lớn (tối đa 20MB)")

    # Kiểm tra nội dung thật, không chỉ đuôi file
    if not pdf_bytes.lstrip(b"\x00\t\r\n ").startswith(b"%PDF-"):
        raise HTTPException(status_code=400, detail="File không phải PDF hợp lệ")

    try:
        result = await ocr_invoice(pdf_bytes)
        return result.to_dict()
    except ValueError as e:
        raise HTTPException(status_code=400, detail=str(e))
    except OcrError as e:
        msg = str(e)
        status = 503 if ("cấu hình" in msg or "poppler" in msg) else 502
        raise HTTPException(status_code=status, detail=msg)
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Lỗi OCR: {str(e)}")
