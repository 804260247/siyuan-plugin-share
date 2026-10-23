#!/usr/bin/env bash
# =============================================================================
# PaddleX OCR serving 启动脚本（siyuan-plugin-share 网盘全文索引 OCR sidecar）
#
# 由 docker-compose 的 ocr 服务挂载执行（python:3.11-slim 基础镜像）。
# 对外契约（php-site/index.php 已按此写死，不可更改）：
#   POST /ocr          body: {"file": "<base64>", "fileType": 1}
#                      resp: {"errorCode": 0, "result": {"ocrResults": [{...}]}}
#   GET  /openapi.json 健康探测端点（FastAPI 默认提供）
#
# 环境变量（均有默认值，可在 compose 里覆盖）：
#   OCR_ENGINE     推理引擎：onnxruntime（默认）| paddle
#                  onnxruntime = PaddleX 统一引擎配置，CPU 上内存占用更低、
#                  低配 NAS 更稳（首次启动会把官方 Paddle 模型转成 ONNX 并缓存）
#   OCR_DEVICE     推理设备，默认 cpu；GPU 环境可填 gpu:0
#   OCR_PORT       监听端口，默认 8080（与 web 侧 NETDISK_OCR_SIDECAR_URL 统一）
#   OCR_PROFILE    low（默认）| high 资源预设：
#                    low  = 2 线程 + 关闭 ONNX 内存竞技场，防低配机 OOM
#                    high = 4 线程，吞吐优先
#                  显式设置 OCR_THREADS 时以 OCR_THREADS 为准
#   OCR_THREADS    推理线程数，默认按 OCR_PROFILE 取 2/4；0 = 引擎默认
#   OCR_PIP_INDEX  可选 pip 国内镜像源，如：
#                  https://pypi.tuna.tsinghua.edu.cn/simple
#
# 模型缓存：/root/.paddlex（compose 里挂 ./ocr/models:/root/.paddlex 持久化，
#           重建容器不重复下载/转换模型）
# =============================================================================
set -euo pipefail

OCR_ENGINE="${OCR_ENGINE:-onnxruntime}"
OCR_DEVICE="${OCR_DEVICE:-cpu}"
OCR_PORT="${OCR_PORT:-8080}"
OCR_PROFILE="${OCR_PROFILE:-low}"
OCR_THREADS="${OCR_THREADS:-}"
OCR_PIP_INDEX="${OCR_PIP_INDEX:-}"
export OCR_ENGINE OCR_DEVICE OCR_PORT OCR_PROFILE OCR_THREADS

# 模型/配置缓存目录（与 compose 卷挂载点对应）
export PADDLEX_HOME="/root/.paddlex"
mkdir -p "$PADDLEX_HOME"

# ---- 线程数：profile 预设，显式 OCR_THREADS 优先 ----
if [ -z "$OCR_THREADS" ]; then
    case "$OCR_PROFILE" in
        high) OCR_THREADS=4 ;;
        *)    OCR_THREADS=2 ;;
    esac
    export OCR_THREADS
fi

PIP_ARGS=()
if [ -n "$OCR_PIP_INDEX" ]; then
    PIP_ARGS+=(-i "$OCR_PIP_INDEX" --trusted-host "$(echo "$OCR_PIP_INDEX" | awk -F/ '{print $3}')")
fi

echo "[ocr-init] engine=${OCR_ENGINE} device=${OCR_DEVICE} port=${OCR_PORT} profile=${OCR_PROFILE} threads=${OCR_THREADS}"

# ---- 系统库：完整版 opencv 运行时所需（必须在 pip 装 paddlex[ocr] 之前备齐）----
# paddlex[ocr] 拉进来的是 opencv-contrib-python（完整版、非 headless），其 native
# 模块 cv2 加载时需要 libGL.so.1 与 glib；python:3.11-slim 基础镜像默认没有，
# 缺失时 import cv2 直接崩：ImportError: libGL.so.1: cannot open shared object
# file（用户 NAS 实测，栈在 paddlex/modules/.../visualizer.py import cv2）。
# libglib2.0-0 在 trixie 因 64-bit time_t 迁移改名 libglib2.0-0t64，用 || 回退。
# libgomp1 是 onnxruntime CPU EP 的运行时依赖，slim 不保证自带，装了无害。
# 幂等守卫：libGL.so.1 已存在则不再走 apt（重启不重复耗 apt update 索引时间）。
if [ ! -e /usr/lib/x86_64-linux-gnu/libGL.so.1 ]; then
    echo "[ocr-init] installing system libs (libgl1/libglib2.0-0/libgomp1) ..."
    apt-get update -qq </dev/null \
        || { echo "[ocr-init] FATAL: apt-get update failed"; exit 1; }
    apt-get install -y --no-install-recommends libgl1 </dev/null \
        || { echo "[ocr-init] FATAL: apt-get install libgl1 failed"; exit 1; }
    apt-get install -y --no-install-recommends libglib2.0-0 </dev/null \
        || apt-get install -y --no-install-recommends libglib2.0-0t64 </dev/null \
        || { echo "[ocr-init] FATAL: apt-get install libglib2.0-0(-t64) failed"; exit 1; }
    apt-get install -y --no-install-recommends libgomp1 </dev/null \
        || { echo "[ocr-init] FATAL: apt-get install libgomp1 failed"; exit 1; }
else
    echo "[ocr-init] system libs already present, skip apt (idempotent)"
fi

