"""回导批次管理：攒批（≤50 行 / ≤4 MB）→ POST → 逐行记账。

服务端语义（0025）：
  * 单批 >50 行或请求体 >4 MB → 整批拒收（所以这里必须自己先切好）；
  * 逐行 rejected 带 reason，单行失败不影响同批其他行；
  * 顶层 params_key 与服务端不一致 → 整批拒收 + 回带 expected_params_key
    → 本工具按任务书 4.2 要求提示用户并以非 0 退出码结束。
"""

from __future__ import annotations

import json
import time

from .httpapi import ApiError

# 请求体硬上限 4 MB；留 64 KB 余量给 JSON 外壳与编码差异
DEFAULT_BYTES_MAX = 4 * 1048576 - 65536
DEFAULT_BATCH_MAX = 50

RETRY_ATTEMPTS = 2
RETRY_SLEEP = 1.5


class ParamsMismatch(Exception):
    def __init__(self, expected: str):
        super().__init__("params_key 不一致，服务端期望 %r" % expected)
        self.expected = expected


def item_bytes(item: dict) -> int:
    return len(json.dumps(item, ensure_ascii=False, separators=(",", ":")).encode("utf-8"))


class Uploader:
    def __init__(self, client, params_key: str, store, log,
                 batch_max: int = DEFAULT_BATCH_MAX,
                 bytes_max: int = DEFAULT_BYTES_MAX):
        self.client = client
        self.params_key = params_key
        self.store = store
        self.log = log
        self.batch_max = max(1, min(int(batch_max), DEFAULT_BATCH_MAX))
        self.bytes_max = max(1024, min(int(bytes_max), DEFAULT_BYTES_MAX))  # 不许超过服务端 4 MB 硬上限
        self.items: list[dict] = []
        self.bytes = 0
        self.stats = {"accepted": 0, "rejected": 0, "upload_failed": 0, "too_large": 0}
        self.batches = 0
        self.last_expected_key = ""

    # ---- 攒批 ----
    def add(self, item: dict) -> bool:
        """返回 True 表示已进入待发批次；False 表示单行就超 4 MB（本地直接判失败）。"""
        size = item_bytes(item)
        if size + 128 > self.bytes_max:
            self.store.mark_failed(item.get("content_hash", ""),
                                   "text_too_large", 1, item.get("_path", ""))
            self.stats["too_large"] += 1
            self.log("跳过：单行结果 %d 字节，超过 4 MB 单批上限，无法回导（%s）"
                     % (size, item.get("_path", "")))
            return False
        if self.items and (len(self.items) >= self.batch_max
                           or self.bytes + size > self.bytes_max):
            self.flush()
        self.items.append(item)
        self.bytes += size
        return True

    # ---- 发送 ----
    def _send(self, items: list[dict]) -> dict:
        # 内部字段（_path 等）只在本地日志/失败清单里用，不发给服务端
        payload = [{k: v for k, v in it.items() if not k.startswith("_")} for it in items]
        last: Exception | None = None
        for attempt in range(1, RETRY_ATTEMPTS + 1):
            try:
                return self.client.post_import(self.params_key, payload)
            except ApiError as exc:
                last = exc
                # 4xx 是请求本身的问题（超限/鉴权/JSON），重试无意义
                if exc.status and 400 <= exc.status < 500:
                    break
                if attempt < RETRY_ATTEMPTS:
                    time.sleep(RETRY_SLEEP * attempt)
        raise last if last else ApiError("回导失败")

    def flush(self):
        if not self.items:
            return
        items, self.items, self.bytes = self.items, [], 0
        self.batches += 1
        try:
            resp = self._send(items)
        except ApiError as exc:
            self.stats["upload_failed"] += len(items)
            tag = ("http_%d" % exc.status) if exc.status else "network_error"
            self.log("整批回导失败（%d 行）：%s" % (len(items), exc))
            for it in items:
                self.store.mark_failed(it.get("content_hash", ""), tag, RETRY_ATTEMPTS,
                                       it.get("_path", ""))
            return

        rejected = resp.get("rejected") or []
        rej_map: dict[str, str] = {}
        for row in rejected:
            if not isinstance(row, dict):
                continue
            h = str(row.get("content_hash") or "").strip().lower()
            if h and h not in rej_map:
                rej_map[h] = str(row.get("reason") or "unknown")

        expected = str(resp.get("expected_params_key") or "").strip()
        if expected:
            self.last_expected_key = expected
        if any(reason == "params_mismatch" for reason in rej_map.values()):
            raise ParamsMismatch(expected or "(服务端未回带)")

        accepted = 0
        for it in items:
            h = str(it.get("content_hash") or "")
            reason = rej_map.get(h.lower())
            if reason:
                self.stats["rejected"] += 1
                self.store.mark_failed(h, reason, 1, it.get("_path", ""))
                self.log("拒收 %s：%s（%s）" % (h[:12], reason, it.get("_path", "")))
                continue
            accepted += 1
            self.store.mark_done(h, self.params_key, int(it.get("size") or 0),
                                 int(it.get("pages") or 0),
                                 len(str(it.get("text") or "")), "win")
            self.store.append_result(_public_item(it))
        self.stats["accepted"] += accepted
        if accepted != int(resp.get("accepted") or 0):
            self.log("注意：服务端 accepted=%s 与本地计数=%d 不一致，以服务端为准"
                     % (resp.get("accepted"), accepted))

    def close(self):
        self.flush()


def _public_item(item: dict) -> dict:
    """落 results.jsonl 的副本：去掉内部字段、只留回传的东西。"""
    return {
        "content_hash": item.get("content_hash", ""),
        "size": item.get("size", 0),
        "pages": item.get("pages", 0),
        "text": item.get("text", ""),
        "engine": item.get("engine", ""),
    }
