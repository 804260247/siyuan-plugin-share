"""离线自测（不需要 paddlex / 不需要真 NAS）。

做法：
  * 用标准库 http.server 起一个「NAS 桩」，只实现两个真实端点 + 与 0025 一致的限制
    （≤50 行、≤4 MB、params_key 不一致整批拒收并回带 expected_params_key、401 鉴权）；
  * 用假引擎替换 `ocrwin.engine.get_engine`（不加载 paddle，只验流水线与协议）；
  * PDF / PNG 夹具用 PyMuPDF 与 Pillow 现做，渲染路径是**真渲染**。

运行：`python tests/run_tests.py`（工作目录任意）。依赖：PyMuPDF、Pillow。
"""

from __future__ import annotations

import contextlib
import hashlib
import io
import json
import os
import shutil
import sys
import tempfile
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from ocrwin import cli, engine as engine_mod, params as params_mod, upload as upload_mod  # noqa: E402
from ocrwin import pydeps  # noqa: E402
from ocrwin.httpapi import ApiClient, UrllibTransport  # noqa: E402
from ocrwin.state import StateStore  # noqa: E402

TOKEN = "test-token-123456"
KEY = "paddle5|high|200|960|r1"


# --------------------------------------------------------------------------- NAS 桩

class NasStub:
    def __init__(self):
        self.token = TOKEN
        self.params = {
            "v": 1, "engine": "paddle-ppocrv5", "engine_version": "paddlex 3.0.3 / paddlepaddle 3.0.0",
            "profile": "high", "dpi": 200, "max_side": 960, "render_ver": "r1", "params_key": KEY,
        }
        self.expected_key = KEY
        self.posts: list[dict] = []
        self.texts: dict[str, str] = {}
        self.reject_reason: str | None = None
        stub = self

        class Handler(BaseHTTPRequestHandler):
            protocol_version = "HTTP/1.1"

            def log_message(self, *a):  # 静音
                pass

            def _json(self, code, payload):
                body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
                self.send_response(code)
                self.send_header("Content-Type", "application/json; charset=utf-8")
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body)

            def _auth_ok(self):
                return self.headers.get("X-Api-Key", "") == stub.token

            def do_GET(self):
                if self.path != "/admin/netdisk/ocr/params":
                    return self._json(404, {"code": 404, "msg": "not found"})
                if not self._auth_ok():
                    return self._json(401, {"code": 401, "msg": "未登录或 API Key 无效"})
                return self._json(200, stub.params)

            def do_POST(self):
                if self.path != "/admin/netdisk/ocr/import":
                    return self._json(404, {"code": 404, "msg": "not found"})
                if not self._auth_ok():
                    return self._json(401, {"code": 401, "msg": "未登录或 API Key 无效"})
                n = int(self.headers.get("Content-Length") or 0)
                raw = self.rfile.read(n)
                if len(raw) > 4 * 1048576:
                    return self._json(413, {"code": 413, "msg": "请求体超过 4 MB 上限，整批未写入"})
                body = json.loads(raw.decode("utf-8"))
                items = body.get("items") or []
                if len(items) > 50:
                    return self._json(400, {"code": 400, "msg": "单批最多 50 行"})
                stub.posts.append({"key": body.get("params_key"), "items": items, "bytes": len(raw)})
                if body.get("params_key") != stub.expected_key:
                    return self._json(200, {
                        "code": 0, "msg": "", "accepted": 0,
                        "rejected": [{"content_hash": str(it.get("content_hash", "")),
                                      "reason": "params_mismatch"} for it in items],
                        "expected_params_key": stub.expected_key,
                    })
                rejected = []
                accepted = 0
                for it in items:
                    h = str(it.get("content_hash", ""))
                    if stub.reject_reason:
                        rejected.append({"content_hash": h, "reason": stub.reject_reason})
                        continue
                    stub.texts[h] = str(it.get("text", ""))
                    accepted += 1
                return self._json(200, {"code": 0, "msg": "", "accepted": accepted, "rejected": rejected})

        self.httpd = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        self.port = self.httpd.server_address[1]
        self.thread = threading.Thread(target=self.httpd.serve_forever, daemon=True)

    @property
    def base(self):
        return "http://127.0.0.1:%d" % self.port

    def __enter__(self):
        self.thread.start()
        return self

    def __exit__(self, *exc):
        self.httpd.shutdown()
        self.httpd.server_close()


# --------------------------------------------------------------------------- 假引擎

class FakeEngine:
    calls = 0
    fail_times = 0          # 需要连续失败的次数（用于 2 次重试用例）
    label = "fake"

    def __init__(self, device="cpu"):
        self.device = device

    def ensure_ready(self):
        return None

    def recognize_image(self, jpeg_path):
        type(self).calls += 1
        if type(self).fail_times > 0:
            type(self).fail_times -= 1
            raise engine_mod.OcrEngineError("模拟引擎故障")
        n = type(self).calls
        return ["第%d页" % n, "sample text %d" % n]

    @classmethod
    def reset(cls):
        cls.calls = 0
        cls.fail_times = 0


# --------------------------------------------------------------------------- 夹具

def make_pdf(path: Path, pages: int = 2):
    import pymupdf

    doc = pymupdf.open()
    for i in range(pages):
        page = doc.new_page()
        page.insert_text((72, 72), "Page %d sample text" % (i + 1), fontsize=12)
    doc.save(str(path))
    doc.close()


