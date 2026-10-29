"""单文件流水线：渲染 → 识别 → 拼装成一行回导数据。

拼装口径与 NAS 侧一致：所有页的所有文本行用单个空格连接
（NAS：页内 implode(' ')，页间 ' ' . $pageText）。服务端入库时还会再过一遍
netdisk_normalize_ocr_text，所以这里不需要也不应该做「去中文字间空格」之类的加工。
"""

from __future__ import annotations

from pathlib import Path

from . import ENGINE_LABEL
from . import render
from .scan import kind_of, sha256_file, size_of


class FileFailed(Exception):
    """单文件失败（原因会落 failed.jsonl）。"""

    def __init__(self, reason: str):
        super().__init__(reason)
        self.reason = reason


def recognize_file(path: Path, params: dict, engine, resample: str,
                   workdir: Path) -> tuple[str, int]:
    """返回 (text, pages)。失败抛 FileFailed / RenderError / OcrEngineError。"""
    dpi = int(params.get("dpi") or 0)
    max_side = int(params.get("max_side") or 0)
    pages = 0
    lines: list[str] = []
    for jpeg in render.render_pages(path, dpi, max_side, resample, workdir):
        pages += 1
        lines.extend(engine.recognize_image(jpeg))
    if pages == 0:
        raise FileFailed("no_pages")
    text = " ".join(t for t in lines if t is not None).strip()
    return text, pages


def build_item(path: Path, text: str, pages: int, content_hash: str | None = None) -> dict:
    return {
        "content_hash": content_hash or sha256_file(path),
        "size": size_of(path),
        "pages": int(pages),
        "text": text,
        "engine": ENGINE_LABEL,
        "_path": str(path),   # 内部字段：日志/失败清单用，回导前剔除
    }


def kind(path: Path) -> str:
    return kind_of(path)
