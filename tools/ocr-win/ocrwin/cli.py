"""命令行入口：`ocr-win run …` / `ocr-win self-check`。

退出码（README 与交付说明同口径）：
  0   全部成功（或 dry-run/扫描完成）
  1   跑完但有文件失败（失败清单在 failed.jsonl）
  2   启动失败：参数拉不到 / 鉴权失败 / 输入目录不可用
  3   params_key 与服务端不一致（服务端回带 expected_params_key）
  4   本机依赖缺失（PyMuPDF / Pillow / paddlex）
  130 用户中断（Ctrl+C），已把缓冲中的结果尽量提交
"""

from __future__ import annotations

import argparse
import concurrent.futures as cf
import os
import platform
import shutil
import sys
import tempfile
import time
import unicodedata
from pathlib import Path

from . import __version__
from . import engine as engine_mod
from . import httpapi
from . import params as params_mod
from . import pydeps
from . import render, runner, scan
from .httpapi import ApiClient, ApiError
from .state import StateStore
from .upload import DEFAULT_BATCH_MAX, DEFAULT_BYTES_MAX, ParamsMismatch, Uploader

EXIT_OK = 0
EXIT_PARTIAL = 1
EXIT_STARTUP = 2
EXIT_PARAMS_MISMATCH = 3
EXIT_DEPENDENCY = 4
EXIT_INTERRUPTED = 130

STATE_DIR_NAME = ".ocr-win-state"
MAX_WORKERS = 8

#: 子命令别名（带横杠的写法是第一遍就会被人敲的，容忍掉）
ALIASES = {
    "--self-check": "self-check",
    "-self-check": "self-check",
    "--self_check": "self-check",
    "selfcheck": "self-check",
}


def log(msg: str):
    print("[ocr-win] %s" % msg, flush=True)


# --------------------------------------------------------------------------- 参数解析

def build_parser() -> argparse.ArgumentParser:
    p = argparse.ArgumentParser(
        prog="ocr-win",
        description="PC 端离线 OCR 工具：本地渲染+识别，结果经 HTTP 回导 NAS（绝不直连 app.db）",
    )
    p.add_argument("--version", action="version", version="ocr-win %s" % __version__)
    sub = p.add_subparsers(dest="cmd")

    r = sub.add_parser("run", help="扫描本地目录并把识别结果回导（默认子命令）")
    r.add_argument("--input", required=True, help="本地扫描目录（递归）")
    r.add_argument("--server", required=True, help="NAS 站点根地址，如 http://192.168.1.111:3003")
    r.add_argument("--token", default="", help="后台 API Key（X-Api-Key）；会话 cookie 方式不支持")
    r.add_argument("--params-url", default=None,
                   help="覆盖参数接口地址（默认 <server>/admin/netdisk/ocr/params）")
    r.add_argument("--workers", type=int, default=1, help="并发 worker 数（默认 1；每 worker 一份模型）")
    r.add_argument("--resume", dest="resume", action="store_true", default=True,
                   help="断点续跑：本地 done.jsonl 已有的 (hash, params_key) 跳过（默认开启）")
    r.add_argument("--no-resume", dest="resume", action="store_false", help="关闭断点续跑，全部重扫")
    r.add_argument("--dry-run", action="store_true", help="只扫描+算哈希+校验参数，不渲染/不识别/不回导")
    r.add_argument("--out-dir", default=None, help="状态目录（默认 <input>/.ocr-win-state）")
    r.add_argument("--batch-size", type=int, default=DEFAULT_BATCH_MAX, help="单批行数上限（≤50）")
    r.add_argument("--batch-bytes", type=int, default=DEFAULT_BYTES_MAX, help="单批请求体上限（≤4 MB）")
    r.add_argument("--device", default="cpu",
                   help="paddlex 设备（默认 cpu；gpu:0 需装 paddlepaddle-gpu 与 cuDNN，"
                        "见 README「Windows GPU 前置条件」，GPU 下 --workers 必须 1）")
    r.add_argument("--resample", default="bilinear",
                   choices=list(render.RESAMPLE_CHOICES),
                   help="缩放到 max_side 的插值算法（默认 bilinear，与 NAS 侧 GD 同算法）")

    s = sub.add_parser("self-check", help="打印环境自检（版本/依赖/模型目录/参数拉取）")
    s.add_argument("--server", default="", help="可选：顺带验证参数接口可达")
    s.add_argument("--token", default="", help="可选：API Key")
    s.add_argument("--params-url", default=None, help="可选：覆盖参数接口地址")
    s.add_argument("--no-pipeline", action="store_true",
                   help="跳过 OCR 流水线初始化（只查依赖，不加载模型/不下载模型）")
    return p


