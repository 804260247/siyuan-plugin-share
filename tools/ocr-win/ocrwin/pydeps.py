"""从 paddlex 的包元数据派生 PyInstaller 需要收集的第三方依赖（0031）。

为什么要"派生"而不是手抄清单：
  `requirements.txt` 写的是 `paddlex[ocr]==3.0.3`，extras 里的第三方包
  （cv2 / sklearn / tokenizers …）都装在 venv 里，所以**源码模式能跑**；
  但 PyInstaller 只加了 `--collect-submodules paddlex`，收的只是 paddlex
  自己的 .py，而 OCR pipeline 是懒导入、静态分析追不到 —— 打出来的 exe
  一初始化就报 `OCR requires additional dependencies`。

  上游随时会给 `ocr` extra 加包，所以清单必须从
  `importlib.metadata.requires("paddlex")` 里筛 `extra == "ocr"` 现算，
  不许在本文件里硬编码一份包名清单。

纯标准库实现（打包机上不额外依赖 `packaging`）：
  * 发行包名 → 顶层模块名走 dist-info 的 `top_level.txt`，
    这样 `opencv-contrib-python` → `cv2`、`scikit-learn` → `sklearn`
    这类「包名≠模块名」的映射不用手维护。
"""

from __future__ import annotations

import importlib.metadata as md
import importlib.util
import re

#: 被收集依赖的发行包（extras 挂在它身上）
DEFAULT_DIST = "paddlex"
#: 要收集的 extra 名
DEFAULT_EXTRA = "ocr"

_NAME_RE = re.compile(r"^\s*([A-Za-z0-9][A-Za-z0-9._-]*)")
_EXTRA_RE = re.compile(r"""extra\s*==\s*['"]([^'"]+)['"]""")
_NORM_RE = re.compile(r"[-_.]+")


def normalize(name: str) -> str:
    """PEP 503 规范化发行包名（`Jinja2` → `jinja2`，`foo_bar` → `foo-bar`）。"""
    return _NORM_RE.sub("-", str(name).strip()).lower()


def requirement_names(dist: str = DEFAULT_DIST, extra: str = DEFAULT_EXTRA,
                      requires=None) -> list[str]:
    """派生 `<dist>[<extra>]` 声明的第三方发行包名（去重、保声明顺序）。

    `requires` 可注入（离线自测用），默认取 `importlib.metadata.requires(dist)`；
    发行包未安装时返回空列表——调用方自己决定这算不算错误。
    """
    if requires is None:
        try:
            requires = md.requires(dist) or []
        except md.PackageNotFoundError:
            return []
    wanted = extra.strip().lower()
    out: list[str] = []
    seen: set[str] = set()
    for line in requires:
        if not line:
            continue
        # 只在 marker（分号之后）里找 extra，避免把 `==4.10.0.84` 之类版本号当条件
        head, _, marker = line.partition(";")
        if wanted not in {m.strip().lower() for m in _EXTRA_RE.findall(marker)}:
            continue
        m = _NAME_RE.match(head)
        if not m:
            continue
        name = normalize(m.group(1))
        if name and name not in seen:
            seen.add(name)
            out.append(name)
    return out


def top_level_modules(dist: str) -> list[str]:
    """发行包名 → 它真正能被 import 的顶层模块名。

    读 dist-info 里的 `top_level.txt`；缺失/为空时退回「把名字里的 - 换成 _」。
    发行包未安装返回 `[]`。
    """
    try:
        meta = md.distribution(dist)
    except md.PackageNotFoundError:
        return []
    mods: list[str] = []
    try:
        raw = meta.read_text("top_level.txt") or ""
    except Exception:
        raw = ""
    for line in raw.splitlines():
        mod = line.strip()
        if mod and mod not in mods:
            mods.append(mod)
    if not mods:
        mods = [normalize(dist).replace("-", "_")]
    return mods


def module_pairs(dist: str = DEFAULT_DIST, extra: str = DEFAULT_EXTRA,
                 requires=None) -> list[tuple[str, str]]:
    """`[(发行包名, 顶层模块名)]`，按 extra 声明顺序；未安装的发行包跳过。"""
    pairs: list[tuple[str, str]] = []
    for name in requirement_names(dist, extra, requires=requires):
        for mod in top_level_modules(name):
            pairs.append((name, mod))
    return pairs


def uninstalled(dist: str = DEFAULT_DIST, extra: str = DEFAULT_EXTRA,
                requires=None) -> list[str]:
    """extra 里声明了、但当前解释器里没装的发行包名（打包机上应为空）。"""
    out: list[str] = []
    for name in requirement_names(dist, extra, requires=requires):
        try:
            md.distribution(name)
        except md.PackageNotFoundError:
            out.append(name)
    return out


def missing_modules(pairs=None) -> list[tuple[str, str]]:
    """探一遍「模块能不能找到」（`find_spec`，不 import、不加载模型）。

    返回 `[(发行包名, 模块名)]`。self-check 用它把"缺哪个包"说清楚，
    而不是只丢一句 `OCR requires additional dependencies`。
    """
    if pairs is None:
        pairs = module_pairs()
    out: list[tuple[str, str]] = []
    for dist, mod in pairs:
        try:
            spec = importlib.util.find_spec(mod)
        except (ImportError, AttributeError, ValueError):
            spec = None
        if spec is None:
            out.append((dist, mod))
    return out


def pyinstaller_args(dist: str = DEFAULT_DIST, extra: str = DEFAULT_EXTRA,
                     skip=(), requires=None) -> list[str]:
    """派生 PyInstaller 参数：每个 extra 模块一条 `--collect-all <module>`。

    `--collect-all` 会一并收集该包的 submodules / 数据文件 / 二进制扩展，
    比只写 `--hidden-import` 稳（cv2 的 .pyd、tokenizers 的 dll 都在里面）。
    `skip` 里的模块视为「基础参数已经显式收集过」，不重复加。
    """
    args: list[str] = []
    seen = {str(s) for s in skip}
    for _dist, mod in module_pairs(dist, extra, requires=requires):
        if mod in seen:
            continue
        seen.add(mod)
        args += ["--collect-all", mod]
    return args


def summary(dist: str = DEFAULT_DIST, extra: str = DEFAULT_EXTRA) -> str:
    """一行日志：派生出多少个包、分别是哪些（超长就截断）。"""
    names = requirement_names(dist, extra)
    if not names:
        return "%s[%s] 未读到 extras（paddlex 未安装或元数据缺失）" % (dist, extra)
    shown = ", ".join(names[:12])
    if len(names) > 12:
        shown += ", …（共 %d 个）" % len(names)
    return "派生自 %s[%s] 的第三方包 %d 个：%s" % (dist, extra, len(names), shown)
