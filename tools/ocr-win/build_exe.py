"""打包入口：`build.ps1` 与 `build.cmd` 共用（0031）。

分工：
  * 两个脚本只负责「建 venv → 装依赖 → 调本脚本 → 冒烟自检」，
    PyInstaller 参数全部在 `pyinstaller_argv()` 里生成 —— 参数只有一份，
    从构造上就不可能再出现 0029 那种「两个脚本写得不一致」。
  * OCR extras 的收集参数从 `paddlex` 的包元数据派生（`ocrwin/pydeps.py`），
    不硬编码包名：上游给 `ocr` extra 加包时无需改这里。
  * 派生两类参数，缺一不可（0034）：`--collect-all <模块>` 让模块/数据文件
    进包，`--copy-metadata <发行包>` 让 `.dist-info` 元数据进包 —— paddlex
    判定 extras 用的是 `importlib.metadata.version()`，只补前者的产物会报
    `OCR requires additional dependencies`（模块明明都在）。

用法（在 tools\\ocr-win 下）：
  .venv\\Scripts\\python.exe build_exe.py            # --onedir
  .venv\\Scripts\\python.exe build_exe.py --onefile  # 单文件
  .venv\\Scripts\\python.exe build_exe.py --print-only  # 只打印派生结果与命令行，不打包
"""

from __future__ import annotations

import argparse
import os
import subprocess
import sys
from pathlib import Path

#: 基础参数里已用 --collect-submodules / --collect-data 显式收集过的模块，
#: 派生 extras 时跳过，避免同一个包被收集两遍。
BASE_MODULES = ("paddlex", "paddle")

EXIT_DEPENDENCY = 4


def pyinstaller_argv(onefile: bool, entry: str = "run_ocr_win.py",
                     name: str = "ocr-win") -> list[str]:
    """完整 PyInstaller 命令行（含 pydeps 派生出的 --collect-all）。"""
    from ocrwin import pydeps

    argv = [
        sys.executable, "-m", "PyInstaller",
        "--onefile" if onefile else "--onedir",
        "--name", name,
        "--clean", "--noconfirm",
        "--collect-submodules", "paddlex",
        "--collect-data", "paddlex",
        "--collect-data", "paddlex.deploy",
        "--collect-submodules", "paddle",
        "--hidden-import", "onnxruntime",
        "--hidden-import", "PIL.Image",
        # paddlex 的 deps.py 在导入期 `from packaging.requirements import
        # Requirement` 解析每条 Requires-Dist（0034 第 9 条）。分析链通常能
        # 顺着收进来，但这里显式声明：漏了就是 ImportError，与缺元数据不同。
        "--hidden-import", "packaging",
        "--hidden-import", "packaging.requirements",
        "--hidden-import", "packaging.version",
    ]
    argv += pydeps.pyinstaller_args(skip=BASE_MODULES)      # 模块进包
    argv += pydeps.metadata_pyinstaller_args()              # 元数据进包（0034）
    argv.append(entry)
    return argv


def exe_path(here: Path, onefile: bool, name: str = "ocr-win") -> Path:
    suffix = ".exe" if os.name == "nt" else ""
    if onefile:
        return here / "dist" / (name + suffix)
    return here / "dist" / name / (name + suffix)


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description="打包 ocr-win（PyInstaller 参数由 paddlex 元数据派生）")
    ap.add_argument("--onefile", action="store_true", help="打成单文件 exe（默认 --onedir）")
    ap.add_argument("--print-only", action="store_true",
                    help="只打印派生出的依赖与完整命令行，不执行 PyInstaller（离线自测用）")
    a = ap.parse_args(argv)

    here = Path(__file__).resolve().parent
    os.chdir(str(here))
    sys.path.insert(0, str(here))

    from ocrwin import pydeps

    names = pydeps.requirement_names()
    not_installed = pydeps.uninstalled()
    meta_names = pydeps.metadata_names()
    print("[build] %s" % pydeps.summary(), flush=True)
    if not names:
        print("[build] 无法从 paddlex 元数据读出 ocr extras —— 依赖没装全，"
              "先执行 pip install -r requirements.txt -r requirements-build.txt", flush=True)
        return EXIT_DEPENDENCY
    if not_installed:
        print("[build] 以下 extras 声明了但本机没装：%s —— "
              "打出来的 exe 会缺这些包，先 pip install -r requirements.txt"
              % ", ".join(not_installed), flush=True)
        return EXIT_DEPENDENCY

    # 两类派生结果都打印出来：出问题时能一眼看出是「没收集模块」还是
    # 「没打进元数据」（0034 的故障就属于后者）。
    mods = pydeps.collect_modules(skip=BASE_MODULES)
    print("[build] --collect-all 派生模块 %d 个：%s"
          % (len(mods), ", ".join(mods) or "（无）"), flush=True)
    print("[build] --copy-metadata 派生发行包 %d 个：%s"
          % (len(meta_names), ", ".join(meta_names) or "（无）"), flush=True)
    missing_meta = pydeps.missing_metadata(meta_names)
    if missing_meta:
        print("[build] 以下发行包没有 dist-info 元数据：%s —— 打出来的 exe 会被 paddlex "
              "误判 extras 未安装，先在打包机上装好依赖" % ", ".join(missing_meta), flush=True)
        return EXIT_DEPENDENCY

    args = pyinstaller_argv(a.onefile)
    print("[build] PyInstaller 命令行：\n  %s" % " ".join(args), flush=True)
    target = exe_path(here, a.onefile)
    print("[build] 预期产物：%s" % target, flush=True)
    if a.print_only:
        print("[build] --print-only：未执行打包", flush=True)
        return 0

    print("[build] 打包中（体积约 1.5–2 GB，耐心等）…", flush=True)
    rc = subprocess.call(args)
    if rc != 0:
        print("[build] PyInstaller 失败，退出码 %d" % rc, flush=True)
        return rc or 1
    if not target.exists():
        print("[build] PyInstaller 报成功但产物不存在：%s" % target, flush=True)
        return 1
    print("[build] 打包完成：%s" % target, flush=True)
    print("[build] 下一步由调用脚本跑冒烟自检：\"%s\" self-check" % target, flush=True)
    return 0


if __name__ == "__main__":
    sys.exit(main())
