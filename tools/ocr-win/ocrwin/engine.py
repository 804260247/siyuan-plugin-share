"""本地 OCR 引擎适配：paddlex[ocr]（与 NAS 侧车同版本、同模型）。

引擎只暴露一件事：`recognize_image(jpeg_path) -> list[str]`（该页的文本行）。
文本行的拼装口径与 NAS 侧完全一致：
  NAS：单页 `implode(' ', rec_texts)`，页间 `$text .= ' ' . $pageText`
  本工具：所有页的所有行合并后用单个空格连接 —— 归一化（NAS 侧
  netdisk_normalize_ocr_text 会把空白折叠并去掉中文字间空格）之后两者等价。

PaddleX 的结果对象形状在 3.x 各小版本间有出入（dict / 带 .json 属性的对象 /
{'res': {...}} 包裹），这里做容错提取，不做版本断言。
"""

from __future__ import annotations

import threading

from . import ENGINE_LABEL


class OcrEngineError(Exception):
    pass


def extract_rec_texts(result) -> list[str]:
    """从 PaddleX OCR 结果里尽力取出 rec_texts（与 NAS 侧读的字段同名）。"""
    out: list[str] = []

    def walk(obj, depth: int):
        if depth > 8 or obj is None:
            return
        if isinstance(obj, dict):
            rt = obj.get("rec_texts")
            if isinstance(rt, (list, tuple)):
                out.extend("" if t is None else str(t) for t in rt)
                return
            for value in obj.values():
                walk(value, depth + 1)
            return
        if isinstance(obj, (list, tuple)):
            for value in obj:
                walk(value, depth + 1)
            return
        for attr in ("json", "res", "prunedResult", "pruned_result"):
            if hasattr(obj, attr):
                try:
                    val = getattr(obj, attr)
                except Exception:
                    continue
                if callable(val):
                    try:
                        val = val()
                    except Exception:
                        continue
                walk(val, depth + 1)

    # 结果对象本身可能是 dict，也可能是带 .json 的包装对象
    walk(result, 0)
    if out:
        return out
    # 最后一档：有些版本支持 dict(result)
    try:
        walk(dict(result), 0)  # type: ignore[arg-type]
    except Exception:
        pass
    return out


class PaddleEngine:
    """paddlex 3.0.3 的 OCR 流水线；一个实例对应一份模型（约占数百 MB 内存）。"""

    label = ENGINE_LABEL

    def __init__(self, device: str = "cpu"):
        self.device = device
        self._pipeline = None
        self._lock = threading.Lock()

    def _ensure(self):
        if self._pipeline is not None:
            return
        try:
            from paddlex import create_pipeline
        except ImportError as exc:
            raise OcrEngineError("缺少 paddlex（pip install \"paddlex[ocr]==3.0.3\"）：%s" % exc)
        kwargs = {"pipeline": "OCR"}
        if self.device:
            kwargs["device"] = self.device
        try:
            self._pipeline = create_pipeline(**kwargs)
        except TypeError:
            # 个别版本不接受 device 关键字
            self._pipeline = create_pipeline(pipeline="OCR")
        except Exception as exc:
            raise OcrEngineError("OCR 流水线初始化失败：%s" % exc)

    def ensure_ready(self):
        """公开的初始化检查（启动阶段 fail-fast 用）。"""
        self._ensure()

    def recognize_image(self, jpeg_path) -> list[str]:
        self._ensure()
        try:
            with self._lock:  # paddlex pipeline 非线程安全，同实例串行
                pages = list(self._pipeline.predict(input=str(jpeg_path)))
        except Exception as exc:
            raise OcrEngineError("识别失败：%s" % exc)
        lines: list[str] = []
        for res in pages:
            lines.extend(extract_rec_texts(res))
        return lines


_local = threading.local()


def get_engine(device: str = "cpu"):
    """线程内单例：每个 worker 线程各自一份引擎（pipeline 不跨线程共享）。

    --workers 1 时全进程只有一份模型；>1 时按线程各建一份，内存随之翻倍——
    README 里写明 16 GB 机器建议 ≤2。
    """
    eng = getattr(_local, "engine", None)
    if eng is None:
        eng = PaddleEngine(device=device)
        _local.engine = eng
    return eng


def release_engine():
    _local.engine = None
