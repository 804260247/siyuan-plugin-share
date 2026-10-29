"""服务端渲染参数的校验与规范化（0026 任务书 4.2）。

服务端回包应当是严格 8 个字段：
    v / engine / engine_version / profile / dpi / max_side / render_ver / params_key
本工具只强依赖其中 4 个可用于渲染/回导的：dpi、max_side、params_key（+ v 做版本门槛）。
缺字段、dpi/max_side 非正、params_key 结构不对 —— 一律报错退出，**绝不**用本地默认值兜底。
"""

from __future__ import annotations


class ParamError(Exception):
    pass


REQUIRED_FIELDS = ("v", "engine", "engine_version", "profile", "dpi", "max_side",
                   "render_ver", "params_key")
KEY_FIELDS = 5  # engine|profile|dpi|max_side|render_ver


def validate(raw: dict) -> dict:
    if not isinstance(raw, dict):
        raise ParamError("参数回包不是 JSON 对象")

    missing = [k for k in REQUIRED_FIELDS if k not in raw]
    if missing:
        raise ParamError("参数回包缺少字段：%s" % ", ".join(missing))

    try:
        version = int(raw.get("v") or 0)
    except (TypeError, ValueError):
        raise ParamError("参数回包 v 不是整数")
    if version != 1:
        raise ParamError("参数回包版本 v=%r 不受支持（本工具只认 v=1）" % raw.get("v"))

    try:
        dpi = int(raw.get("dpi") or 0)
        max_side = int(raw.get("max_side") or 0)
    except (TypeError, ValueError):
        raise ParamError("参数回包 dpi / max_side 不是整数")
    if dpi <= 0:
        raise ParamError("参数回包 dpi=%r 非法（必须 > 0）" % raw.get("dpi"))
    if max_side <= 0:
        raise ParamError("参数回包 max_side=%r 非法（必须 > 0）" % raw.get("max_side"))
    # 本地再夹一道（服务端已夹过，这里只防脏值把本机显存/内存打满）
    if dpi < 50 or dpi > 600:
        raise ParamError("参数回包 dpi=%d 超出本地允许区间 50~600" % dpi)
    if max_side < 320 or max_side > 8000:
        raise ParamError("参数回包 max_side=%d 超出本地允许区间 320~8000" % max_side)

    key = str(raw.get("params_key") or "").strip()
    if not key:
        raise ParamError("参数回包 params_key 为空")
    parts = key.split("|")
    if len(parts) != KEY_FIELDS:
        raise ParamError("params_key=%r 结构非法（应为 engine|profile|dpi|max_side|render_ver）" % key)

    out = dict(raw)
    out["v"] = version
    out["dpi"] = dpi
    out["max_side"] = max_side
    out["params_key"] = key
    return out


def describe(params: dict) -> str:
    """单行摘要，写日志用（不含 token / 路径）。"""
    return "params_key=%s dpi=%d max_side=%d profile=%s engine=%s" % (
        params.get("params_key", ""), int(params.get("dpi") or 0),
        int(params.get("max_side") or 0), params.get("profile", ""), params.get("engine", ""))
