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
#   OCR_PROFILE    low（默认）| high | auto 资源预设：
#                    low  = 2 线程 + 关闭 ONNX 内存竞技场，防低配机 OOM
#                    high = 4 线程，吞吐优先
#                    auto = 按容器内存上限与核数自动选 high/low（内存≥4GiB 且
#                           核数≥6 → high，其余及内存不可知 → low），
#                           判定依据打一行 profile=auto resolved=... 日志
#                  未知取值打 WARN 后按 low 处理（不 FATAL、不挡容器启动）
#                  显式设置 OCR_THREADS 时以 OCR_THREADS 为准
#   OCR_THREADS    推理线程数，默认按 OCR_PROFILE 取 2/4；0 = 引擎默认
#   OCR_PIP_INDEX  可选 pip 国内镜像源，必须是完整 URL（带 scheme 与 /simple/），如：
#                  https://mirrors.aliyun.com/pypi/simple/
#                  与 OCR_APT_MIRROR 写法不同（后者只写主机名）
#   OCR_PIP_TIMEOUT pip 单次网络操作超时秒数，默认 120（pip 自带默认 15s，
#                  官方 CDN 慢时容易 ReadTimeoutError，可再调大）
#   OCR_PIP_RETRIES pip 网络重试次数，默认 5
#   OCR_APT_MIRROR 可选 Debian apt 镜像主机名，如：mirrors.aliyun.com
#                  官方源 deb.debian.org 在容器网络不可达时设置（留空 = 用镜像默认源）
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
OCR_PIP_TIMEOUT="${OCR_PIP_TIMEOUT:-120}"
OCR_PIP_RETRIES="${OCR_PIP_RETRIES:-5}"
OCR_APT_MIRROR="${OCR_APT_MIRROR:-}"
export OCR_ENGINE OCR_DEVICE OCR_PORT OCR_PROFILE OCR_THREADS

# 模型/配置缓存目录（与 compose 卷挂载点对应）
export PADDLEX_HOME="/root/.paddlex"
mkdir -p "$PADDLEX_HOME"

# ---- 档位解析（0022）：OCR_PROFILE = low | high | auto ----
# auto 面向不想手动估机器规格的用户：按容器可用内存与核数自动选线程档。
# 内存依据 = min(cgroup 上限, /proc/meminfo MemTotal)：
#   cgroup 先读 v2 memory.max，不可读再回退 v1 memory.limit_in_bytes；
#   'max'、空文件、非纯数字、≥2^50（v1 无限制哨兵 9223372036854771712）
#   一律视为该来源不可知；两个来源都不可知 → 内存依据缺失。
# 核数依据 = nproc，不可用回退 getconf _NPROCESSORS_ONLN，仍不可用按 2。
# 判定：可用内存 ≥ 4 GiB 且核数 ≥ 6 → high；否则 low；内存完全不可知 → low
# （证不出内存充足就保守取低档，宁可慢、不 OOM）。
# 解析后 OCR_PROFILE 被改写为 low/high（变量在上方已 export，改写即生效）：
# 下方线程预设与 engine_json 生成段（enable_cpu_mem_arena 开关按
# profile != "high" 判断）看到的都是解析后的档位，PY 段零改动。
# 未知取值只 WARN 不 FATAL：档位名写错一个字母容器也必须能起来，行为等同 low。
ocr_mem_source_bytes() {
    # $1=候选文件路径。可读且为纯数字且 <2^50 → stdout 输出字节数；
    # 其余（不可读/'max'/空/非数字/哨兵值）不输出任何内容 = 该来源不可知。
    # 恒返回 0，set -e 下安全。
    local f="$1" v
    [ -r "$f" ] || return 0
    v="$(tr -d '[:space:]' < "$f" 2>/dev/null)" || return 0
    case "$v" in '' | max | *[!0-9]*) return 0 ;; esac
    if [ "$v" -lt 1125899906842624 ]; then echo "$v"; fi
    return 0
}
ocr_mb_or_unknown() {
    # 字节数 → 整除 MB 供日志；空/非纯数字打 unknown
    case "$1" in '' | *[!0-9]*) echo "unknown" ;; *) echo "$(( $1 / 1048576 ))MB" ;; esac
}
if [ "$OCR_PROFILE" = "auto" ]; then
    nd_mem_cg=""
    for nd_f in /sys/fs/cgroup/memory.max /sys/fs/cgroup/memory/memory.limit_in_bytes; do
        nd_mem_cg="$(ocr_mem_source_bytes "$nd_f")"
        if [ -n "$nd_mem_cg" ]; then break; fi
    done
    nd_mem_total=""
    if [ -r /proc/meminfo ]; then
        nd_mem_total="$(awk '/^MemTotal:/{printf "%d", $2 * 1024; exit}' /proc/meminfo 2>/dev/null)"
        case "$nd_mem_total" in '' | *[!0-9]*) nd_mem_total="" ;; esac
    fi
    # 可用内存 = 已知来源取 min（缺一个用另一个，全缺 → 空 = 不可知）
    nd_mem=""
    for nd_v in "$nd_mem_cg" "$nd_mem_total"; do
        [ -n "$nd_v" ] || continue
        if [ -z "$nd_mem" ] || [ "$nd_v" -lt "$nd_mem" ]; then nd_mem="$nd_v"; fi
    done
    nd_nproc="$(nproc 2>/dev/null || true)"
    case "$nd_nproc" in '' | *[!0-9]*) nd_nproc="$(getconf _NPROCESSORS_ONLN 2>/dev/null || true)" ;; esac
    case "$nd_nproc" in '' | *[!0-9]*) nd_nproc=2 ;; esac
    if [ -n "$nd_mem" ] && [ "$nd_mem" -ge 4294967296 ] && [ "$nd_nproc" -ge 6 ]; then
        OCR_PROFILE=high
    else
        OCR_PROFILE=low
    fi
    echo "[ocr-init] profile=auto resolved=${OCR_PROFILE} (mem_limit=$(ocr_mb_or_unknown "$nd_mem_cg") mem_total=$(ocr_mb_or_unknown "$nd_mem_total") nproc=${nd_nproc})"
