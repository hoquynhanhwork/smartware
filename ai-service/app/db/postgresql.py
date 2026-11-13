from sqlalchemy import create_engine, text
from sqlalchemy.orm import sessionmaker
from app.config import settings

engine = create_engine(settings.database_url)
SessionLocal = sessionmaker(bind=engine)

ro_engine = (
    create_engine(settings.database_url_ro, pool_size=3, max_overflow=2, pool_pre_ping=True)
    if settings.database_url_ro
    else None
)

def get_db():
    db = SessionLocal()
    try:
        yield db
    finally:
        db.close()

def test_connection():
    try:
        with engine.connect() as conn:
            conn.execute(text("SELECT 1"))
        return True
    except Exception as e:
        return str(e)