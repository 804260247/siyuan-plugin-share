# PC 端 OCR 重建操作指引（ocr-win）

适用场景：在 **PC（Windows）** 上用 `ocr-win` 对本地 PDF / 图片做 OCR，再把识别结果**回传（回导）到思源分享服务端**，让服务端 / NAS 复用这批结果，避免在 NAS 侧重新下载和识别。

这套流程的价值在于「**PC 快跑（可用 GPU）+ 服务端复用**」：PC 把重活干完，NAS 侧按缓存命中直接跳过下载与识别。

---

## 0. 名词与前提

| 名词 | 含义 |
|---|---|
| 服务端 | 部署了思源分享 Web 的站点，例如 `https://your-share.example.com:4344` |
| API Key / Token | 服务端「个人中心 → API Key」生成的一串令牌，通过环境变量 `OCR_TOKEN` 传入 |
| 分享 slug（`--share`） | 该网盘文件所属**分享链接**的短码。服务端据此把 OCR 参数解析到「该分享绑定的网盘连接」，取参口径必须和后续重建一致 |
| 网盘连接 config_id | 服务端后台里的一条 Cloudreve 等网盘连接配置；带 `--share` 时参数取自该分享绑定的连接 |

**前提：**
- 已安装 `ocr-win`（Python 源码目录，含 `run_ocr_win.py` 与 `ocrwin/`），并有可用的虚拟环境。
- 服务端已启用 OCR 侧车（`NETDISK_OCR_SIDECAR=1`、`NETDISK_OCR_SIDECAR_URL=http://ocr:8080`），否则回导后服务端重建仍是 `engine=tesseract`，结果不可用于高精度比对。
- 你有一个有效的 API Key，且知道要处理的**分享链接 slug**。

---

## 1. 关键约束（务必先读，违反会直接踩坑）

1. **只能用源码模式跑，别双击旧的打包 exe。**
   `dist\ocr-win\ocr-win.exe` 是早期 PyInstaller 打包产物，`.py` 已被烤进 exe，命令行参数（尤其 `--share`）可能与源码版不一致，覆盖 `.py` 也不会改变 exe 行为。
   统一用：`.venv\Scripts\python.exe run_ocr_win.py ...`（CPU 环境）或 `.venv-gpu\Scripts\python.exe ...`（GPU 环境）。

2. **GPU 下 `--workers` 必须是 1。** 多进程并发显存会溢出。

3. **设 `OMP_NUM_THREADS=1`。** 避免与推理框架的线程调度互相抢 CPU。

4. **`--token` 不能为空。** 新开一个 PowerShell 窗口，`$env:OCR_TOKEN` 会丢，导致 `--token: expected one argument`。每次运行前先设。

5. **目标目录若已有旧的 `.ocr-win-state*/done.jsonl`，正式重跑必须加 `--no-resume`。**
   默认「续跑」会把 `done.jsonl` 里已记录的文件**整体跳过**，看起来跑完了其实一个没扫。

6. **做比对 / 重建前先另存 `done.jsonl`。**
   服务端对 `(content_hash, params_key)` 是**无条件覆盖**：如果随后在服务端勾了「强制重跑 OCR」（`force_ocr=1`），NAS 侧车会重扫并覆盖 PC 回导的行（`source` 从 `win` 变成 `nas`）。先备份才能还原对比。

---

## 2. 运行命令模板

```powershell
# 0) 新开窗口必须先设 token（空值会让 --token 报错）
$env:OCR_TOKEN = "<你的 API Key>"
$env:OMP_NUM_THREADS = "1"

# 1) 指向本地要 OCR 的目录（含 PDF / 位图）
$B = 'D:\你的网盘同步目录\某资料夹'

cd C:\你的路径\ocr-win

# 2) GPU 环境 + 分享 slug + 回导服务端
.venv-gpu\Scripts\python.exe run_ocr_win.py run `
  --input "$B" `
  --server https://your-share.example.com:4344 `
  --token $env:OCR_TOKEN `
  --share <分享slug> `
  --device gpu:0 --workers 1 `
  --out-dir "$B\.ocr-win-state-b10" --no-resume

# 3) 跑完第一件事：另存 done.jsonl（防止后续服务端强制重跑覆盖）
Copy-Item "$B\.ocr-win-state-b10\done.jsonl" pc-batch.jsonl -Force
```

