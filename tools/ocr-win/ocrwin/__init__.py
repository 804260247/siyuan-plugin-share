"""PC 端（Windows）离线 OCR 工具：本地渲染 + 识别，识别结果经 HTTP 回导 NAS。

设计铁律（0026 任务书 4.3）：
  * 本工具**绝不**读写 NAS 上的 app.db。SQLite WAL 经 SMB 共享会锁错甚至损坏库，
    唯一写入口是 `POST /admin/netdisk/ocr/import`。
  * 只回传 content_hash / size / pages / text / engine 五个字段；
    chars / quality / status / attempts 一律由服务端重算（本地算的作废）。
  * 渲染参数（dpi / max_side）与 params_key 必须来自服务端
    `GET /admin/netdisk/ocr/params`，拉不到就报错退出，不拿本地默认值硬跑——
    否则 params_key 对不上，整批结果作废。
"""

__version__ = "1.0.0"

# 与 NAS 侧车保持同源的引擎标识（回导时作溯源标记，不参与命中判定）
ENGINE_LABEL = "paddle5"