def make_png(path: Path, w: int = 1200, h: int = 800):
    from PIL import Image

    Image.new("RGB", (w, h), (255, 255, 255)).save(str(path))


def sha256_of(path: Path) -> str:
    h = hashlib.sha256()
    h.update(Path(path).read_bytes())
    return h.hexdigest()


def run_cli(argv):
    buf = io.StringIO()
    with contextlib.redirect_stdout(buf):
        rc = cli.main(argv)
    return rc, buf.getvalue()


class BaseCase(unittest.TestCase):
    def setUp(self):
        FakeEngine.reset()
        self._orig_get_engine = engine_mod.get_engine
        engine_mod.get_engine = lambda device="cpu": FakeEngine(device)
        self.tmp = Path(tempfile.mkdtemp(prefix="ocrwin-test-"))
        self.input = self.tmp / "in"
        self.input.mkdir()

    def tearDown(self):
        engine_mod.get_engine = self._orig_get_engine
        shutil.rmtree(str(self.tmp), ignore_errors=True)


# --------------------------------------------------------------------------- 用例

class TestScanAndDryRun(BaseCase):
    def test_extension_filter_and_dry_run(self):
        """任务书 4.4 用例 1：5 PDF + 2 图 + 1 docx → 处理 7、跳过 docx 并计数。"""
        for i in range(5):
            make_pdf(self.input / ("doc%d.pdf" % i), pages=1)
        for i in range(2):
            make_png(self.input / ("img%d.png" % i))
        (self.input / "note.docx").write_bytes(b"PK\x03\x04 not a real docx")
        with NasStub() as stub:
            rc, out = run_cli(["run", "--input", str(self.input), "--server", stub.base,
                               "--token", TOKEN, "--dry-run"])
        self.assertEqual(rc, cli.EXIT_OK, out)
        self.assertIn("可处理 7 个", out)
        self.assertIn("扩展名跳过 1 个", out)
        self.assertEqual(FakeEngine.calls, 0, "dry-run 不许识别")
        self.assertEqual(stub.posts, [], "dry-run 不许回导")


class TestEndToEnd(BaseCase):
    def test_import_then_resume(self):
        """用例 1/2 + 端到端：真渲染 → 假识别 → 真 HTTP 回导 → 断点续跑 0 次识别。"""
        make_pdf(self.input / "a.pdf", pages=2)
        make_pdf(self.input / "b.pdf", pages=1)
        make_png(self.input / "c.png")
        (self.input / "skip.txt").write_text("ignored", encoding="utf-8")
        with NasStub() as stub:
            rc, out = run_cli(["run", "--input", str(self.input), "--server", stub.base,
                               "--token", TOKEN])
            self.assertEqual(rc, cli.EXIT_OK, out)
            self.assertIn("识别成功 3", out)
            self.assertIn("回导 accepted=3", out)
            self.assertEqual(len(stub.texts), 3)
            # 哈希与「原始文件字节」一致，pages 与真实页数一致
            sent = {it["content_hash"]: it for p in stub.posts for it in p["items"]}
            for name, pages in (("a.pdf", 2), ("b.pdf", 1), ("c.png", 1)):
                h = sha256_of(self.input / name)
                self.assertIn(h, sent, "%s 未回导" % name)
                self.assertEqual(sent[h]["pages"], pages)
                self.assertEqual(sent[h]["size"], (self.input / name).stat().st_size)
                self.assertEqual(sent[h]["engine"], "paddle5")
            # 文本单行（服务端还会再单行化一次，本地也不许带换行）
            for it in sent.values():
                self.assertNotIn("\n", it["text"])
                self.assertNotIn("\r", it["text"])
            # 每批 ≤50 行
            for p in stub.posts:
                self.assertLessEqual(len(p["items"]), 50)

            # 第二次跑：全部命中 done.jsonl → 0 次识别、0 次回导
            calls_before = FakeEngine.calls
            posts_before = len(stub.posts)
            rc2, out2 = run_cli(["run", "--input", str(self.input), "--server", stub.base,
                                 "--token", TOKEN])
            self.assertEqual(rc2, cli.EXIT_OK, out2)
            self.assertIn("已完成跳过 3", out2)
            self.assertEqual(FakeEngine.calls, calls_before, "续跑不许再识别")
            self.assertEqual(len(stub.posts), posts_before, "续跑不许再回导")

    def test_no_resume_reprocesses(self):
        make_pdf(self.input / "a.pdf", pages=1)
        with NasStub() as stub:
            run_cli(["run", "--input", str(self.input), "--server", stub.base, "--token", TOKEN])
            calls = FakeEngine.calls
            rc, out = run_cli(["run", "--input", str(self.input), "--server", stub.base,
                               "--token", TOKEN, "--no-resume"])
        self.assertEqual(rc, cli.EXIT_OK, out)
        self.assertGreater(FakeEngine.calls, calls)

    def test_duplicate_content_recognized_once(self):
        """同内容不同文件名：只识别一次（内容寻址的意义）。"""
        make_pdf(self.input / "a.pdf", pages=1)
        shutil.copy(str(self.input / "a.pdf"), str(self.input / "a-copy.pdf"))
        with NasStub() as stub:
            rc, out = run_cli(["run", "--input", str(self.input), "--server", stub.base,
                               "--token", TOKEN])
        self.assertEqual(rc, cli.EXIT_OK, out)
        self.assertEqual(len(stub.texts), 1)
        self.assertIn("同内容重复 1", out)