CPU 环境把 `.venv-gpu\Scripts\python.exe` 换成 `.venv\Scripts\python.exe`，`--device` 用 `cpu`（或不传），`--workers` 可 >1。

---

## 3. 参数口径自检（`--share` 是否真的生效）

`run` 一启动（含 `--dry-run`）会拉一次参数接口并打印一行口径日志：

- **带 `--share <slug>`** → `参数口径：scope=share config_id=<N>`
  说明服务端按该分享绑定的连接取参，回导结果能被重建复用。
- **不带 `--share`** → `参数口径：scope=default config_id=0`，紧跟一行提示：
  > 未带 `--share`，按服务端硬编码默认值取参；若该分享绑定的连接 DPI≠默认值，回导结果不会被重建复用。

**只有看到 `scope=share`，才说明取参口径与后续服务端重建一致。**

`--help` 里应能看到：
```
--share SHARE   分享 slug：服务端据此把 OCR 参数解析到该分享绑定的网盘连接。
```
看不到这一行，说明你跑的是旧 exe 或旧版 `.py`，回第 1 节第 1 条。

---

## 4. 回导结果解读与验收

跑完看汇总：

| 字段 | 期望 |
|---|---|
| `accepted` | 成功回导并落库的文件数（=本批可 hash 的 PDF/位图数） |
| `rejected` | 被拒数（正常应为 0；出现 `params_mismatch` 多半是没带 `--share`，见第 3 节） |
| 批次 | 回导批次号，服务端结果表可按批次追溯 |

**PC 扫描数 vs 服务端文件数可能不一致，属正常：**
- 扫描器只认 `.pdf` 和位图，`.jsonl` / 文件夹会被自动跳过（汇总里的「扩展名跳过 N」）。
- 服务端侧可能存在**只在网盘分享树里、本地 PC 未同步**的文件，这些文件 PC 扫不到。
- 因此比对以 **`content_hash` 交集**为准，不看文件名 / 路径 / 总数。

---

## 5. PC ↔ NAS 结果复用与比对纪律

1. **比对键必须是 `content_hash`。** 文件名和路径可以不同，但字节必须一致。
2. **决定复用的是 `params_key`，不是 `engine`。** 只要 `(content_hash, params_key)` 命中，服务端就跳过下载和识别，直接复用 PC 回导的行。
3. **服务端 `chars` 必须是 `netdisk_normalize_ocr_text()` 归一后的值** 再参与差异率计算。
4. **`force_ocr=1` 会覆盖同 `params_key` 的 PC 回导行**（服务端 upsert 无条件覆盖）——这是**已知边界，不是 bug**。做差异比对前务必先备份 `done.jsonl`（第 1 节第 6 条 / 第 2 节第 3 步）。
5. 平时（不勾强制重跑）走的就是「PC 快跑 + NAS 复用」的正路：NAS 按缓存命中，不下载、不识别。

---

## 6. 常见报错对照

| 报错 / 现象 | 含义与处置 |
|---|---|
| `unrecognized arguments: --share <slug>` | 还在跑旧 exe，或覆盖没落到实际运行目录 → 改源码模式（第 1 节第 1 条） |
| `argument --token: expected one argument` | `$env:OCR_TOKEN` 为空（新窗口丢变量）→ 重设后再跑 |
| 回导整批 `params_mismatch` | 没带 `--share`，而该连接 OCR 参数（DPI 等）≠默认值 → 必须带 `--share` |
| 跑完 files=0 / 全被跳过 | 目录里已有旧 `done.jsonl`，走了续跑 → 加 `--no-resume` |
| 服务端重建 `engine=tesseract`，无法用于比对 | 侧车环境未生效 → 检查 `NETDISK_OCR_SIDECAR=1`、`NETDISK_OCR_SIDECAR_URL`，改 env 后需 `docker compose up -d` 重建容器 |

---

## 7. 服务端侧验证（可选）

回导后在服务端后台触发一次网盘重建（**不要**勾强制重跑，否则覆盖 PC 行），确认：
- 命中缓存的文件数 = PC `accepted` 交集数；
- 结果表里这些行 `source` 仍为 `win`、`engine` 为 `paddleocr-sidecar`，即「PC 快跑 + NAS 复用」链路成立。

如需做差异率比对：比对前先 `Copy-Item done.jsonl pc-batch.jsonl`，再另建 job 勾 `force_ocr=1` 让 NAS 侧重扫，取 `content_hash` 交集比较服务端与 PC 的归一字符数差异。