# --------------------------------------------------------------------------- 单文件流水线

def _one_file(path: Path, content_hash: str, params: dict, device: str,
              resample: str, tmp_root: Path) -> dict:
    workdir = Path(tempfile.mkdtemp(prefix="f-", dir=str(tmp_root)))
    result = {"path": path, "hash": content_hash, "ok": False,
              "reason": "unknown", "attempts": 0, "item": None, "secs": 0.0}
    t0 = time.time()
    try:
        for attempt in (1, 2):            # 任务书 4.3：单文件最多 2 次
            result["attempts"] = attempt
            try:
                eng = engine_mod.get_engine(device)
                text, pages = runner.recognize_file(path, params, eng, resample, workdir)
                if not text.strip():
                    raise runner.FileFailed("empty_text")
                result["item"] = runner.build_item(path, text, pages, content_hash)
                result["ok"] = True
                result["reason"] = ""
                return result
            except (runner.FileFailed, render.RenderError, engine_mod.OcrEngineError) as exc:
                reason = getattr(exc, "reason", None) or str(exc).strip() or exc.__class__.__name__
                result["reason"] = reason[:200]
                if attempt == 1:
                    log("重试 %s：%s" % (path.name, result["reason"]))
            except Exception as exc:       # 兜底：单文件异常不许拖死整批
                result["reason"] = ("exception: %s" % exc)[:200]
                if attempt == 1:
                    log("重试 %s：%s" % (path.name, result["reason"]))
            finally:
                render.cleanup(workdir)
        return result
    finally:
        result["secs"] = round(time.time() - t0, 2)
        shutil.rmtree(str(workdir), ignore_errors=True)


def _apply(result: dict, store: StateStore, uploader: Uploader, stats: dict):
    secs = float(result.get("secs") or 0.0)
    stats["secs"] += secs                  # 每文件渲染+识别的累计耗时（多 worker 时是各文件之和）
    if result["ok"]:
        item = result["item"]
        pages = int(item.get("pages") or 0)
        stats["pages"] += pages
        uploader.add(item)                 # add() 内部按需 flush
        stats["ocr_ok"] += 1
        # 逐文件耗时/页数：判断「慢在渲染还是慢在识别」的唯一依据，
        # 此前 result["secs"] 与 pages 一直是只算不打（0032）。
        log("完成 %s pages=%d 识别=%.1f 秒（%.2f 秒/页）"
            % (result["path"].name, pages, secs, (secs / pages) if pages else 0.0))
        return
    stats["failed"] += 1
    store.mark_failed(result["hash"], result["reason"], result["attempts"], str(result["path"]))
    log("失败 %s：%s" % (result["path"].name, result["reason"]))


# --------------------------------------------------------------------------- run

