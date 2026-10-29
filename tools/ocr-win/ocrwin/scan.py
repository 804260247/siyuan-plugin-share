"""本地目录递归扫描 + 内容哈希（0026 任务书 4.2）。

只处理 `.pdf` 与常见位图；其他扩展名跳过并计数（任务书用例 1）。
哈希算**原始文件字节**，与 NAS 侧 `netdisk_index.content_hash` 同一口径，
这样回导的结果才能按 (content_hash, params_key) 命中。
"""

from __future__ import annotations

import hashlib
import os
from pathlib import Path

PDF_EXT = {".pdf"}
IMAGE_EXT = {".png", ".jpg", ".jpeg", ".bmp", ".tif", ".tiff", ".webp"}
ALLOWED_EXT = PDF_EXT | IMAGE_EXT

_CHUNK = 1024 * 1024


def kind_of(path: Path) -> str:
    return "pdf" if path.suffix.lower() in PDF_EXT else "image"


def iter_files(root: Path, exclude_dirs=()) -> tuple[list[Path], list[Path]]:
    """递归列举，返回 (待处理文件, 被扩展名跳过的文件)；顺序确定（目录名与文件名均排序）。"""
    ex = {os.path.normcase(os.path.abspath(str(d))) for d in exclude_dirs}
    allowed: list[Path] = []
    skipped: list[Path] = []
    for dirpath, dirnames, filenames in os.walk(str(root)):
        keep = []
        for d in sorted(dirnames):
            full = os.path.abspath(os.path.join(dirpath, d))
            if os.path.normcase(full) in ex or d in (".git",):
                continue
            keep.append(d)
        dirnames[:] = keep
        for name in sorted(filenames):
            p = Path(dirpath) / name
            if p.suffix.lower() in ALLOWED_EXT:
                allowed.append(p)
            else:
                skipped.append(p)
    return allowed, skipped


def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with open(path, "rb") as fh:
        while True:
            chunk = fh.read(_CHUNK)
            if not chunk:
                break
            h.update(chunk)
    return h.hexdigest()


def size_of(path: Path) -> int:
    try:
        return int(path.stat().st_size)
    except OSError:
        return 0