class TestFailurePaths(BaseCase):
    def test_params_unreachable_exits_2(self):
        """用例 3 反面：参数拉不到 → 直接退出，不许用本地默认值硬跑。"""
        with NasStub() as stub:
            dead = "http://127.0.0.1:%d" % stub.port  # 关掉后用这个地址
        rc, out = run_cli(["run", "--input", str(self.input), "--server", dead, "--token", TOKEN])
        self.assertEqual(rc, cli.EXIT_STARTUP, out)
        self.assertIn("参数拉取失败", out)
        self.assertEqual(FakeEngine.calls, 0)

    def test_bad_token_exits_2(self):
        make_pdf(self.input / "a.pdf", pages=1)
        with NasStub() as stub:
            rc, out = run_cli(["run", "--input", str(self.input), "--server", stub.base,
                               "--token", "wrong-token"])
        self.assertEqual(rc, cli.EXIT_STARTUP, out)
        self.assertIn("401", out)

    def test_params_mismatch_exits_3(self):
        """任务书 4.4 用例 4：服务端换了参数（客户端拿到的 key 已过期）→ 提示并退出 3。"""
        make_pdf(self.input / "a.pdf", pages=1)
        with NasStub() as stub:
            stub.expected_key = "paddle5|high|300|960|r1"   # 只 import 认这个，params 仍发旧 key
            rc, out = run_cli(["run", "--input", str(self.input), "--server", stub.base,
                               "--token", TOKEN])
        self.assertEqual(rc, cli.EXIT_PARAMS_MISMATCH, out)
        self.assertIn("paddle5|high|300|960|r1", out)

    def test_corrupt_pdf_retries_twice_then_failed(self):
        """任务书 4.3：单文件最多 2 次，之后进 failed.jsonl，不阻塞其他文件。"""
        (self.input / "broken.pdf").write_bytes(b"%PDF-1.4 not really a pdf")
        make_pdf(self.input / "good.pdf", pages=1)
        with NasStub() as stub:
            rc, out = run_cli(["run", "--input", str(self.input), "--server", stub.base,
                               "--token", TOKEN])
            self.assertEqual(rc, cli.EXIT_PARTIAL, out)
            self.assertIn("失败 broken.pdf", out)
            failed = [json.loads(l) for l in
                      (self.input / cli.STATE_DIR_NAME / "failed.jsonl").read_text("utf-8").splitlines()]
            self.assertEqual(len(failed), 1)
            self.assertEqual(failed[0]["attempts"], 2)
            self.assertEqual(len(stub.texts), 1, "好文件必须照常回导")

    def test_rejected_item_recorded(self):
        make_pdf(self.input / "a.pdf", pages=1)
        with NasStub() as stub:
            stub.reject_reason = "size_mismatch"
            rc, out = run_cli(["run", "--input", str(self.input), "--server", stub.base,
                               "--token", TOKEN])
        self.assertEqual(rc, cli.EXIT_PARTIAL, out)
        self.assertIn("size_mismatch", out)


class TestUploader(BaseCase):
    def test_batches_respect_50_rows(self):
        """单批 ≤50 行：120 条必须切成 ≥3 批，且每批 ≤50。"""
        with NasStub() as stub:
            store = StateStore(self.tmp / "state")
            client = ApiClient(stub.base, TOKEN, transport=UrllibTransport())
            up = upload_mod.Uploader(client, KEY, store, lambda m: None)
            for i in range(120):
                up.add({"content_hash": "%064x" % i, "size": 10, "pages": 1,
                        "text": "t%d" % i, "engine": "paddle5"})
            up.close()
        self.assertEqual(len(stub.posts), 3)
        self.assertEqual([len(p["items"]) for p in stub.posts], [50, 50, 20])
        self.assertEqual(len(stub.texts), 120)

    def test_single_row_over_4mb_marked_too_large(self):
        """单行结果 >4 MB 无法回导：本地记 text_too_large，不拖垮同批其他行。"""
        with NasStub() as stub:
            store = StateStore(self.tmp / "state")
            client = ApiClient(stub.base, TOKEN, transport=UrllibTransport())
            up = upload_mod.Uploader(client, KEY, store, lambda m: None)
            up.add({"content_hash": "%064x" % 1, "size": 5_000_000, "pages": 1,
                    "text": "x" * (5 * 1048576), "engine": "paddle5"})
            up.add({"content_hash": "%064x" % 2, "size": 10, "pages": 1,
                    "text": "small", "engine": "paddle5"})
            up.close()
        self.assertEqual(up.stats["too_large"], 1)
        self.assertEqual(len(stub.texts), 1)
        self.assertEqual(len(stub.posts), 1)
        self.assertLess(stub.posts[0]["bytes"], 4 * 1048576)


class TestParamsValidation(unittest.TestCase):
    DOC = {"v": 1, "engine": "paddle-ppocrv5", "engine_version": "x", "profile": "high",
           "dpi": 200, "max_side": 960, "render_ver": "r1", "params_key": KEY}

    def test_ok(self):
        self.assertEqual(params_mod.validate(dict(self.DOC))["params_key"], KEY)

    def test_missing_field(self):
        d = dict(self.DOC)
        d.pop("max_side")
        with self.assertRaises(params_mod.ParamError):
            params_mod.validate(d)

    def test_zero_dpi(self):
        d = dict(self.DOC, dpi=0)
        with self.assertRaises(params_mod.ParamError):
            params_mod.validate(d)

    def test_bad_key_shape(self):
        d = dict(self.DOC, params_key="paddle5|high")
        with self.assertRaises(params_mod.ParamError):
            params_mod.validate(d)


