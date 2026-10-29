"""本地渲染：PDF / 图片 → 供 OCR 的 JPEG 页图。

与 NAS 侧的渲染口径对齐（这是「字符数差异 < 5%」的前提，0026 任务书 4.4 用例 6）：
  NAS：pdftoppm -r <dpi> -png → GD 缩放（长边 ≤ max_side，双线性）→ imagejpeg q85 → base64
  本工具：PyMuPDF 按 dpi/72 渲染（RGB、无 alpha）→ PIL 缩放（长边 ≤ max_side，默认双线性）
          → JPEG q85 落临时文件

**参数一律来自服务端 params**（dpi / max_side），不写死。PyMuPDF 在 Windows 打包后
自带 MuPDF，不需要 poppler/pdftoppm。
"""

from __future__ import annotations

import os
from pathlib import Path
from typing import Iterator

from .scan import kind_of

# 单页渲染像素上限（防脏参数把本机内存打满；A4@400DPI ≈ 15.5MP，留足余量）
MAX_RENDER_PIXELS = 120_000_000
JPEG_QUALITY = 85

RESAMPLE_CHOICES = ("bilinear", "lanczos", "bicubic")


class RenderError(Exception):
    pass


class DependencyError(Exception):
    pass


def _import_pymupdf():
    try:
        import pymupdf as _m  # PyMuPDF ≥1.24 的官方新名字
        return _m
    except ImportError:
        pass
    try:
        import fitz as _m  # 旧名字（1.23 及以前 / 部分发行版）
        return _m
    except ImportError:
        raise DependencyError("缺少 PyMuPDF（pip install PyMuPDF）")


def _import_pil():
    try:
        from PIL import Image
        return Image
    except ImportError:
        raise DependencyError("缺少 Pillow（pip install Pillow）")


def _resample(name: str):
    Image = _import_pil()
    table = {
        "bilinear": Image.BILINEAR,   # 与 NAS 侧 GD imagecopyresampled 同一算法（默认）
        "lanczos": Image.LANCZOS,
        "bicubic": Image.BICUBIC,
    }
    return table.get((name or "bilinear").lower(), Image.BILINEAR)


def fit_max_side(img, max_side: int, resample: str):
    """长边超过 max_side 才缩（只缩不放），与 NAS 侧 scale 计算逐式一致。"""
    if max_side <= 0:
        return img
    w, h = img.size
    if w <= max_side and h <= max_side:
        return img
    scale = (max_side / w) if w >= h else (max_side / h)
    nw = max(1, int(round(w * scale)))
    nh = max(1, int(round(h * scale)))
    return img.resize((nw, nh), _resample(resample))


def _save_jpeg(img, out_path: Path):
    img.convert("RGB").save(str(out_path), "JPEG", quality=JPEG_QUALITY, optimize=False)


def page_count(path: Path) -> int:
    """PDF 页数（与 NAS 侧 pdfinfo 的 Pages 同义）；图片按帧数，至少 1。"""
    if kind_of(path) == "pdf":
        mupdf = _import_pymupdf()
        try:
            with mupdf.open(str(path)) as doc:
                return int(doc.page_count)
        except Exception as exc:
            raise RenderError("PDF 打不开：%s" % exc)
    Image = _import_pil()
    try:
        with Image.open(str(path)) as img:
            return int(getattr(img, "n_frames", 1) or 1)
    except Exception as exc:
        raise RenderError("图片打不开：%s" % exc)


def render_pages(path: Path, dpi: int, max_side: int, resample: str,
                 workdir: Path) -> Iterator[Path]:
    """逐页产出 JPEG 临时文件路径（PDF 按页、多帧 TIFF 按帧）。"""
    if kind_of(path) == "pdf":
        yield from _render_pdf(path, dpi, max_side, resample, workdir)
    else:
        yield from _render_image(path, max_side, resample, workdir)


def _render_pdf(path: Path, dpi: int, max_side: int, resample: str,
                workdir: Path) -> Iterator[Path]:
    mupdf = _import_pymupdf()
    Image = _import_pil()
    zoom = float(dpi) / 72.0
    try:
        doc = mupdf.open(str(path))
    except Exception as exc:
        raise RenderError("PDF 打不开：%s" % exc)
    try:
        matrix = mupdf.Matrix(zoom, zoom)
        for i in range(int(doc.page_count)):
            page = doc.load_page(i)
            try:
                pix = page.get_pixmap(matrix=matrix, alpha=False, colorspace=mupdf.csRGB)
            except Exception as exc:
                raise RenderError("第 %d 页渲染失败：%s" % (i + 1, exc))
            if pix.width * pix.height > MAX_RENDER_PIXELS:
                raise RenderError("第 %d 页渲染像素过多：%dx%d（上限 %d）"
                                  % (i + 1, pix.width, pix.height, MAX_RENDER_PIXELS))
            img = Image.frombytes("RGB", (pix.width, pix.height), pix.samples)
            img = fit_max_side(img, max_side, resample)
            out = Path(workdir) / ("p%05d.jpg" % (i + 1))
            _save_jpeg(img, out)
            del img, pix
            yield out
    finally:
        try:
            doc.close()
        except Exception:
            pass


def _render_image(path: Path, max_side: int, resample: str,
                  workdir: Path) -> Iterator[Path]:
    Image = _import_pil()
    try:
        img = Image.open(str(path))
    except Exception as exc:
        raise RenderError("图片打不开：%s" % exc)
    try:
        n = int(getattr(img, "n_frames", 1) or 1)
        for i in range(n):
            if n > 1:
                img.seek(i)
            frame = img.convert("RGB")
            frame = fit_max_side(frame, max_side, resample)
            out = Path(workdir) / ("i%05d.jpg" % (i + 1))
            _save_jpeg(frame, out)
            del frame
            yield out
    finally:
        try:
            img.close()
        except Exception:
            pass


def cleanup(workdir: Path):
    """删除本次文件产生的页图（异常路径也必须走到，避免像 NAS 侧那样留一地 /tmp/ndo_*）。"""
    try:
        for name in os.listdir(str(workdir)):
            if name.endswith(".jpg"):
                try:
                    os.unlink(os.path.join(str(workdir), name))
                except OSError:
                    pass
    except OSError:
        pass
