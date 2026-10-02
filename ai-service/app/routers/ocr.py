"""
routers/ocr.py
──────────────
Endpoint OCR hóa đơn nhập hàng.
Nhận file PDF (multipart/form-data), trả về JSON chuẩn hóa.
"""

from fastapi import APIRouter, Depends, HTTPException, UploadFile, File, Form
from app.services.ocr_service import ocr_invoice
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
      "confidence": 0.9,
      "warnings": []
    }
    """
    # Validate file type
    if not file.filename.lower().endswith(".pdf"):
        raise HTTPException(status_code=400, detail="Chỉ hỗ trợ file PDF")

    # Đọc bytes
    pdf_bytes = await file.read()

    if len(pdf_bytes) == 0:
        raise HTTPException(status_code=400, detail="File PDF rỗng")

    if len(pdf_bytes) > MAX_SIZE:
        raise HTTPException(status_code=400, detail="File PDF quá lớn (tối đa 20MB)")

    try:
        result = await ocr_invoice(pdf_bytes)
        return result.to_dict()
    except ValueError as e:
        raise HTTPException(status_code=400, detail=str(e))
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Lỗi OCR: {str(e)}")