class TestSelfCheck(BaseCase):
    def test_self_check_runs(self):
        """self-check 必须在任何环境都能跑完并如实报告依赖（本机无 paddlex → 退出 4）。"""
        rc, out = run_cli(["self-check"])
        self.assertIn("PyMuPDF", out)
        self.assertIn("paddlex", out)
        self.assertIn("GPU", out)      # 0032：GPU 能力探测行必须打，且不影响退出码
        self.assertIn(rc, (cli.EXIT_OK, cli.EXIT_DEPENDENCY), out)


# --------------------------------------------------------------------------- 0031 打包依赖派生

def _fake_dist(root: Path, name: str, version: str, top_level: str, requires=()):
    """造一个 dist-info 目录（顶到 sys.path 前面），模拟已安装的发行包。"""
    d = root / ("%s-%s.dist-info" % (name.replace("-", "_"), version))
    d.mkdir(parents=True, exist_ok=True)
    (d / "METADATA").write_text(
        "Metadata-Version: 2.1\nName: %s\nVersion: %s\n" % (name, version), encoding="utf-8")
    (d / "top_level.txt").write_text(top_level + "\n", encoding="utf-8")
    if requires:
        (d / "METADATA").write_text(
            "Metadata-Version: 2.1\nName: %s\nVersion: %s\n%s\n" % (
                name, version,
                "".join("Requires-Dist: %s\n" % r for r in requires), ),
            encoding="utf-8")
    return d


class TestPydeps(unittest.TestCase):
    """OCR extras 必须从 paddlex 元数据派生，不许硬编码。"""

    REQUIRES = [
        'einops; extra == "ocr"',
        "ftfy; extra == \"ocr\"",
        'opencv-contrib-python==4.10.0.84; extra == "ocr"',
        'pypdfium2>=4; extra == "ocr"',
        'Jinja2; extra == "ocr"',
        'tokenizers>=0.19; extra == "ocr"',
        'lxml; extra == "ocr" and platform_system == "Windows"',
        'paddlepaddle==3.0.0',
        'pyinstaller>=6,<7; extra == "dev"',
        'PyYAML; extra == "serving"',
    ]

    def test_requirement_names_filters_extra(self):
        names = pydeps.requirement_names(requires=self.REQUIRES)
        self.assertEqual(names, ["einops", "ftfy", "opencv-contrib-python",
                                 "pypdfium2", "jinja2", "tokenizers", "lxml"])

    def test_requirement_names_absent_dist_returns_empty(self):
        self.assertEqual(pydeps.requirement_names("no-such-dist-xyz", requires=None), [])

    def test_top_level_mapping(self):
        """包名≠模块名（opencv-contrib-python→cv2、scikit-learn→sklearn）走 top_level.txt。"""
        tmp = Path(tempfile.mkdtemp(prefix="ocrwin-deps-"))
        try:
            _fake_dist(tmp, "opencv-contrib-python", "4.10.0.84", "cv2")
            _fake_dist(tmp, "scikit-learn", "1.5.2", "sklearn")
            _fake_dist(tmp, "Jinja2", "3.1.4", "jinja2")
            sys.path.insert(0, str(tmp))
            try:
                self.assertEqual(pydeps.top_level_modules("opencv-contrib-python"), ["cv2"])
                self.assertEqual(pydeps.top_level_modules("scikit-learn"), ["sklearn"])
                self.assertEqual(pydeps.top_level_modules("jinja2"), ["jinja2"])
                self.assertEqual(pydeps.top_level_modules("no-such-dist-xyz"), [])
                pairs = pydeps.module_pairs(requires=self.REQUIRES)
                # 未安装的发行包跳过，已安装的按 dist→module 映射
                self.assertEqual([p for p in pairs if p[0] == "opencv-contrib-python"],
                                 [("opencv-contrib-python", "cv2")])
                args = pydeps.pyinstaller_args(skip=("paddlex", "paddle"),
                                               requires=self.REQUIRES)
                self.assertIn("--collect-all", args)
                self.assertIn("cv2", args)
                self.assertNotIn("paddlex", args)   # skip 生效
            finally:
                sys.path.remove(str(tmp))
        finally:
            shutil.rmtree(str(tmp), ignore_errors=True)

    def test_missing_modules_reports_names(self):
        pairs = [("opencv-contrib-python", "definitely_not_a_module_xyz"), ("jinja2", "sys")]
        miss = pydeps.missing_modules(pairs)
        self.assertEqual(miss, [("opencv-contrib-python", "definitely_not_a_module_xyz")])

    def test_summary_mentions_dist_and_count(self):
        self.assertIn("paddlex[ocr]", pydeps.summary("paddlex", "ocr"))


