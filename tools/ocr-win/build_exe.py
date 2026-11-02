"""打包入口：`build.ps1` 与 `build.cmd` 共用（0031）。

分工：
  * 两个脚本只负责「建 venv → 装依赖 → 调本脚本 → 冒烟自检」，
    PyInstaller 参数全部在 `pyinstaller_argv()` 里生成 —— 参数只有一份，
    从构造上就不可能再出现 0029 那种「两个脚本写得不一致」。
  * OCR extras 的收集参数从 `paddlex` 的包元数据派生（`ocrwin/pydeps.py`），
    不硬编码包名：上游给 `ocr` extra 加包时无需改这里。
  * 派生三类参数，缺一不可：
      - `--collect-all <模块>`（0031）：模块/数据文件进包；
      - `--copy-metadata <发行包>`（0034）：`.dist-info` 元数据进包 —— paddlex
        判定 extras 用的是 `importlib.metadata.version()`，只补第一类的产物会报
        `OCR requires additional dependencies`（模块明明都在）；
      - `--add-binary <abs>;<dest>`（0036）：paddle 自己的原生运行库（mklml /
        mkldnn / libiomp5md …）进包 —— 这些库运行时才 LoadLibrary 加载，
        静态依赖树里查不到，`--collect-all paddle` 收不全，漏一个就是
        `error code is 126`。

用法（在 tools\\ocr-win 下）：
  .venv\\Scripts\\python.exe build_exe.py            # --onedir
  .venv\\Scripts\\python.exe build_exe.py --onefile  # 单文件
  .venv\\Scripts\\python.exe build_exe.py --print-only  # 只打印派生结果与命令行，不打包
"""

from __future__ import annotations

import argparse
import importlib.util
import os
import subprocess
import sys
from pathlib import Path

#: 基础参数里已用 --collect-submodules / --collect-data 显式收集过的模块，
#: 派生 extras 时跳过，避免同一个包被收集两遍。
BASE_MODULES = ("paddlex", "paddle")

EXIT_DEPENDENCY = 4

#: paddle 里「静态依赖树查不到、运行时靠 LoadLibrary 动态加载」的原生库。
#: dest 必须与 site-packages 内的相对路径一致 —— paddle 是按
#: `paddle/libs/xxx.dll` 去 LoadLibrary 的，放错目录等于没加（0036）。
#: 第二位是可收的文件后缀，None 表示该目录下所有文件都收。
PADDLE_NATIVE_DIRS = (
    ("libs", None),              # mklml.dll / mkldnn.dll / libiomp5md.dll / common.dll …
    ("base", (".pyd", ".dll")),  # libpaddle.pyd 及其同级 DLL
)


class NativeLibsMissing(RuntimeError):
    """打包机上找不到 paddle 的原生运行库 —— 装错环境，必须早失败。"""


def paddle_root():
    """`<site-packages>/paddle` 目录；找不到返回 None。

    用 `find_spec` 而不是 `import paddle`：后者会初始化原生库，
    为了「找目录」先把 155 MB 的 libpaddle.pyd 加载一遍没有意义。
    """
    try:
        spec = importlib.util.find_spec("paddle")
    except (ImportError, ValueError, AttributeError):
        return None
    if spec is None:
        return None
    locs = list(getattr(spec, "submodule_search_locations", None) or [])
    if locs:
        return Path(locs[0])
    origin = getattr(spec, "origin", None)
    return Path(origin).parent if origin else None


def native_lib_files(root=None):
    """派生 `[(源文件, 包内相对目录)]`：`paddle/libs` 全部 + `paddle/base` 的 pyd/dll。

    不写死文件名：今天漏的是 `mklml.dll`，明天上游可能换一批 ——
    目录里有什么就收什么，条目数可与磁盘逐一核对。
    """
    base_root = Path(root) if root is not None else paddle_root()
    if base_root is None or not base_root.is_dir():
        raise NativeLibsMissing("找不到 paddle 包目录（打包机上没装 paddlepaddle？）")
    pairs = []
    libs = base_root / "libs"
    if not libs.is_dir():
        raise NativeLibsMissing("找不到 %s —— 打包机上装错环境" % libs)
    files = sorted(p for p in libs.iterdir() if p.is_file())
    if not files:
        raise NativeLibsMissing("%s 是空目录 —— 打包机上装错环境" % libs)
    for f in files:
        pairs.append((f, "paddle/libs"))
    for sub, suffixes in PADDLE_NATIVE_DIRS:
        if sub == "libs":
            continue
        d = base_root / sub
        if not d.is_dir():
            continue
        for f in sorted(d.iterdir()):
            if not f.is_file():
                continue
            if suffixes is not None and f.suffix.lower() not in suffixes:
                continue
            pairs.append((f, "paddle/%s" % sub))
    return pairs


def native_pyinstaller_args(pairs):
    """`[(源文件, dest)]` → 若干条 `--add-binary "<abs>;<dest>"`。

    `--add-binary` 的 dest 是**包内目录**，PyInstaller 按原名放进去；
    `--collect-all paddle` 只顺着静态依赖树收，这类运行时才加载的库收不全。
    """
    args = []
    for src, dest in pairs:
        args += ["--add-binary", "%s%s%s" % (Path(src).resolve(), os.pathsep, dest)]
    return args


def pyinstaller_argv(onefile: bool, entry: str = "run_ocr_win.py",
                     name: str = "ocr-win", native=None) -> list[str]:
    """完整 PyInstaller 命令行（含 pydeps 派生出的 --collect-all 与原生库 add-binary）。

    `native` 为 `native_lib_files()` 的结果，可注入（自测用）；默认现派生，
    派生失败抛 `NativeLibsMissing`，由调用方转成 `EXIT_DEPENDENCY`。
    """
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
    # 原生运行库进包（0036）：mklml/mkldnn/libiomp5md 这类库不在静态依赖树里，
    # --collect-all 收不到，exe 一初始化就 `error code is 126`。
    argv += native_pyinstaller_args(native_lib_files() if native is None else native)
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
                    help="只打印三类派生结果与完整命令行，不执行 PyInstaller（离线自测用）")
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

    try:
        native = native_lib_files()
    except NativeLibsMissing as exc:
        print("[build] paddle 原生运行库派生失败：%s —— 打包机上必须先装好 "
              "paddlepaddle（pip install -r requirements.txt）" % exc, flush=True)
        return EXIT_DEPENDENCY
    print("[build] --add-binary 派生 paddle 原生库 %d 个：%s"
          % (len(native), ", ".join("%s/%s" % (dest, Path(src).name)
                                    for src, dest in native) or "（无）"), flush=True)

    args = pyinstaller_argv(a.onefile, native=native)
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