# ---- 依赖安装（容器重建后若已安装则 pip 秒过）----
# paddlepaddle 提供官方模型格式支持；onnxruntime 引擎首次运行需 paddle2onnx 做格式转换
# paddlex[ocr]：OCR 产线自身依赖组（opencv-contrib/pypdfium2/pyclipper/shapely/
#   scikit-learn 等）。create_pipeline("OCR") 时 PaddleX 经 require_extra 校验该
#   extra，缺失即抛 RuntimeError: OCR requires additional dependencies（用户 NAS
#   实测）。extra 名 "ocr" 已按 PyPI paddlex 3.0.3 元数据核实（requires_dist 中
#   15 条 extra == "ocr"）。[] 必须加引号，否则 bash/zsh 会当 glob 展开。
#   顺序：先 pip 装产线 extras，再 paddlex --install serving（后者只补 uvicorn/
#   fastapi 等服务层包，不提供产线依赖）。
# </dev/null：sidecar 无 TTY/无 stdin，任何工具意外读交互输入时立即 EOF 而非挂起
python3 -m pip install --no-cache-dir "${PIP_ARGS[@]}" \
    paddlepaddle==3.0.0 "paddlex[ocr]==3.0.3" paddle2onnx onnxruntime </dev/null

# ---- fail-fast 预检：确认 cv2 native 库就位 ----
# 缺系统库时让日志一句话讲清根因，而不是 exec 阶段甩 40 行 traceback。
# 故意用 import cv2 而非 import paddlex：后者会顺带触发模型索引扫描，慢且可能
# 引发下载，不适合做启动预检。
python3 -c 'import cv2' </dev/null 2>/dev/null \
    || { echo "[ocr-init] FATAL: cv2 import failed, missing system libs (libGL/libglib)"; exit 1; }
echo "[ocr-init] cv2 import ok"

# serving 插件（uvicorn/fastapi 等，提供 /ocr、/openapi.json HTTP 服务）
paddlex --install serving "${PIP_ARGS[@]}" </dev/null

# ---- 导出 OCR 产线默认配置并注入引擎/资源参数 ----
# 必须带 --save_path：PaddleX 3.0.3 在 save_path 缺省时会 input() 要求手输保存
# 路径（interactive_get_pipeline），无 stdin 的 sidecar 里直接 EOFError 崩溃；
# 目标文件已存在时它还会再 input() 问「Is it covered? (y/N)」，同样 EOF 崩，
# 故用「已存在则跳过」保证重启幂等（下游 python3 -c 读取的正是此路径）
cd "$PADDLEX_HOME"
if [ ! -f "$PADDLEX_HOME/OCR.yaml" ]; then
    paddlex --get_pipeline_config OCR --save_path "$PADDLEX_HOME" </dev/null
else
    echo "[ocr-init] OCR.yaml already present, skip export (idempotent)"
fi

# 按环境变量生成引擎配置 JSON（onnxruntime/paddle 两种后端的字段名不同）
ENGINE_JSON=$(python3 -c '
import json, os
engine = os.environ.get("OCR_ENGINE", "onnxruntime")
device = os.environ.get("OCR_DEVICE", "cpu")
profile = os.environ.get("OCR_PROFILE", "low")
t = os.environ.get("OCR_THREADS", "")
engine_cfg = {"device_type": "cpu"}
if device.startswith("gpu"):
    try:
        engine_cfg = {"device_type": "gpu", "device_id": int(device.split(":")[1])}
    except Exception:
        engine_cfg = {"device_type": "gpu", "device_id": 0}
threads = int(t) if t.isdigit() else 0
if engine == "onnxruntime":
    # low profile 额外关闭内存竞技场/内存模式，压低 onnxruntime 峰值内存
    if threads > 0:
        engine_cfg["intra_op_num_threads"] = threads
    if profile != "high":
        engine_cfg["enable_cpu_mem_arena"] = False
        engine_cfg["enable_mem_pattern"] = False
elif engine == "paddle":
    engine_cfg["cpu_threads"] = threads if threads > 0 else 2
else:
    raise SystemExit("[ocr-init] unsupported OCR_ENGINE=" + engine)
print(json.dumps({"engine": engine, "engine_config": engine_cfg}))
')
export OCR_ENGINE_JSON="$ENGINE_JSON"
echo "[ocr-init] engine_json=${ENGINE_JSON}"

python3 -c '
import json, os, sys
try:
    import yaml
except ImportError:
    sys.exit("[ocr-init] PyYAML missing; check paddlex install logs")

cfg_path = os.path.join(os.environ["PADDLEX_HOME"], "OCR.yaml")
with open(cfg_path, "r", encoding="utf-8") as f:
    cfg = yaml.safe_load(f)

# 全局统一引擎 + 引擎配置（作用于 OCR 产线全部子模块）
override = json.loads(os.environ["OCR_ENGINE_JSON"])
cfg["engine"] = override["engine"]
cfg["engine_config"] = override["engine_config"]

# Serving 响应瘦身：不回传可视化图（PHP 侧只读 rec_texts/rec_scores）
serving = cfg.get("Serving") or {}
serving["visualize"] = False
cfg["Serving"] = serving

with open(cfg_path, "w", encoding="utf-8") as f:
    yaml.safe_dump(cfg, f, allow_unicode=True, sort_keys=False)
print("[ocr-init] config written:", cfg_path)
'

# ---- 拉起服务 ----
# 基础服务化设计并发数为 1（官方口径），单请求内按上面线程数推理；
# low profile 通过低线程 + 关闭内存竞技场限制内存峰值，防低配 NAS OOM。
echo "[ocr-init] starting paddlex serving: http://0.0.0.0:${OCR_PORT}/ocr"
exec paddlex --serve --pipeline "${PADDLEX_HOME}/OCR.yaml" \
    --device "${OCR_DEVICE}" --host 0.0.0.0 --port "${OCR_PORT}"
