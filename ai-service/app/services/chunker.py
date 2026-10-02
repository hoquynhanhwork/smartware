from __future__ import annotations
import re
from pathlib import Path
from dataclasses import dataclass


@dataclass
class Chunk:
    chunk_index:  int
    chunk_text:   str    # child — ~200 token
    parent_text:  str    # parent — ~500 token
    source_file:  str
    file_type:    str


# ================================================================
#  ĐỌC FILE
# ================================================================

def read_file(path: str | Path) -> str:
    """Đọc nội dung raw text từ PDF / docx / txt."""
    path = Path(path)
    ext  = path.suffix.lower()

    if ext == ".txt":
        return path.read_text(encoding="utf-8", errors="ignore")

    elif ext == ".pdf":
        try:
            import pdfplumber
            with pdfplumber.open(path) as pdf:
                pages = [p.extract_text() or "" for p in pdf.pages]
            return "\n\n".join(pages)
        except ImportError:
            # Fallback: dùng pypdf
            from pypdf import PdfReader
            reader = PdfReader(str(path))
            return "\n\n".join(
                page.extract_text() or "" for page in reader.pages
            )

    elif ext in (".docx", ".doc"):
        from docx import Document
        doc   = Document(str(path))
        paras = [p.text for p in doc.paragraphs if p.text.strip()]
        return "\n\n".join(paras)

    else:
        raise ValueError(f"Định dạng không hỗ trợ: {ext}")


# ================================================================
#  CLEAN TEXT
# ================================================================

def clean_text(text: str) -> str:
    """Làm sạch text: bỏ khoảng trắng thừa, giữ cấu trúc đoạn."""
    # Chuẩn hoá xuống dòng
    text = re.sub(r'\r\n', '\n', text)
    # Xoá khoảng trắng đầu/cuối mỗi dòng
    lines = [l.strip() for l in text.split('\n')]
    # Gộp nhiều dòng trống liên tiếp thành 1
    text = re.sub(r'\n{3,}', '\n\n', '\n'.join(lines))
    return text.strip()


# ================================================================
#  TÁCH CHUNK
# ================================================================

def _split_sentences(text: str) -> list[str]:
    """Tách thành danh sách câu, giữ dấu câu."""
    # Tách tại dấu . ! ? theo sau bởi khoảng trắng + chữ hoa / \n
    parts = re.split(r'(?<=[.!?])\s+(?=[A-ZÀÁẢÃẠĂẮẶẲẴẰÂẤẦẨẪẬĐÈÉẺẼẸÊẾỀỂỄỆÌ])', text)
    return [p.strip() for p in parts if p.strip()]


def _approx_tokens(text: str) -> int:
    """Ước lượng số token: ~4 ký tự = 1 token (tiếng Việt ~3 ký tự/token)."""
    return len(text) // 3


def make_chunks(
    text:         str,
    source_file:  str,
    file_type:    str,
    child_tokens: int = 200,
    parent_tokens: int = 500,
) -> list[Chunk]:
    """
    Tách text thành (child, parent) chunk pairs.
    
    Thuật toán:
      1. Tách thành đoạn (paragraph)
      2. Gom đoạn thành parent (~500 token)
      3. Trong mỗi parent, tách thành child (~200 token)
      4. Mỗi child mang theo parent của nó
    """
    text   = clean_text(text)
    # Tách thành paragraphs
    paras  = [p.strip() for p in re.split(r'\n\n+', text) if p.strip()]

    chunks: list[Chunk] = []
    idx    = 0

    # Gom paragraphs thành parents
    current_parent_paras: list[str] = []
    current_parent_tokens = 0

    def flush_parent(paras_: list[str]) -> None:
        nonlocal idx
        parent_text = "\n\n".join(paras_)

        # Tách parent thành children
        sentences = []
        for p in paras_:
            sentences.extend(_split_sentences(p))

        current_child: list[str] = []
        current_child_tokens     = 0

        def flush_child(sents: list[str]) -> None:
            nonlocal idx
            child_text = " ".join(sents)
            if not child_text.strip():
                return
            chunks.append(Chunk(
                chunk_index  = idx,
                chunk_text   = child_text,
                parent_text  = parent_text,
                source_file  = source_file,
                file_type    = file_type,
            ))
            idx += 1

        for sent in sentences:
            t = _approx_tokens(sent)
            if current_child_tokens + t > child_tokens and current_child:
                flush_child(current_child)
                # Overlap: giữ lại câu cuối làm đầu chunk mới
                current_child        = [current_child[-1], sent]
                current_child_tokens = _approx_tokens(" ".join(current_child))
            else:
                current_child.append(sent)
                current_child_tokens += t

        if current_child:
            flush_child(current_child)

    for para in paras:
        t = _approx_tokens(para)
        if current_parent_tokens + t > parent_tokens and current_parent_paras:
            flush_parent(current_parent_paras)
            # Overlap: giữ paragraph cuối
            current_parent_paras  = [current_parent_paras[-1], para]
            current_parent_tokens = sum(_approx_tokens(p) for p in current_parent_paras)
        else:
            current_parent_paras.append(para)
            current_parent_tokens += t

    if current_parent_paras:
        flush_parent(current_parent_paras)

    return chunks