class TestBuildExe(unittest.TestCase):
    """build_exe.py 是 build.ps1 / build.cmd 的唯一参数来源。"""

    def test_argv_contains_derived_collect_all(self):
        import build_exe
        orig = pydeps.module_pairs
        pydeps.module_pairs = lambda *a, **k: [("opencv-contrib-python", "cv2"),
                                               ("scikit-learn", "sklearn")]
        try:
            onedir = build_exe.pyinstaller_argv(False)
            onefile = build_exe.pyinstaller_argv(True)
        finally:
            pydeps.module_pairs = orig
        self.assertIn("--onedir", onedir)
        self.assertIn("--onefile", onefile)
        for arg in ("--collect-all", "cv2", "sklearn", "run_ocr_win.py"):
            self.assertIn(arg, onedir)
        self.assertEqual(onedir.count("cv2"), 1)          # 同一个模块只收一次
        self.assertLessEqual(onedir.count("--collect-all"), 2)  # 基础模块不重复收集

    def test_print_only_runs_offline(self):
        import build_exe
        rc = build_exe.main(["--print-only"])
        # 沙箱里没装 paddlex[ocr] → 明确报退出码 4，而不是假装成功
        self.assertEqual(rc, build_exe.EXIT_DEPENDENCY)


class TestSelfCheckPipeline(unittest.TestCase):
    """self-check 必须真初始化 pipeline（0031 的第二个缺陷）。"""

    def setUp(self):
        self.calls = []
        self._orig_ver = cli._ver
        self._orig_req = pydeps.requirement_names
        self._orig_miss = pydeps.missing_modules
        self._orig_pipe = engine_mod.PaddleEngine

        cli._ver = lambda m: "9.9.9" if m in ("pymupdf", "PIL", "paddlex", "paddle") else None
        pydeps.requirement_names = lambda *a, **k: ["einops", "opencv-contrib-python"]
        pydeps.missing_modules = lambda *a, **k: []

        outer = self

        class FakePipeline:
            def __init__(self, device="cpu"):
                outer.calls.append(device)

            def ensure_ready(self):
                outer.calls.append("ensure_ready")

        engine_mod.PaddleEngine = FakePipeline

    def tearDown(self):
        cli._ver = self._orig_ver
        pydeps.requirement_names = self._orig_req
        pydeps.missing_modules = self._orig_miss
        engine_mod.PaddleEngine = self._orig_pipe

    def test_pipeline_initialized_by_default(self):
        rc, out = run_cli(["self-check"])
        self.assertEqual(rc, cli.EXIT_OK, out)
        self.assertIn("ensure_ready", self.calls)
        self.assertIn("初始化成功", out)

    def test_no_pipeline_skips_init(self):
        rc, out = run_cli(["self-check", "--no-pipeline"])
        self.assertEqual(rc, cli.EXIT_OK, out)
        self.assertNotIn("ensure_ready", self.calls)
        self.assertIn("已跳过", out)

    def test_pipeline_failure_exits_4_with_package_names(self):
        class Boom:
            def __init__(self, device="cpu"):
                pass

            def ensure_ready(self):
                raise RuntimeError("OCR requires additional dependencies, please install")

        engine_mod.PaddleEngine = Boom
        pydeps.missing_modules = lambda *a, **k: [("opencv-contrib-python", "cv2")]
        rc, out = run_cli(["self-check"])
        self.assertEqual(rc, cli.EXIT_DEPENDENCY, out)
        self.assertIn("cv2", out)
        self.assertIn("opencv-contrib-python", out)

    def test_dash_self_check_alias(self):
        """第一遍就被敲成 `--self-check`，必须容忍而不是报 run 缺 --input。"""
        rc, out = run_cli(["--self-check", "--no-pipeline"])
        self.assertEqual(rc, cli.EXIT_OK, out)
        self.assertIn("self-check 版本", out)

    def test_frozen_bundle_skips_metadata_check(self):
        """打包产物里没有 dist-info：跳过清单核对，但流水线必须照样真初始化。"""
        sys.frozen = True
        try:
            rc, out = run_cli(["self-check"])
        finally:
            del sys.frozen
        self.assertEqual(rc, cli.EXIT_OK, out)
        self.assertIn("dist-info", out)
        self.assertIn("ensure_ready", self.calls)

    def test_missing_extra_reports_before_pipeline(self):
        pydeps.missing_modules = lambda *a, **k: [("scikit-learn", "sklearn")]
        rc, out = run_cli(["self-check"])
        self.assertEqual(rc, cli.EXIT_DEPENDENCY, out)
        self.assertIn("scikit-learn", out)
        self.assertNotIn("ensure_ready", self.calls)


# --------------------------------------------------------------------------- 0032 性能可观测性 / GPU

class TestPerfObservability(BaseCase):
    """0032：逐文件耗时/页数必须真打出来，汇总补页级指标，缓冲文案不得再说「已导入」。"""

    def test_per_file_line_reports_pages_and_secs(self):
        make_pdf(self.input / "a.pdf", pages=2)
        with NasStub() as stub:
            rc, out = run_cli(["run", "--input", str(self.input), "--server", stub.base,
                               "--token", TOKEN])
        self.assertEqual(rc, cli.EXIT_OK, out)
        self.assertIn("完成 a.pdf pages=2", out)
        self.assertIn("秒/页", out)
        self.assertLess(out.index("完成 a.pdf"), out.index("汇总：总页数"),
                        "逐文件行必须在汇总之前")

    def test_summary_reports_page_metrics(self):
        make_pdf(self.input / "a.pdf", pages=2)
        make_pdf(self.input / "b.pdf", pages=3)
        with NasStub() as stub:
            rc, out = run_cli(["run", "--input", str(self.input), "--server", stub.base,
                               "--token", TOKEN])
        self.assertEqual(rc, cli.EXIT_OK, out)
        # 两个文件页数不同（PDF 内容不同 → 哈希不同 → 都进池）
        self.assertIn("完成 a.pdf pages=2", out)
        self.assertIn("完成 b.pdf pages=3", out)
        self.assertIn("汇总：总页数 5", out)
        self.assertIn("平均", out)
        self.assertIn("页/分钟", out)

    def test_progress_line_says_buffered_not_imported(self):
        make_pdf(self.input / "a.pdf", pages=1)
        with NasStub() as stub:
            rc, out = run_cli(["run", "--input", str(self.input), "--server", stub.base,
                               "--token", TOKEN])
        self.assertEqual(rc, cli.EXIT_OK, out)
        self.assertIn("已缓冲", out)
        self.assertNotIn("已导入", out, "旧文案会让人以为没上传成功")


