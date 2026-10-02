import re
from sqlalchemy.orm import Session
from sqlalchemy import text
from app.services.gpt_service import _post_chat
from app.config import settings

# Có thể cache schema để không gọi DB mỗi lần
_SCHEMA_CACHE = None

def get_db_schema(db: Session) -> str:
    """Lấy schema dạng text: danh sách các bảng và cột liên quan đến kho hàng."""
    global _SCHEMA_CACHE
    if _SCHEMA_CACHE:
        return _SCHEMA_CACHE

    # Lấy danh sách bảng + cột (chỉ lấy các bảng quan trọng)
    tables = [
        "products", "suppliers", "categories",
        "stock_outbounds", "stock_outbound_items",
        "stock_inbounds", "stock_inbound_items",
        "inventory_batches", "inventory_history",
        "forecasts", "trends", "alerts", "warehouse_assessment"
    ]
    table_list = ", ".join(f"'{t}'" for t in tables)
    rows = db.execute(text(f"""
        SELECT table_name, column_name, data_type
        FROM information_schema.columns
        WHERE table_name IN ({table_list})
          AND table_schema = 'public'
        ORDER BY table_name, ordinal_position
    """)).fetchall()
    schema_str = ""
    for table, col, dtype in rows:
        schema_str += f"{table}.{col} ({dtype})\n"
    _SCHEMA_CACHE = schema_str
    return schema_str

async def ask_sql(question: str, supplier_id: int, db: Session) -> tuple[str, list | dict]:
    """
    Chuyển câu hỏi thành SQL, thực thi và trả về kết quả.
    """
    schema = get_db_schema(db)

    prompt = f"""
Bạn là một chuyên gia SQL. Dựa trên cấu trúc cơ sở dữ liệu dưới đây, hãy viết câu lệnh SQL để trả lời câu hỏi của người dùng.

Cấu trúc bảng (table.column (kiểu dữ liệu)):
{schema}

Lưu ý:
- Sử dụng ngôn ngữ SQL chuẩn PostgreSQL.
- Nếu câu hỏi liên quan đến một nhà cung cấp (supplier) cụ thể, hãy sử dụng supplier_id = {supplier_id} trong điều kiện WHERE.
- Chỉ trả về câu lệnh SQL, không giải thích, không markdown.
- Nếu câu hỏi không thể chuyển thành SQL, hãy trả về 'INVALID' và giải thích ngắn trong comment.

Câu hỏi: {question}
"""

    # Gọi LM Studio hoặc GPT để sinh SQL
    sql_response, tokens = await _post_chat(
        base_url=settings.lm_base_url,
        api_key=settings.lm_api_key,
        model=settings.lm_model,
        messages=[
            {"role": "system", "content": "Bạn là chuyên gia SQL. Chỉ trả về câu lệnh SQL, không giải thích."},
            {"role": "user", "content": prompt}
        ],
        max_tokens=300,
        temperature=0.1,
        timeout=30
    )

    # Làm sạch SQL (loại bỏ markdown, comments)
    sql = re.sub(r'```sql\n?', '', sql_response.strip())
    sql = re.sub(r'```', '', sql)
    sql = sql.strip()

    if 'INVALID' in sql:
        return sql, {"error": "Không thể tạo SQL từ câu hỏi."}

    # Thực thi SQL
    try:
        result = db.execute(text(sql)).fetchall()
        # Chuyển thành list of dict
        cols = result.keys() if result else []
        data = [dict(zip(cols, row)) for row in result]
        return sql, data
    except Exception as e:
        return sql, {"error": f"Lỗi khi thực thi SQL: {e}"}