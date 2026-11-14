import re


class UnsafeSQL(ValueError):
    pass


MAX_SQL_LENGTH = 4000

_STRING_LITERAL = re.compile(r"'(?:[^']|'')*'")

_FORBIDDEN = re.compile(
    r"\b("
    r"insert|update|delete|drop|alter|create|truncate|grant|revoke|copy|call|do|"
    r"execute|prepare|deallocate|merge|vacuum|analyze|cluster|reindex|refresh|lock|"
    r"listen|notify|unlisten|set|reset|show|begin|commit|rollback|savepoint|"
    r"declare|fetch|move|into|comment|security|"
    r"set_config|current_setting|pg_\w+|lo_\w+|dblink\w*|information_schema|"
    r"current_user|session_user|current_database|version"
    r")\b",
    re.IGNORECASE,
)

_PUBLIC_SCHEMA = re.compile(r"\bpublic\s*\.", re.IGNORECASE)


def clean_llm_sql(raw: str) -> str:
    """Bỏ rào markdown (```sql) và khoảng trắng thừa."""
    sql = re.sub(r"```(?:sql)?", "", raw, flags=re.IGNORECASE)
    return sql.strip()


def validate_select_only(sql: str) -> str:
    """
    Trả về SQL đã chuẩn hóa (bỏ dấu ';' cuối) nếu hợp lệ, ngược lại raise UnsafeSQL.
    Chỉ cho phép đúng một câu SELECT (hoặc WITH ... SELECT).
    """
    sql = (sql or "").strip()
    if sql.endswith(";"):
        sql = sql[:-1].rstrip()
    if not sql:
        raise UnsafeSQL("Câu SQL rỗng.")
    if len(sql) > MAX_SQL_LENGTH:
        raise UnsafeSQL("Câu SQL quá dài.")

    for token, why in (("--", "chú thích"), ("/*", "chú thích"), ('"', "tên định danh có ngoặc kép"),
                       ("\\", "ký tự thoát"), ("$", "dollar-quote hoặc tham số")):
        if token in sql:
            raise UnsafeSQL(f"Không cho phép {why} trong câu SQL.")

    # Bỏ nội dung chuỗi để từ khóa nằm trong 'chuỗi' không bị tính nhầm.
    bare = _STRING_LITERAL.sub(" ", sql)
    if "'" in bare:
        raise UnsafeSQL("Chuỗi trong câu SQL không đóng đúng cách.")
    if ";" in bare:
        raise UnsafeSQL("Chỉ cho phép một câu lệnh.")
    if not re.match(r"^\s*(select|with)\b", bare, re.IGNORECASE):
        raise UnsafeSQL("Chỉ cho phép câu lệnh SELECT.")
    if _PUBLIC_SCHEMA.search(bare):
        raise UnsafeSQL("Không được truy cập schema public.")
    m = _FORBIDDEN.search(bare)
    if m:
        raise UnsafeSQL(f"Từ khóa hoặc hàm không được phép: {m.group(1)}.")
    return sql
