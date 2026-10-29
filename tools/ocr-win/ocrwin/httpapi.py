"""HTTP 传输层 + NAS 两个后台接口的客户端。

传输默认走 requests；环境里没装 requests 时自动退回标准库 urllib（行为等价，
零依赖也能跑）。两条路径都只做一件事：把 (method, url, headers, body) 发出去，
把 (status, body) 收回来——测试可以注入自己的 Transport 打真 HTTP。
"""

from __future__ import annotations

import json
import urllib.error
import urllib.request

USER_AGENT = "ocr-win/1.0 (+siyuan-plugin-share)"


class ApiError(Exception):
    """接口调用失败（网络异常 / 非 2xx / 回包不是 JSON）。"""

    def __init__(self, message: str, status: int = 0, body: str = "", url: str = ""):
        super().__init__(message)
        self.status = status
        self.body = body
        self.url = url


class RequestsTransport:
    name = "requests"

    def request(self, method: str, url: str, headers: dict, body: bytes | None, timeout: float):
        import requests  # 延迟导入：没装时由 default_transport() 决定退回 urllib

        resp = requests.request(method, url, headers=headers, data=body, timeout=timeout)
        return resp.status_code, resp.content


class UrllibTransport:
    name = "urllib"

    def request(self, method: str, url: str, headers: dict, body: bytes | None, timeout: float):
        req = urllib.request.Request(url, data=body, headers=headers, method=method)
        try:
            with urllib.request.urlopen(req, timeout=timeout) as resp:
                return int(resp.status), resp.read()
        except urllib.error.HTTPError as exc:  # 4xx/5xx 也当正常回包返回，交给上层判读
            return int(exc.code), exc.read()


def default_transport():
    """优先 requests，缺失时退回 urllib（不抛错，保证单机可跑）。"""
    try:
        import requests  # noqa: F401

        return RequestsTransport()
    except Exception:
        return UrllibTransport()


class ApiClient:
    """NAS 后台两个 OCR 接口的最小客户端。

    鉴权走 API Key（`X-Api-Key`，与后台 require_api_user 同一取 key 位置），
    同时附 `Authorization: Bearer`——服务端两条都认，多带一条不影响判定。
    API Key 通道不走 cookie，因此 CSRF 面不存在（服务端对 apikey 通道免 CSRF）。
    """

    def __init__(self, server: str, token: str = "", transport=None,
                 params_url: str | None = None,
                 params_timeout: float = 30.0, import_timeout: float = 300.0):
        self.server = (server or "").strip().rstrip("/")
        self.token = (token or "").strip()
        self.transport = transport or default_transport()
        self.params_url = params_url or self._url("/admin/netdisk/ocr/params")
        self._import_url = self._url("/admin/netdisk/ocr/import")
        self.params_timeout = params_timeout
        self.import_timeout = import_timeout

    # ---- 内部 ----
    def _url(self, path: str) -> str:
        base = (self.server or "").strip().rstrip("/")
        return base + path

    def _headers(self, content_type: str) -> dict:
        h = {
            "Accept": "application/json",
            "User-Agent": USER_AGENT,
        }
        if content_type:
            h["Content-Type"] = content_type
        if self.token:
            h["X-Api-Key"] = self.token
            h["Authorization"] = "Bearer " + self.token
        return h

    @staticmethod
    def _parse(raw: bytes) -> dict:
        try:
            text = raw.decode("utf-8", "replace")
            data = json.loads(text)
        except Exception:
            raise ApiError("回包不是合法 JSON", 0, raw[:200].decode("utf-8", "replace"))
        if not isinstance(data, dict):
            raise ApiError("回包不是 JSON 对象", 0, raw[:200].decode("utf-8", "replace"))
        return data

    # ---- 两个端点 ----
    def get_params(self) -> dict:
        """GET /admin/netdisk/ocr/params —— 只读无副作用，失败必须报错退出。"""
        url = self.params_url
        try:
            status, raw = self.transport.request("GET", url, self._headers(""), None, self.params_timeout)
        except Exception as exc:
            raise ApiError("参数接口请求失败：%s" % exc, 0, "", url)
        data = self._parse(raw) if raw else {}
        if status != 200:
            msg = str(data.get("msg") or data.get("message") or "")[:200]
            raise ApiError("参数接口返回 HTTP %d%s" % (status, ("：" + msg) if msg else ""),
                           status, raw[:200].decode("utf-8", "replace"), url)
        return data

    def post_import(self, params_key: str, items: list[dict]) -> dict:
        """POST /admin/netdisk/ocr/import —— 单批 ≤50 行、请求体 ≤4 MB。"""
        body = {"v": 1, "params_key": params_key, "items": items}
        payload = json.dumps(body, ensure_ascii=False).encode("utf-8")
        url = self._import_url
        try:
            status, raw = self.transport.request("POST", url, self._headers("application/json"),
                                                 payload, self.import_timeout)
        except Exception as exc:
            raise ApiError("回导请求失败：%s" % exc, 0, "", url)
        data = self._parse(raw) if raw else {}
        if status != 200:
            msg = str(data.get("msg") or data.get("message") or "")[:200]
            raise ApiError("回导接口返回 HTTP %d%s" % (status, ("：" + msg) if msg else ""),
                           status, raw[:200].decode("utf-8", "replace"), url)
        return data