elif [ "$OCR_PROFILE" != "low" ] && [ "$OCR_PROFILE" != "high" ]; then
    echo "[ocr-init] WARN unknown OCR_PROFILE=${OCR_PROFILE}, fall back to low"
    OCR_PROFILE=low
fi

# ---- 线程数：profile 预设，显式 OCR_THREADS 优先 ----
if [ -z "$OCR_THREADS" ]; then
    case "$OCR_PROFILE" in
        high) OCR_THREADS=4 ;;
        *)    OCR_THREADS=2 ;;
    esac
    export OCR_THREADS
fi

# ---- pip 源/超时/重试：统一用 PIP_* 环境变量导出，不走命令行参数 ----
# 为什么不用 -i/--trusted-host 传参：paddlex --install serving 内部会自行调起
# pip 子进程，子进程只继承环境变量、不接受我们拼在 paddlex 命令上的 pip 参数；
# 统一走 PIP_* 后，直接 pip install 与 paddlex 间接调起的 pip 共用同一份源配置。
export PIP_DEFAULT_TIMEOUT="$OCR_PIP_TIMEOUT"
export PIP_RETRIES="$OCR_PIP_RETRIES"
export PIP_DISABLE_PIP_VERSION_CHECK=1
# 持久 pip 缓存：放在 PADDLEX_HOME（compose 挂载卷）内，docker compose up -d
# --build 重建容器后已下载的 wheel 直接命中本地缓存，不再重下
# （paddlepaddle 单包 192 MB，官方 CDN 慢时每次重建都从头下载）。
export PIP_CACHE_DIR="$PADDLEX_HOME/pip-cache"
mkdir -p "$PIP_CACHE_DIR"
if [ -n "$OCR_PIP_INDEX" ]; then
    export PIP_INDEX_URL="$OCR_PIP_INDEX"
    export PIP_TRUSTED_HOST="$(echo "$OCR_PIP_INDEX" | awk -F/ '{print $3}')"
    echo "[ocr-init] pip index -> ${PIP_INDEX_URL} (trusted-host ${PIP_TRUSTED_HOST})"
fi

echo "[ocr-init] engine=${OCR_ENGINE} device=${OCR_DEVICE} port=${OCR_PORT} profile=${OCR_PROFILE} threads=${OCR_THREADS}"

