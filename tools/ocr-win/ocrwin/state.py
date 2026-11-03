"""本地状态目录：done.jsonl / failed.jsonl / results.jsonl（断点续跑靠前者，不依赖服务端）。

三个文件都在 `--out-dir`（默认 `<输入目录>/.ocr-win-state`），均为**追加写**、
UTF-8、`\\n` 结尾、一行一个 JSON。解析失败的残缺行直接忽略（断电写半行不影响续跑）。
"""

from __future__ import annotations

import json
import os
from pathlib import Path


def _dumps(obj: dict) -> str:
    return json.dumps(obj, ensure_ascii=False, separators=(",", ":"))


class StateStore:
    def __init__(self, out_dir: Path):
        self.dir = Path(out_dir)
        self.dir.mkdir(parents=True, exist_ok=True)
        self.done_path = self.dir / "done.jsonl"
        self.failed_path = self.dir / "failed.jsonl"
        self.results_path = self.dir / "results.jsonl"

    # ---- 续跑 ----
    def load_done(self) -> dict[str, dict]:
        """返回 {content_hash|params_key: 记录}。params_key 参与键，参数变了不会误跳。"""
        done: dict[str, dict] = {}
        if not self.done_path.is_file():
            return done
        try:
            with open(str(self.done_path), "r", encoding="utf-8", errors="replace") as fh:
                for line in fh:
                    line = line.strip()
                    if not line:
                        continue
                    try:
                        rec = json.loads(line)
                    except Exception:
                        continue  # 残缺行忽略
                    if not isinstance(rec, dict):
                        continue
                    key = self._key(rec.get("content_hash", ""), rec.get("params_key", ""))
                    if key:
                        done[key] = rec
        except OSError:
            return done
        return done

    @staticmethod
    def _key(content_hash: str, params_key: str) -> str:
        h = (content_hash or "").strip().lower()
        k = (params_key or "").strip()
        if not h or not k:
            return ""
        return h + "|" + k

    # ---- 追加写 ----
    def _append(self, path: Path, rec: dict):
        os.makedirs(str(self.dir), exist_ok=True)
        with open(str(path), "a", encoding="utf-8", newline="\n") as fh:
            fh.write(_dumps(rec) + "\n")
            fh.flush()
            try:
                os.fsync(fh.fileno())
            except OSError:
                pass

    def mark_done(self, content_hash: str, params_key: str, size: int, pages: int,
                  chars: int, source: str):
        self._append(self.done_path, {
            "content_hash": content_hash,
            "params_key": params_key,
            "size": int(size),
            "pages": int(pages),
            "chars": int(chars),   # 仅本地统计口径，不回传（服务端重算）
            "source": source,
        })

    def mark_failed(self, content_hash: str, reason: str, attempts: int, path: str = ""):
        self._append(self.failed_path, {
            "content_hash": content_hash,
            "reason": reason,
            "attempts": int(attempts),
            "path": path,
        })

    def append_result(self, item: dict):
        """已回导成功的一行，留档便于核对/重放（不参与任何服务端判定）。"""
        self._append(self.results_path, item)