class _FakeCuda:
    def __init__(self, n):
        self.n = n

    def device_count(self):
        return self.n

    def get_device_name(self, i):
        return "FakeGPU-%d" % i

    def get_cudnn_version(self):
        return 8907


class _FakeDevice:
    def __init__(self, compiled, n):
        self._compiled = compiled
        self.cuda = _FakeCuda(n)

    def is_compiled_with_cuda(self):
        return self._compiled


class _FakePaddle:
    def __init__(self, compiled=False, n=0, ver="3.0.0"):
        self.device = _FakeDevice(compiled, n)
        if ver:
            self.__version__ = ver


class TestGpuProbe(unittest.TestCase):
    """GPU 探测：有卡说可用、无卡说仅 CPU、坏装不抛、都不改退出码。"""

    def test_available(self):
        txt = engine_mod.gpu_summary(_FakePaddle(True, 2))
        self.assertIn("GPU 可用", txt)
        self.assertIn("FakeGPU-0", txt)
        self.assertIn("8907", txt)

    def test_cpu_only_wheel(self):
        txt = engine_mod.gpu_summary(_FakePaddle(False, 0))
        self.assertIn("仅 CPU", txt)
        self.assertIn("CPU 版，未编译 CUDA", txt)
        self.assertIn("paddlepaddle", txt)
        self.assertIn("paddlepaddle-gpu", txt, "要给出换 GPU 版的做法")
        self.assertNotIn("GPU 版但未检测到设备", txt, "CPU 版不能说成「GPU 版无设备」")

    def test_compiled_but_no_visible_device(self):
        txt = engine_mod.gpu_summary(_FakePaddle(True, 0))
        self.assertIn("GPU 版但未检测到设备", txt)
        self.assertIn("paddlepaddle-gpu", txt)
        self.assertNotIn("仅 CPU", txt, "GPU 版无设备不能说成「仅 CPU」")

    def test_two_install_states_are_distinguishable(self):
        """0032 追加项：CPU 版 vs GPU 版无卡，两条文案必须一眼能分开。"""
        cpu = engine_mod.gpu_summary(_FakePaddle(False, 0))
        gpu = engine_mod.gpu_summary(_FakePaddle(True, 0))
        self.assertNotEqual(cpu, gpu)
        self.assertIn("CPU 版", cpu)
        self.assertIn("GPU 版", gpu)

    def test_exact_wording_cpu_version(self):
        """项目经理指定的字面口径：`paddlepaddle 3.0.0（CPU 版，未编译 CUDA）`。"""
        orig = engine_mod._dist_label
        engine_mod._dist_label = lambda p, g, v="": "paddlepaddle 3.0.0"
        try:
            txt = engine_mod.gpu_summary(_FakePaddle(False, 0))
        finally:
            engine_mod._dist_label = orig
        self.assertTrue(txt.startswith("仅 CPU：paddlepaddle 3.0.0（CPU 版，未编译 CUDA）"), txt)

    def test_exact_wording_gpu_version_no_device(self):
        orig = engine_mod._dist_label
        engine_mod._dist_label = lambda p, g, v="": "paddlepaddle-gpu 3.0.0"
        try:
            txt = engine_mod.gpu_summary(_FakePaddle(True, 0))
        finally:
            engine_mod._dist_label = orig
        self.assertTrue(txt.startswith("paddlepaddle-gpu 3.0.0（GPU 版但未检测到设备）"), txt)

    def test_dist_label_names_the_right_dist(self):
        """包名判定：读不到 dist-info 时按「是否编译 CUDA」反推，不许把 GPU wheel 说成 paddlepaddle。"""
        import importlib.metadata as md

        def has(name):
            try:
                md.version(name)
                return True
            except Exception:
                return False

        cpu = engine_mod._dist_label(_FakePaddle(False, 0), False, "3.0.0")
        gpu = engine_mod._dist_label(_FakePaddle(True, 0), True, "3.0.0")
        self.assertIn("3.0.0", cpu)
        self.assertIn("3.0.0", gpu)
        if has("paddlepaddle") or has("paddlepaddle-gpu"):
            self.assertIn("paddlepaddle", cpu)      # 真装了就看 dist-info
            return
        self.assertEqual(cpu, "paddlepaddle 3.0.0")
        self.assertEqual(gpu, "paddlepaddle-gpu 3.0.0")

    def test_broken_paddle_never_raises(self):
        class Boom:
            @property
            def device(self):
                raise RuntimeError("半装的 paddle")

        self.assertIn("仅 CPU", engine_mod.gpu_summary(Boom()))

    def test_no_paddle_at_all_is_safe(self):
        txt = engine_mod.gpu_summary()      # 沙箱通常没装 paddle
        self.assertIsInstance(txt, str)
        self.assertTrue(("仅 CPU" in txt) or ("GPU 可用" in txt), txt)