def cmd_run(a) -> int:
    inp = Path(os.path.expanduser(a.input))
    if not inp.is_dir():
        log("输入目录不存在或不是目录：%s" % inp)
        return EXIT_STARTUP
    inp = inp.resolve()
    out_dir = Path(os.path.expanduser(a.out_dir)) if a.out_dir else (inp / STATE_DIR_NAME)
    try:
        out_dir.mkdir(parents=True, exist_ok=True)
    except OSError as exc:
        log("状态目录创建失败：%s（%s）" % (out_dir, exc))
        return EXIT_STARTUP
    store = StateStore(out_dir)

    client = ApiClient(a.server, a.token, params_url=a.params_url)
    log("拉取服务端参数：%s" % client.params_url)
    try:
        raw = client.get_params()
    except ApiError as exc:
        log("参数拉取失败：%s" % exc)
        log("按任务书要求不使用本地默认值继续，直接退出。请检查 --server / --token / 网络。")
        return EXIT_STARTUP
    try:
        params = params_mod.validate(raw)
    except params_mod.ParamError as exc:
        log("参数回包不可用：%s" % exc)
        return EXIT_STARTUP
    log("服务端参数：" + params_mod.describe(params))

    files, skipped_ext = scan.iter_files(inp, exclude_dirs=[out_dir])
    log("扫描完成：可处理 %d 个、扩展名跳过 %d 个（%s）" % (len(files), len(skipped_ext), inp))

    done = store.load_done() if a.resume else {}
    todo: list[tuple[Path, str]] = []
    seen: set[str] = set()
    skipped_done = 0
    dup = 0
    unreadable = 0
    for p in files:
        try:
            h = scan.sha256_file(p)
        except OSError as exc:
            unreadable += 1
            store.mark_failed("", "read_error: %s" % exc, 1, str(p))
            log("读不了：%s（%s）" % (p, exc))
            continue
        if ("%s|%s" % (h, params["params_key"])) in done:
            skipped_done += 1
            continue
        if h in seen:                      # 同内容多副本：只识别一次
            dup += 1
            continue
        seen.add(h)
        todo.append((p, h))

    log("续跑过滤后待识别 %d 个（已完成跳过 %d、同内容重复 %d、不可读 %d）"
        % (len(todo), skipped_done, dup, unreadable))

    if a.dry_run:
        log("dry-run：不渲染、不识别、不回导。以下为将要处理的前 10 个文件：")
        for p, h in todo[:10]:
            log("  %s  %s  %d 字节" % (h[:16], p, scan.size_of(p)))
        log("dry-run 汇总：可处理 %d、扩展名跳过 %d、已完成跳过 %d、同内容重复 %d"
            % (len(files), len(skipped_ext), skipped_done, dup))
        return EXIT_OK

    # 待识别 0 个就到此为止（0033）：旧实现无条件往下走 ensure_ready()，
    # 续跑全部命中的场景也会去建 pipeline —— GPU 版 paddle 缺 cuDNN 时明明
    # 一个文件都不用处理，却会崩在引擎初始化上（真机就是这样炸的），
    # 还会顺带下载几百 MB 模型。
    if not todo:
        log("待识别 0 个，跳过引擎初始化（不建 pipeline、不下载模型、不回导）")
        log("汇总：扫描 %d、扩展名跳过 %d、已完成跳过 %d、同内容重复 %d、不可读 %d"
            % (len(files), len(skipped_ext), skipped_done, dup, unreadable))
        log("汇总：识别成功 0、失败 0、回导 accepted=0 rejected=0 批次=0 用时 0.0 秒")
        return EXIT_OK

    # 依赖预检：缺依赖直接退，不要跑到一半才发现
    try:
        render._import_pymupdf()
        render._import_pil()
    except render.DependencyError as exc:
        log("依赖缺失：%s" % exc)
        return EXIT_DEPENDENCY
    workers = max(1, min(int(a.workers or 1), MAX_WORKERS))
    # GPU 预检（0033）：显存与运行库两个坑都必须在开跑前拦住，不许静默继续。
    #   ① GPU 下每 worker 一份模型，8 GB 显存跑 2 份通常直接 OOM；
    #   ② Windows 版 paddlepaddle-gpu 不带 cuDNN，缺 DLL 会等到 create_pipeline()
    #      才抛 `error code is 126`，用户看不懂 —— 这里 ctypes 提前试加载。
    if engine_mod.is_gpu_device(a.device):
        if workers > 1:
            log("GPU 模式下 --workers 必须为 1（每 worker 一份模型，8 GB 显存会 OOM）："
                "当前 --workers %d，请改成 --workers 1 重跑" % workers)
            return EXIT_STARTUP
        gpu_ok, gpu_msg = engine_mod.gpu_preflight(a.device)
        if not gpu_ok:
            log(gpu_msg)
            return EXIT_DEPENDENCY
        if gpu_msg:
            log(gpu_msg)
    try:
        engine_mod.get_engine(a.device).ensure_ready()
    except engine_mod.OcrEngineError as exc:
        log("OCR 引擎不可用：%s" % exc)
        return EXIT_DEPENDENCY

    uploader = Uploader(client, params["params_key"], store, log,
                        batch_max=a.batch_size, bytes_max=a.batch_bytes)
    stats = {"ocr_ok": 0, "failed": 0, "pages": 0, "secs": 0.0}
    tmp_root = Path(tempfile.mkdtemp(prefix="ocr-win-"))
    t0 = time.time()
    total = len(todo)

    def _progress(idx: int):
        if total and (idx % 10 == 0 or idx == total):
            el = time.time() - t0
            rate = (idx / el) if el > 0 else 0
            # 「已导入」永远是 0：回导是按批缓冲的（batch_max=50），凑满一批才 POST，
            # 进度行看到的只能是缓冲区里的行数，不是服务端已收下的行数（0032 改文案）。
            log("进度 %d/%d（成功 %d、失败 %d、已缓冲 %d 行）%.2f 个/秒"
                % (idx, total, stats["ocr_ok"], stats["failed"],
                   len(uploader.items), rate))

    try:
        with cf.ThreadPoolExecutor(max_workers=workers) as ex:
            futures = [ex.submit(_one_file, p, h, params, a.device, a.resample, tmp_root)
                       for p, h in todo]
            for idx, fut in enumerate(cf.as_completed(futures), 1):
                _apply(fut.result(), store, uploader, stats)
                _progress(idx)
        uploader.close()
    except ParamsMismatch as exc:
        log("服务端拒绝：整批 params_key 不一致")
        log("  服务端期望：%s" % exc.expected)
        log("  本地使用  ：%s（来自服务端 /params —— 通常说明 NAS 侧刚改过 DPI/档位，重跑即可）"
            % params["params_key"])
        return EXIT_PARAMS_MISMATCH
    except KeyboardInterrupt:
        log("收到中断，正在提交缓冲中的已识别结果……")
        try:
            uploader.close()
        except ParamsMismatch as exc:
            log("缓冲结果因 params_key 不一致被拒：%s" % exc.expected)
        return EXIT_INTERRUPTED
    finally:
        shutil.rmtree(str(tmp_root), ignore_errors=True)

    el = time.time() - t0
    failed_total = stats["failed"] + uploader.stats["too_large"]
    log("汇总：扫描 %d、扩展名跳过 %d、已完成跳过 %d、同内容重复 %d、不可读 %d"
        % (len(files), len(skipped_ext), skipped_done, dup, unreadable))
    log("汇总：识别成功 %d、失败 %d、回导 accepted=%d rejected=%d 批次=%d 用时 %.1f 秒"
        % (stats["ocr_ok"], failed_total, uploader.stats["accepted"],
           uploader.stats["rejected"] + uploader.stats["too_large"],
           uploader.batches, el))
    pages_total = stats["pages"]
    total_secs = stats["secs"]
    avg_page = (total_secs / pages_total) if pages_total else 0.0
    ppm = (pages_total * 60.0 / el) if el > 0 else 0.0
    log("汇总：总页数 %d、平均 %.2f 秒/页（逐文件累计 %.1f 秒）、%.2f 页/分钟"
        % (pages_total, avg_page, total_secs, ppm))
    if uploader.stats["upload_failed"]:
        log("汇总：整批上传失败 %d 行（详见 failed.jsonl，重跑会自动重试这些文件）"
            % uploader.stats["upload_failed"])
    # 被服务端拒收的行同样算「没落地」：不当作成功，退出码非 0
    rejected_total = uploader.stats["rejected"] + uploader.stats["too_large"]
    if failed_total or rejected_total or uploader.stats["upload_failed"]:
        log("失败清单：%s" % store.failed_path)
        return EXIT_PARTIAL
    return EXIT_OK


