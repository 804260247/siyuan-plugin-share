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

import ctypes
import glob
import os
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
            raise OcrEngineError(_friendly_init_error(exc))

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


def gpu_summary(paddle_mod=None) -> str:
    """探测 paddle 的 GPU 能力，返回一行中文说明（供 self-check 打印）。

    GPU 是**可选**能力：本函数永不抛异常、永不改变退出码 —— 探测不到就如实说
    「仅 CPU」，能跑 CPU 跑 CPU。paddle 已被 `self-check` 的 `_ver()` 导入过，
    这里再 `__import__` 只是取已有模块对象，不会二次初始化 CUDA。
    """
    try:
        p = __import__("paddle") if paddle_mod is None else paddle_mod
    except Exception as exc:                    # 没装 paddlepaddle / 装坏了
        return "仅 CPU（未安装 paddlepaddle：%s）" % exc

    try:
        return _gpu_probe(p)
    except Exception as exc:                    # 属性访问就抛（半装/坏装的 paddle）也不许炸
        return "仅 CPU（GPU 探测异常：%s）" % exc


def _dist_label(p, gpu_compiled, ver=""):
    """「发行包名 版本」——CPU 版与 GPU 版都 import 成 `paddle`，只能靠包名区分。

    打包产物（frozen）里没有 dist-info，读不到 metadata，此时按是否编译了 CUDA
    反推包名，避免把 GPU 版 wheel 说成 `paddlepaddle`。
    """
    ver = str(ver or getattr(p, "__version__", "") or "").strip()
    names = ("paddlepaddle-gpu", "paddlepaddle") if gpu_compiled else \
            ("paddlepaddle", "paddlepaddle-gpu")
    dist = names[0]                             # 无 dist-info 时的反推默认值
    try:
        from importlib import metadata
        for cand in ("paddlepaddle-gpu", "paddlepaddle"):
            try:
                mv = metadata.version(cand)
            except Exception:
                continue
            dist = cand
            ver = str(mv or ver).strip()
            break
    except Exception:
        pass
    return ("%s %s" % (dist, ver)).strip()


def _gpu_probe(p) -> str:
    def _get(obj, name, *args):
        """属性/方法两吃：个别版本 is_compiled_with_cuda 是属性不是函数。"""
        val = getattr(obj, name, None)
        if val is None:
            return None
        if callable(val):
            try:
                return val(*args)
            except Exception:
                return None
        return val

    dev = getattr(p, "device", None)
    compiled = _get(dev, "is_compiled_with_cuda")
    if compiled is None:                        # 老版本只有 paddle.core
        compiled = _get(getattr(p, "core", None), "is_compiled_with_cuda")
    ver = str(getattr(p, "__version__", "") or "").strip()
    if not compiled:
        return ("仅 CPU：%s（CPU 版，未编译 CUDA）—— 要 GPU 请装 "
                "paddlepaddle-gpu 并加 --device gpu:0（安装命令见 README「GPU 加速」）"
                % _dist_label(p, False, ver))

    cuda = getattr(dev, "cuda", None)
    count = _get(cuda, "device_count")
    try:
        count = int(count or 0)
    except Exception:
        count = 0
    if count <= 0:
        return ("%s（GPU 版但未检测到设备）—— 已编译 CUDA 但 cuda.device_count()=0，"
                "用 nvidia-smi 确认驱动正常、显卡未被独占，见 README「GPU 加速」"
                % _dist_label(p, True, ver))

    names = []
    for i in range(count):
        name = _get(cuda, "get_device_name", i)
        names.append(str(name) if name else ("GPU %d" % i))
    cudnn = _get(cuda, "get_cudnn_version")
    bits = ["%s" % _dist_label(p, True, ver),
            "GPU 可用：%s ×%d" % (", ".join(names), count), "CUDA 已编译"]
    if cudnn:
        bits.append("cuDNN %s" % cudnn)
    else:
        bits.append("cuDNN 版本未知")
    return "，".join(bits)


# --------------------------------------------------------------------------- GPU 预检（0033）

#: Windows 版 paddlepaddle-gpu 的 cuDNN 依赖：cu126 轮子要 cuDNN 9、cu118 要 cuDNN 8。
CUDNN_CANDIDATES = ("cudnn64_9.dll", "cudnn64_8.dll")

_GPU_RUNBOOK = "README「Windows GPU 前置条件」"


def is_gpu_device(device) -> bool:
    """`gpu` / `gpu:0` / `GPU:1` 都算 GPU，`cpu` / 空串不算。"""
    return str(device or "").strip().lower().startswith("gpu")


def _paddle_libs_dir():
    """`<site-packages>/paddle/libs` —— Windows wheel 把 CUDA 运行库放这里。"""
    try:
        import paddle
    except Exception:
        return None
    base = os.path.dirname(getattr(paddle, "__file__", "") or "")
    if not base:
        return None
    libs = os.path.join(base, "libs")
    return libs if os.path.isdir(libs) else None