class TestSelfCheckGpuLine(unittest.TestCase):
    """GPU 探测出错也不许改变 self-check 退出码。"""

    def setUp(self):
        self._orig_ver = cli._ver
        self._orig_req = pydeps.requirement_names
        self._orig_miss = pydeps.missing_modules
        self._orig_pipe = engine_mod.PaddleEngine
        self._orig_gpu = engine_mod.gpu_summary

        cli._ver = lambda m: "9.9.9" if m in ("pymupdf", "PIL", "paddlex", "paddle") else None
        pydeps.requirement_names = lambda *a, **k: ["einops"]
        pydeps.missing_modules = lambda *a, **k: []

        class FakePipeline:
            def __init__(self, device="cpu"):
                pass

            def ensure_ready(self):
                return None

        engine_mod.PaddleEngine = FakePipeline

    def tearDown(self):
        cli._ver = self._orig_ver
        pydeps.requirement_names = self._orig_req
        pydeps.missing_modules = self._orig_miss
        engine_mod.PaddleEngine = self._orig_pipe
        engine_mod.gpu_summary = self._orig_gpu

    def test_probe_exception_does_not_change_exit_code(self):
        def boom(*a, **k):
            raise RuntimeError("cuda 探测炸了")

        engine_mod.gpu_summary = boom
        rc, out = run_cli(["self-check"])
        self.assertEqual(rc, cli.EXIT_OK, out)
        self.assertIn("探测失败", out)

    def test_cpu_only_still_exit_ok(self):
        engine_mod.gpu_summary = lambda *a, **k: "仅 CPU（模拟）"
        rc, out = run_cli(["self-check"])
        self.assertEqual(rc, cli.EXIT_OK, out)
        self.assertIn("仅 CPU（模拟）", out)


# --------------------------------------------------------------------------- 0033 惰性引擎 / GPU 预检

class _EnsureCounter:
    """替身引擎：只统计 ensure_ready 有没有被调用。"""

    label = "fake"

    def __init__(self):
        self.ensure_calls = 0

    def ensure_ready(self):
        self.ensure_calls += 1

    def recognize_image(self, jpeg_path):
        return ["x"]


class TestLazyEngine(BaseCase):
    """0033 第 1 条：待识别 0 个时不许初始化引擎（真机崩在 error code 126 的那条路径）。"""

    def test_zero_todo_does_not_init_engine(self):
        make_pdf(self.input / "a.pdf", pages=1)
        with NasStub() as stub:
            rc1, out1 = run_cli(["run", "--input", str(self.input), "--server", stub.base,
                                 "--token", TOKEN])
            self.assertEqual(rc1, cli.EXIT_OK, out1)
            counter = _EnsureCounter()
            engine_mod.get_engine = lambda device="cpu": counter     # 覆盖 BaseCase 的假引擎
            rc2, out2 = run_cli(["run", "--input", str(self.input), "--server", stub.base,
                                 "--token", TOKEN])
        self.assertEqual(rc2, cli.EXIT_OK, out2)
        self.assertIn("已完成跳过 1", out2)
        self.assertIn("待识别 0 个", out2)
        self.assertIn("跳过引擎初始化", out2)
        self.assertEqual(counter.ensure_calls, 0, "待识别 0 个还去建 pipeline 就是 0033 要修的 bug")
        self.assertNotIn("GPU 运行库", out2)

    def test_nonzero_todo_still_fails_fast_on_bad_engine(self):
        """有任务时仍然要 fail-fast：引擎建不起来照样退 4，不能变成逐个文件失败。"""
        make_pdf(self.input / "a.pdf", pages=1)

        class Boom:
            label = "boom"

            def __init__(self, device="cpu"):
                pass

            def ensure_ready(self):
                raise engine_mod.OcrEngineError("缺少 paddlex")

        engine_mod.get_engine = lambda device="cpu": Boom()
        with NasStub() as stub:
            rc, out = run_cli(["run", "--input", str(self.input), "--server", stub.base,
                               "--token", TOKEN])
        self.assertEqual(rc, cli.EXIT_DEPENDENCY, out)
        self.assertIn("OCR 引擎不可用", out)