# ---- 系统库：完整版 opencv 运行时所需（必须在 pip 装 paddlex[ocr] 之前备齐）----
# paddlex[ocr] 拉进来的是 opencv-contrib-python（完整版、非 headless），其 native
# 模块 cv2 加载时需要 libGL.so.1 与 glib；python:3.11-slim 基础镜像默认没有，
# 缺失时 import cv2 直接崩：ImportError: libGL.so.1: cannot open shared object
# file（用户 NAS 实测，栈在 paddlex/modules/.../visualizer.py import cv2）。
# libgl1 会连带整套 mesa 依赖（约 38 包 / 48 MB），这是 libGL.so.1 的最小依赖
# 闭包，省不掉；--no-install-recommends 已经只装必需项，不要试图换小包顶替。
# 幂等守卫：libGL.so.1 已存在则整个 apt 段（含 update）全部跳过。
if [ ! -e /usr/lib/x86_64-linux-gnu/libGL.so.1 ]; then
    echo "[ocr-init] installing system libs (libgl1/glib/libgomp1) ..."

    # apt 源可配：官方源 deb.debian.org:80 在部分网络不可达（用户 NAS 实测
    # pip 通、apt 全部 connection timed out）。OCR_APT_MIRROR 填镜像主机名
    # （如 mirrors.aliyun.com），替换 deb.debian.org / security.debian.org。
    # aliyun/tuna 的 debian 与 debian-security 路径都在镜像根下，只换主机名、
    # 保留原 scheme 与路径即可。trixie 用 deb822 格式的
    # /etc/apt/sources.list.d/debian.sources，旧版用 /etc/apt/sources.list，
    # 两者都存在则都改；重写前备份为 <原文件>.bak-ocr。
    if [ -n "$OCR_APT_MIRROR" ]; then
        mirror_host="${OCR_APT_MIRROR#http://}"
        mirror_host="${mirror_host#https://}"
        mirror_host="${mirror_host%%/*}"
        echo "[ocr-init] apt mirror -> ${mirror_host}"
        for src_file in /etc/apt/sources.list.d/debian.sources /etc/apt/sources.list; do
            [ -f "$src_file" ] || continue
            cp "$src_file" "${src_file}.bak-ocr"
            # </dev/null 统一兜底：全脚本任何外部命令都不允许挂起等 stdin
            sed -i -e "s|deb\.debian\.org|${mirror_host}|g" \
                   -e "s|security\.debian\.org|${mirror_host}|g" "$src_file" </dev/null
        done
    fi

    # 超时/重试：源不可达时 apt 默认每条连接干等约 2 分钟、日志刷两百多行，
    # 收紧为单连接 15s、重试 3 次，快速失败快速报因
    APT_OPTS=(-o Acquire::http::Timeout=15 -o Acquire::https::Timeout=15 -o Acquire::Retries=3)

    # update 单独判 rc：源不可达时在这里就报根因并提示 OCR_APT_MIRROR，
    # 不留到 install 阶段被几百行 Ign/Err 掩盖（用户 NAS 实测教训）
    apt-get update -qq "${APT_OPTS[@]}" </dev/null \
        || { echo "[ocr-init] FATAL: apt-get update failed (mirror unreachable? set OCR_APT_MIRROR)"; exit 1; }

    # glib 包名自适应：trixie 因 64-bit time_t 迁移把 libglib2.0-0 改名
    # libglib2.0-0t64。apt-cache policy 对未知包也返回 rc=0（Candidate: (none)），
    # 故按 Candidate 行判定，取第一个有真实候选的包名，一条 install 装齐，
    # 不再"先装旧名失败再回退新名"多跑一轮；两者都无候选时按当前基础镜像
    # （trixie）取 t64。
    GLIB_PKG=""
    for cand in libglib2.0-0 libglib2.0-0t64; do
        if apt-cache policy "$cand" </dev/null 2>/dev/null | grep -qE '^[[:space:]]*Candidate:[[:space:]]+[^[:space:](]'; then
            GLIB_PKG="$cand"
            break
        fi
    done
    [ -n "$GLIB_PKG" ] || GLIB_PKG=libglib2.0-0t64
    echo "[ocr-init] glib package resolved: ${GLIB_PKG}"

    # libgomp1 是 onnxruntime CPU EP 的运行时依赖，slim 不保证自带，装了无害
    apt-get install -y --no-install-recommends "${APT_OPTS[@]}" \
        libgl1 "$GLIB_PKG" libgomp1 </dev/null \
        || { echo "[ocr-init] FATAL: apt-get install libgl1/${GLIB_PKG}/libgomp1 failed"; exit 1; }
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
python3 -m pip install \
    paddlepaddle==3.0.0 "paddlex[ocr]==3.0.3" paddle2onnx onnxruntime </dev/null \
    || { echo "[ocr-init] FATAL: pip install failed (slow or unreachable index? set OCR_PIP_INDEX to a domestic mirror)"; exit 1; }

# ---- fail-fast 预检：确认 cv2 native 库就位 ----
# 缺系统库时让日志一句话讲清根因，而不是 exec 阶段甩 40 行 traceback。
# 故意用 import cv2 而非 import paddlex：后者会顺带触发模型索引扫描，慢且可能
# 引发下载，不适合做启动预检。
python3 -c 'import cv2' </dev/null 2>/dev/null \
    || { echo "[ocr-init] FATAL: cv2 import failed, missing system libs (libGL/libglib)"; exit 1; }
echo "[ocr-init] cv2 import ok"

# serving 插件（uvicorn/fastapi 等，提供 /ocr、/openapi.json HTTP 服务）
paddlex --install serving </dev/null \
    || { echo "[ocr-init] FATAL: paddlex --install serving failed (slow or unreachable index? set OCR_PIP_INDEX to a domestic mirror)"; exit 1; }

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