def _dll_probe_names():
    return list(CUDNN_CANDIDATES)


def gpu_preflight(device="gpu:0", libs_dir=None, loader=None):
    """开跑前的 GPU 运行库预检，返回 (ok, 一行说明)。

    背景：Windows 版 `paddlepaddle-gpu` 轮子**不打包 cuDNN**，运行时靠 PATH 里的
    `cudnn64_9.dll` 加载；缺了要等到 `create_pipeline()` 才炸，报的是
    `third-party dynamic library ... error code is 126`，对使用者毫无信息量。
    这里提前用 ctypes 试加载，缺就直接给出「装 cuDNN」的指引。

    - 非 GPU 设备 / 非 Windows：直接 (True, "")，不做任何事；
    - `libs_dir`、`loader` 可注入，便于在 Linux/CI 上覆盖缺库路径。
    """
    if not is_gpu_device(device):
        return True, ""
    if loader is None:
        loader = getattr(ctypes, "WinDLL", None)
    if loader is None:                      # 非 Windows：没有 WinDLL，无从预检
        return True, ""
    if libs_dir is None:
        libs_dir = _paddle_libs_dir()

    def _try(path_or_name):
        try:
            loader(path_or_name)
            return True
        except Exception:
            return False

    where = libs_dir or "（未找到 paddle\\libs）"
    found = None
    for name in _dll_probe_names():
        cand = os.path.join(libs_dir, name) if libs_dir else None
        if cand and os.path.isfile(cand):
            if _try(cand):
                found = cand
                break
        if _try(name):
            found = name
            break

    if not found:
        return False, (
            "GPU 运行库缺失：未找到可加载的 %s（已查 paddle\\libs 与 PATH：%s）。"
            "Windows 版 paddlepaddle-gpu 打包时不含 cuDNN，需自行装：cu126 轮子配 "
            "cuDNN 9.x for CUDA 12、cu118 轮子配 cuDNN 8.6（下载后把 cuDNN 的 bin 目录"
            "加入 PATH，或把 DLL 复制进 paddle\\libs）。详细步骤见 %s；"
            "想先跑起来就用 --device cpu。"
            % (" / ".join(CUDNN_CANDIDATES), where, _GPU_RUNBOOK))

    # cudart 一并探一下：只在 paddle\libs 里确实带了 cudart 时才算硬条件，
    # 否则可能来自系统 CUDA 安装，交给 paddle 自己按 PATH 找。
    cudarts = sorted(glob.glob(os.path.join(libs_dir, "cudart64_*.dll"))) if libs_dir else []
    extra = ""
    if cudarts:
        first = next((c for c in cudarts if _try(c)), None)
        if first is None:
            return False, (
                "GPU 运行库缺失：cuDNN 已就绪（%s），但 paddle\\libs 里的 CUDA 运行库"
                "（%s）加载失败，通常是缺配套的 CUDA 运行库或 PATH 上装了版本冲突的同名 DLL。"
                "见 %s，或先用 --device cpu。" % (os.path.basename(found),
                                              ", ".join(os.path.basename(c) for c in cudarts),
                                              _GPU_RUNBOOK))
        extra = "，CUDA 运行库 %s" % os.path.basename(first)
    return True, "GPU 运行库就绪：%s%s" % (
        os.path.basename(found) if os.path.sep in str(found) else found, extra)


_DLL_ERROR_HINTS = ("third-party dynamic library", "dynamic library", "cudnn64",
                    "cudart64", "cublas64", "error code is 126")


def _friendly_init_error(exc) -> str:
    """把 paddle 的原生 DLL 报错换成能照着做的说明（0033）。

    真机报的是 `(PreconditionNotMet) The third-party dynamic library
    (cudnn64_9.dll) ... error code is 126`，用户看不懂也定位不到。
    原始首行只在 `OCR_WIN_DEBUG=1` 时附带，避免默认就把原生报错糊到脸上。
    """
    text = str(exc).strip() or exc.__class__.__name__
    low = text.lower()
    missing = ""
    for name in ("cudnn64_9.dll", "cudnn64_8.dll", "cudart64_12.dll", "cudart64_110.dll"):
        if name in low:
            missing = name
            break
    if not (missing or any(h in low for h in _DLL_ERROR_HINTS)):
        return "OCR 流水线初始化失败：%s" % text
    why = ("缺少 %s" % missing) if missing else "缺少 CUDA / cuDNN 动态库"
    msg = ("OCR 流水线初始化失败：GPU 运行库未就绪（%s）。Windows 版 paddlepaddle-gpu "
           "不含 cuDNN，需自行安装（cu126 轮子配 cuDNN 9.x for CUDA 12，cu118 轮子配 "
           "cuDNN 8.6），步骤见 %s；也可以直接用 --device cpu 先跑。"
           % (why, _GPU_RUNBOOK))
    if os.environ.get("OCR_WIN_DEBUG"):
        msg += "｜原始报错：%s" % text.splitlines()[0][:300]
    return msg


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
