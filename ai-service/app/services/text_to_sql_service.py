import logging
from sqlalchemy import text
from app.services.gpt_service import _post_chat
from app.services.sql_guard import UnsafeSQL, clean_llm_sql, validate_select_only
from app.db.postgresql import ro_engine
from app.config import settings

logger = logging.getLogger(__name__)

MAX_ROWS = 1000

# Có thể cache schema để không gọi DB mỗi lần
_SCHEMA_CACHE = None

def get_db_schema() -> str:
    """Schema các view ai_ro (đã lọc theo nhà cung cấp), đọc bằng role chỉ-đọc."""
    global _SCHEMA_CACHE
    if _SCHEMA_CACHE:
        return _SCHEMA_CACHE

    with ro_engine.connect() as conn:
        rows = conn.execute(text("""
            SELECT table_name, column_name, data_type
            FROM information_schema.columns
            WHERE table_schema = 'ai_ro'
            ORDER BY table_name, ordinal_position
        """)).fetchall()
    schema_str = ""
    for table, col, dtype in rows:
        schema_str += f"{table}.{col} ({dtype})\n"
    _SCHEMA_CACHE = schema_str
    return schema_str


def run_readonly(sql: str, supplier_id: int) -> list[dict]:
    """
    Chạy SQL trong giao dịch chỉ-đọc bằng role smartware_ai_ro.
    Phạm vi nhà cung cấp do DB ép (view ai_ro đọc app.supplier_id), không phụ thuộc prompt.
    """
    # exec_driver_sql không parse ':tham_số'; '%' phải nhân đôi cho psycopg2.
    wrapped = f"SELECT * FROM ({sql}) AS q LIMIT {MAX_ROWS}".replace("%", "%%")
    with ro_engine.connect() as conn:
        with conn.begin():
            conn.exec_driver_sql("SET TRANSACTION READ ONLY")
            conn.exec_driver_sql("SET LOCAL statement_timeout = '5s'")
            conn.exec_driver_sql("SELECT set_config('app.supplier_id', %s, true)", (str(int(supplier_id)),))
            result = conn.exec_driver_sql(wrapped)
            cols = list(result.keys())
            return [dict(zip(cols, row)) for row in result.fetchall()]


async def ask_sql(question: str, supplier_id: int) -> tuple[str, list | dict]:
    """
    Chuyển câu hỏi thành SQL, thực thi và trả về kết quả.
    """
    if ro_engine is None:
        return "", {"error": "Text-to-SQL chưa được cấu hình (thiếu DATABASE_URL_RO)."}

    schema = get_db_schema()

    prompt = f"""
Bạn là một chuyên gia SQL. Dựa trên cấu trúc cơ sở dữ liệu dưới đây, hãy viết câu lệnh SQL để trả lời câu hỏi của người dùng.

Cấu trúc bảng (table.column (kiểu dữ liệu)):
{schema}

Lưu ý:
- Sử dụng ngôn ngữ SQL chuẩn PostgreSQL.
- Dữ liệu trong các bảng trên đã được giới hạn sẵn theo nhà cung cấp hiện tại, không cần lọc thêm theo supplier_id.
- Chỉ viết đúng một câu SELECT. Không dùng INSERT/UPDATE/DELETE/DROP hay bất kỳ lệnh thay đổi dữ liệu nào.
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
        timeout=300
    )

    sql = clean_llm_sql(sql_response)

    if 'INVALID' in sql:
        return sql, {"error": "Không thể tạo SQL từ câu hỏi."}

    try:
        safe_sql = validate_select_only(sql)
    except UnsafeSQL as e:
        logger.warning("Text-to-SQL bị chặn: %s | sql=%r", e, sql[:300])
        return sql, {"error": f"Câu SQL bị từ chối: {e}"}

    try:
        return safe_sql, run_readonly(safe_sql, supplier_id)
    except Exception:
        logger.exception("Text-to-SQL lỗi khi thực thi | sql=%r", safe_sql[:300])
        return safe_sql, {"error": "Không thực thi được câu truy vấn. Hãy thử diễn đạt lại câu hỏi."}