# --------------------------------------------------------------------------- self-check

def _pad(label: str, width: int = 14) -> str:
    """按显示宽度补空格（CJK 双宽，% -Ns 对不齐）。"""
    w = 0
    for ch in label:
        w += 2 if unicodedata.east_asian_width(ch) in ("W", "F") else 1
    return label + " " * max(1, width - w)


def _ver(module: str):
    try:
        m = __import__(module)
    except Exception:
        return None
    return str(getattr(m, "__version__", "unknown"))


def _packaging_probe():
    """`packaging` 必须在产物内可 import（0034 第 9 条）。

    `paddlex/utils/deps.py` 在**导入期**就 `from packaging.requirements import
    Requirement`，并用它逐条解析 `importlib.metadata.requires("paddlex")` 的
    `Requires-Dist`。PyInstaller 漏收 `packaging` 时抛的是 `ImportError`，
    症状（一启动就崩）与「元数据缺失」（能启动、报 extras 未安装）完全不同，
    所以这条必须单独断言，不能混进 metadata 维度。

    只做纯检查：不 import paddlex（避免与自检的流水线初始化互相干扰）。
    """
    try:
        from packaging.requirements import Requirement
        import packaging.version  # noqa: F401  deps.py 也会用到版本解析
    except Exception as exc:            # ImportError / 损坏的包都算不可用
        return False, "不可 import（%s）" % exc
    try:
        req = Requirement('opencv-contrib-python==4.10.0.84; extra == "ocr"')
        _ = (req.name, req.extras)
    except Exception as exc:
        return False, "可 import 但解析 Requires-Dist 失败（%s）" % exc
    return True, "可 import，Requirement 可解析 Requires-Dist"


