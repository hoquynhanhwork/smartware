from app.services.sql_guard import validate_select_only as v
from app.services.text_to_sql_service import run_readonly as r
cases = [
 "SELECT count(*) FROM products",
 "SELECT * FROM products WHERE supplier_id = 2",
 "DELETE FROM products",
 "SELECT 1; DROP TABLE products",
 "SELECT * FROM public.users",
 "SELECT * FROM users",
 "SELECT pg_sleep(10)",
 "SELECT set_config('app.supplier_id','2',true)",
]
for s in cases:
    try:
        rows = r(v(s), 1)
        print("RUN  ", s, "->", len(rows), "rows", rows[:1])
    except Exception as e:
        print("BLOCK", s, "->", type(e).__name__, str(e)[:80])