class TestGpuPreflight(unittest.TestCase):
    """0033 第 2 条：cuDNN 缺失必须提前拦住，且文案能照着做。"""

    def _libs(self, *names):
        d = Path(tempfile.mkdtemp(prefix="ocrwin-libs-"))
        for n in names:
            (d / n).write_bytes(b"not a real dll")
        self.addCleanup(shutil.rmtree, str(d), True)
        return d

    def test_cpu_device_skips_probe(self):
        ok, msg = engine_mod.gpu_preflight("cpu", loader=lambda *a: (_ for _ in ()).throw(OSError()))
        self.assertTrue(ok)
        self.assertEqual(msg, "")

    def test_is_gpu_device(self):
        for d in ("gpu", "gpu:0", "GPU:1", " gpu:0 "):
            self.assertTrue(engine_mod.is_gpu_device(d), d)
        for d in ("cpu", "", None, "xpu:0"):
            self.assertFalse(engine_mod.is_gpu_device(d), d)

    def test_no_win_dll_means_no_probe(self):
        """非 Windows（CI/开发机）没有 ctypes.WinDLL：如实跳过，不误报缺库。"""
        orig = getattr(__import__("ctypes"), "WinDLL", None)
        try:
            if orig is None:
                self.assertEqual(engine_mod.gpu_preflight("gpu:0", libs_dir=None), (True, ""))
        finally:
            pass

    def test_missing_cudnn_reports_guidance(self):
        libs = self._libs()                       # 空目录：一个 cudnn 都没有

        def loader(name):
            raise OSError("[WinError 126] 找不到指定的模块")

        ok, msg = engine_mod.gpu_preflight("gpu:0", libs_dir=str(libs), loader=loader)
        self.assertFalse(ok)
        self.assertIn("cuDNN", msg)
        self.assertIn("cudnn64_9.dll", msg)
        self.assertIn("README", msg)
        self.assertIn("cuDNN 9.x", msg, "必须给出 cu126 对应的 cuDNN 大版本")
        self.assertIn("--device cpu", msg, "必须给出降级办法")

    def test_found_cudnn_is_ok(self):
        libs = self._libs("cudnn64_9.dll")
        seen = []

        def loader(name):
            seen.append(name)
            return object()

        ok, msg = engine_mod.gpu_preflight("gpu:0", libs_dir=str(libs), loader=loader)
        self.assertTrue(ok, msg)
        self.assertIn("GPU 运行库就绪", msg)
        self.assertIn("cudnn64_9.dll", msg)
        self.assertTrue(str(libs) in seen[0], "先查 paddle\\libs 再查 PATH")

    def test_cudart_in_libs_must_load(self):
        libs = self._libs("cudnn64_9.dll", "cudart64_12.dll")

        def loader(name):
            if str(name).endswith("cudart64_12.dll"):
                raise OSError("cudart 加载失败")
            return object()

        ok, msg = engine_mod.gpu_preflight("gpu:0", libs_dir=str(libs), loader=loader)
        self.assertFalse(ok)
        self.assertIn("cudart64_12.dll", msg)


class TestGpuCliGuard(BaseCase):
    """0033 第 2 条：GPU + workers>1 退 2；缺库退 4；都不许静默继续。"""

    def test_gpu_with_two_workers_exits_2(self):
        make_pdf(self.input / "a.pdf", pages=1)
        with NasStub() as stub:
            rc, out = run_cli(["run", "--input", str(self.input), "--server", stub.base,
                               "--token", TOKEN, "--device", "gpu:0", "--workers", "2"])
        self.assertEqual(rc, cli.EXIT_STARTUP, out)
        self.assertIn("--workers 必须为 1", out)
        self.assertEqual(FakeEngine.calls, 0, "参数不对就不许开跑")

    def test_cpu_with_two_workers_is_fine(self):
        make_pdf(self.input / "a.pdf", pages=1)
        with NasStub() as stub:
            rc, out = run_cli(["run", "--input", str(self.input), "--server", stub.base,
                               "--token", TOKEN, "--device", "cpu", "--workers", "2"])
        self.assertEqual(rc, cli.EXIT_OK, out)

    def test_missing_cudnn_exits_4_with_guide(self):
        make_pdf(self.input / "a.pdf", pages=1)
        orig = engine_mod.gpu_preflight
        engine_mod.gpu_preflight = lambda *a, **k: (
            False, "GPU 运行库缺失：未找到 cudnn64_9.dll（见 README「Windows GPU 前置条件」）")
        try:
            with NasStub() as stub:
                rc, out = run_cli(["run", "--input", str(self.input), "--server", stub.base,
                                   "--token", TOKEN, "--device", "gpu:0", "--workers", "1"])
        finally:
            engine_mod.gpu_preflight = orig
        self.assertEqual(rc, cli.EXIT_DEPENDENCY, out)
        self.assertIn("cudnn64_9.dll", out)
        self.assertIn("README", out)
        self.assertEqual(FakeEngine.calls, 0, "缺库时不许开跑")


class TestFriendlyInitError(unittest.TestCase):
    """0033：原生 `error code is 126` 不许原样糊给用户。"""

    RAW = ("(PreconditionNotMet) The third-party dynamic library (cudnn64_9.dll) that "
           "Paddle depends on is not configured correctly. (error code is 126)")

    def test_dll_error_translated(self):
        msg = engine_mod._friendly_init_error(RuntimeError(self.RAW))
        self.assertIn("cuDNN", msg)
        self.assertIn("cudnn64_9.dll", msg)
        self.assertIn("README", msg)
        self.assertNotIn("error code is 126", msg)
        self.assertNotIn("PreconditionNotMet", msg)

    def test_debug_env_keeps_raw(self):
        os.environ["OCR_WIN_DEBUG"] = "1"
        try:
            msg = engine_mod._friendly_init_error(RuntimeError(self.RAW))
        finally:
            del os.environ["OCR_WIN_DEBUG"]
        self.assertIn("error code is 126", msg)

    def test_unrelated_error_untouched(self):
        msg = engine_mod._friendly_init_error(RuntimeError("OCR requires additional dependencies"))
        self.assertIn("OCR requires additional dependencies", msg)
        self.assertNotIn("cuDNN", msg)


if __name__ == "__main__":
    unittest.main(verbosity=2)