def cmd_self_check(a) -> int:
    log("self-check 版本 %s" % __version__)
    print("  python      : %s" % sys.version.split()[0])
    print("  platform    : %s" % platform.platform())
    print("  executable  : %s" % sys.executable)
    print("  http 传输   : %s" % httpapi.default_transport().name)
    # GPU 是可选加速：探测失败/没有 CUDA 一律只打印、不改 rc（0032）。
    try:
        gpu_line = engine_mod.gpu_summary()
    except Exception as exc:                    # 探测自身出错也不许拖垮自检
        gpu_line = "探测失败（忽略，按 CPU 处理）：%s" % exc
    print("  %s: %s" % (_pad("GPU"), gpu_line))

    mupdf_ver = _ver("pymupdf") or _ver("fitz")
    pil_ver = _ver("PIL")
    pdx_ver = _ver("paddlex")
    paddle_ver = _ver("paddle")
    onnx_ver = _ver("onnxruntime")
    model_dir = os.environ.get("PADDLE_PDX_CACHE_HOME") or os.path.join(
        os.path.expanduser("~"), ".paddlex")

    def line(name: str, value, required: bool):
        ok = value is not None
        print("  %s: %s%s" % (_pad(name), value if ok else "未安装",
                                 "" if ok else ("  ✗ 必需" if required else "  （可选）")))
        return ok

    has_mupdf = line("PyMuPDF", mupdf_ver, True)
    has_pil = line("Pillow", pil_ver, True)
    has_pdx = line("paddlex", pdx_ver, True)
    line("paddlepaddle", paddle_ver, True)
    line("onnxruntime", onnx_ver, False)
    print("  %s: %s%s" % (_pad("模型目录"), model_dir,
                             "" if os.path.isdir(model_dir) else "（不存在，首次运行会自动下载）"))

    rc = EXIT_OK
    if not (has_mupdf and has_pil and has_pdx):
        log("自检结论：关键依赖缺失，先按 README 装依赖再跑")
        rc = EXIT_DEPENDENCY

    # --- OCR extras 必须真查（0031），而且必须查**两个维度**（0034）：
    #     ① 模块在不在 —— `find_spec`，对应 `--collect-all` 缺失；
    #     ② 发行包元数据在不在 —— `importlib.metadata.version()`，对应
    #        `--copy-metadata` 缺失。paddlex 判定 extras 用的正是后者
    #        （`paddlex/utils/deps.py:is_dep_available`），不看 import。
    #        打包产物里模块都在、但 `.dist-info` 不在时，paddlex 照样报
    #        `OCR requires additional dependencies` —— 真机就是这么炸的。
    frozen = bool(getattr(sys, "frozen", False))
    ocr_pairs = []
    miss = []
    meta_missing = []
    if has_pdx:
        names = pydeps.requirement_names()
        if names:
            print("  %s: %d 个（从 paddlex[ocr] 元数据派生）"
                  % (_pad("OCR 依赖"), len(names)))
        else:
            print("  %s: 读不到 paddlex[ocr] 的 extras（元数据缺失）"
                  % _pad("OCR 依赖"))
        # 模块维度：源码模式与打包产物都查（PyInstaller 的 --copy-metadata
        # 会把整个 .dist-info 复制进包，top_level.txt 在；缺它时 pydeps 用
        # RECORD 兜底，两种环境下模块名都能算对）。
        ocr_pairs = pydeps.module_pairs()
        miss = pydeps.missing_modules(ocr_pairs)
        if miss:
            for dist, mod in miss:
                print("  %s: import %s 失败（发行包 %s）" % (_pad("缺失"), mod, dist))
            log("自检结论：OCR 依赖不完整，缺 %s"
                % ", ".join(sorted({d for d, _ in miss})))
            rc = EXIT_DEPENDENCY
        # 元数据维度：paddlex 自己 + 每个 extras 发行包。frozen 下 paddlex
        # 自身元数据缺失也要能认出来（否则连 extras 清单都读不出来）。
        already = {d for d, _ in miss}          # 模块都缺的包不重复报元数据
        meta_missing = [n for n in pydeps.missing_metadata(pydeps.metadata_names())
                        if n not in already]
        for name in meta_missing:
            print("  %s: %s（发行包已打包但无 dist-info，paddlex 会误判 extras 未安装，"
                  "需 --copy-metadata）" % (_pad("元数据缺失"), name))
        if meta_missing:
            log("自检结论：%d 个发行包的元数据缺失，paddlex 会判定 extras 未安装%s"
                % (len(meta_missing),
                   "（打包时漏了 --copy-metadata，见 README「打包 exe」）" if frozen else ""))
            rc = EXIT_DEPENDENCY

    # --- `packaging` 可 import 断言（0034 第 9 条，详见 _packaging_probe）。
    #     paddlex 装了就必须有它（`deps.py` 导入期硬依赖）；paddlex 不在时
    #     这条没有意义，如实跳过、不误报。
    if has_pdx:
        packaging_ok, packaging_msg = _packaging_probe()
        print("  %s: %s" % (_pad("packaging"), packaging_msg))
        if not packaging_ok:
            log("自检结论：packaging 不可用 —— paddlex 导入期就会抛 ImportError，"
                "与「元数据缺失」是两条不同的失败路径（打包漏 collect packaging，"
                "见 README「打包 exe」）")
            rc = EXIT_DEPENDENCY

    # --- OCR 流水线真初始化（默认执行；--no-pipeline 只跳过这一步）。
    #     依赖缺失在 paddlex 3.x 是在 create_pipeline 里才抛的（懒导入），
    #     所以这里必须真构造一次，不能只查版本号。
    if rc == EXIT_OK and has_pdx:
        if getattr(a, "no_pipeline", False):
            print("  %s: 已跳过（--no-pipeline）" % _pad("OCR 流水线"))
        else:
            log("初始化 OCR 流水线（pipeline=OCR device=cpu，首次会下载模型，数百 MB）…")
            try:
                engine_mod.PaddleEngine(device="cpu").ensure_ready()
            except Exception as exc:            # 依赖缺失 / 模型损坏都落这里
                print("  %s: %s" % (_pad("OCR 流水线"), exc))
                miss = pydeps.missing_modules(ocr_pairs)
                if miss:
                    print("  %s: %s" % (_pad("缺失依赖"), ", ".join(
                        sorted({"%s（%s）" % (mod, dist) for dist, mod in miss}))))
                if meta_missing:
                    log("自检结论：OCR 流水线初始化失败，且已定位到 %d 个发行包缺元数据"
                        "（补 --copy-metadata 后重新打包）" % len(meta_missing))
                else:
                    log("自检结论：OCR 流水线初始化失败"
                        "（打包漏 extras 就是这样，见 README「打包 exe」）")
                rc = EXIT_DEPENDENCY
            else:
                print("  %s: 初始化成功" % _pad("OCR 流水线"))

    if rc == EXIT_OK:
        log("自检结论：关键依赖齐全（含 OCR extras 与流水线初始化）")

    if a.server and rc == EXIT_OK:
        client = ApiClient(a.server, a.token, params_url=a.params_url)
        log("验证参数接口：%s" % client.params_url)
        try:
            p = params_mod.validate(client.get_params())
        except (ApiError, params_mod.ParamError) as exc:
            log("参数接口不可用：%s" % exc)
            return EXIT_STARTUP
        print("  %s: %s" % (_pad("params_key"), p["params_key"]))
        print("  %s: dpi=%d max_side=%d profile=%s"
              % (_pad("渲染参数"), p["dpi"], p["max_side"], p.get("profile", "")))
        log("自检结论：参数接口正常，可以开始扫描")
    elif getattr(a, "server", ""):
        log("依赖/引擎未通过，跳过参数接口验证")
    else:
        print("  %s: 未提供（加 --server/--token 可顺带验证接口）" % _pad("params 接口"))
    return rc


# --------------------------------------------------------------------------- main

def main(argv=None) -> int:
    argv = list(sys.argv[1:] if argv is None else argv)
    known = ("run", "self-check")
    if argv and argv[0] in ALIASES:
        argv[0] = ALIASES[argv[0]]      # 子命令别名，别让人第二次踩 --self-check
    if not argv:
        build_parser().print_help()
        return EXIT_STARTUP
    if argv[0] not in known and argv[0] not in ("-h", "--help", "--version"):
        argv = ["run"] + argv          # 兼容 `ocr-win --input …` 的裸调用
    a = build_parser().parse_args(argv)
    if a.cmd == "self-check":
        return cmd_self_check(a)
    return cmd_run(a)
