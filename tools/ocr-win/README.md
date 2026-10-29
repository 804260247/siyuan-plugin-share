# ocr-win —— Windows 端离线 OCR（把识别搬到本机，只把结果回导 NAS）

NAS 端 OCR 是 CPU 串行、单请求内 4 线程推理，实测约 **16 秒/页**。几百个 PDF 在 5825U 上
要跑几十小时。这个工具把「渲染 + 识别」搬到你的电脑上跑，NAS 只收结果：

```
NAS 先跑一轮「只建索引、不做 OCR」  →  PC 端本地 OCR  →  回导结果  →  NAS 下一轮重建直接复用
```

回导只写 `netdisk_ocr_results`（内容寻址表），NAS 下一轮遍历时按 `(content_hash, params_key)`
命中就直接取文本，**不再下载、不再渲染、不再调侧车**。

## 铁律（先看这段）

- **绝不直接读写 NAS 上的 `app.db`**。SQLite WAL 经 SMB 共享会锁错甚至损坏库。
  唯一写入口是 `POST /admin/netdisk/ocr/import`，本工具不做任何文件系统挂载写入。
- **渲染参数与 `params_key` 全部来自服务端** `GET /admin/netdisk/ocr/params`。
  拉不到就报错退出，不拿本地默认值硬跑——否则整批结果的 `params_key` 对不上，全部作废。
- 只回传 `content_hash` / `size` / `pages` / `text` / `engine`；
  `chars` / `quality` / `status` / `attempts` 一律由服务端重算。

## 环境要求

- Windows 10/11，**Python 3.11**（3.10/3.12 一般也能跑，打包请用 3.11）
- 磁盘：模型与依赖约 3–4 GB
- 内存：单 worker 约 1 GB；`--workers 2` 约 2 GB

## 安装

```powershell
cd tools\ocr-win
python -m venv .venv
.\.venv\Scripts\Activate.ps1
python -m pip install -U pip
pip install -r requirements.txt
```

`paddlex[ocr]==3.0.3` + `paddlepaddle==3.0.0` 必须与 NAS 侧车**同版本**，否则模型/结果口径不一致，
字符数没法对照。首次 `run` 会自动下载 PP-OCRv5 模型到 `%USERPROFILE%\.paddlex`。

## 取 API Key

后台用管理员账号登录 → 打开 `/dashboard` → 点「生成 API Key」。**Key 只显示一次**，复制保存。
（生成即覆盖旧 Key，旧 Key 立刻失效。）

## 第一步：自检

```powershell
python -m ocrwin self-check --server http://192.168.1.111:3003 --token <你的 Key>
```

会打印 Python / PyMuPDF / Pillow / paddlex / paddlepaddle / onnxruntime 版本、模型目录，
并向 `--params-url` 实拉一次参数。看到这一行就说明通路正常：

```
[ocr-win] 自检结论：参数接口正常，可以开始扫描
```

## 第二步：先 dry-run，再真跑

```powershell
# 只看会处理什么，不渲染不识别不回导
python -m ocrwin run --input "D:\扫描" --server http://192.168.1.111:3003 --token <Key> --dry-run

# 真跑（可随时 Ctrl+C，重跑自动续）
python -m ocrwin run --input "D:\扫描" --server http://192.168.1.111:3003 --token <Key> --workers 2
```

跑完会打印：

```
[ocr-win] 汇总：扫描 320、扩展名跳过 12、已完成跳过 0、同内容重复 3、不可读 0
[ocr-win] 汇总：识别成功 305、失败 0、回导 accepted=305 rejected=0 批次=7 用时 1854.3 秒
```

## 参数

| 参数 | 默认 | 说明 |
|---|---|---|
| `--input` | 必填 | 本地扫描目录（递归） |
| `--server` | 必填 | NAS 站点根地址，如 `http://192.168.1.111:3003` |
| `--token` | 空 | 后台 API Key（走 `X-Api-Key`，不依赖 cookie） |
| `--params-url` | `<server>/admin/netdisk/ocr/params` | 覆盖参数接口地址（走反代时用） |
| `--workers` | 1 | 并发 worker 数；**每个 worker 一份模型**，16 GB 机器建议 ≤2 |
| `--resume` / `--no-resume` | 续跑开启 | 本地 `done.jsonl` 已有的 `(hash, params_key)` 跳过 |
| `--dry-run` | 关 | 只扫描 + 算哈希 + 校验参数 |
| `--out-dir` | `<input>\.ocr-win-state` | 状态目录（断点续跑与失败清单都在这里） |
| `--batch-size` | 50 | 单批行数上限（服务端硬上限 50） |
| `--batch-bytes` | 4 MB − 64 KB | 单批请求体上限（服务端硬上限 4 MB） |
| `--device` | `cpu` | paddlex 设备（有独显可试 `gpu:0`） |
| `--resample` | `bilinear` | 缩放到 `max_side` 的插值算法；默认与 NAS 侧 GD 同算法，便于字符数对照 |

支持扩展名：`.pdf .png .jpg .jpeg .bmp .tif .tiff .webp`（多帧 TIFF 按帧逐页处理）；其他一律跳过并计数。
不支持 office 文档（任务书明确不做 PC 侧 office OCR）。

## 退出码

| 码 | 含义 |
|---|---|
| 0 | 全部成功（或 dry-run 完成） |
| 1 | 跑完但有文件失败/被拒收，看 `failed.jsonl` |
| 2 | 启动失败：参数拉不到、鉴权失败、输入目录不可用 |
| 3 | `params_key` 与服务端不一致（服务端回带 `expected_params_key`）——通常说明 NAS 侧刚改过 DPI/档位，直接重跑 |
| 4 | 本机依赖缺失（PyMuPDF / Pillow / paddlex） |
| 130 | Ctrl+C 中断（缓冲中的结果已尽量提交） |

## 状态文件（`--out-dir` 下）

| 文件 | 内容 |
|---|---|
| `done.jsonl` | 每条 `{content_hash, params_key, size, pages, chars, source}` —— 续跑靠它，`params_key` 参与键，NAS 改参数后不会误跳 |
| `results.jsonl` | 已回导成功的行（`text` 全量），便于事后核对/重放 |
| `failed.jsonl` | 每条 `{content_hash, reason, attempts, path}` —— 失败原因含 `empty_text` / `text_too_large` / `size_mismatch` / `stale` / `network_error` / `http_<code>` / 渲染或引擎异常文本 |

删掉整个状态目录 = 下次全量重跑。

## 打包 exe

```powershell
.\build.ps1            # 默认 --onedir（启动快、更稳）
.\build.ps1 -OneFile   # 单文件，启动慢一点
```

零基础推荐直接**双击 `build.cmd`**（cmd 批处理，等价于 `.\build.ps1`，不受 PowerShell 执行策略/编码/行尾影响）。

> 这两个脚本必须以 **CRLF 行尾**入库（`.gitattributes` 已用 `-text` 钉住，任何平台 checkout 出来都是 CRLF），
> `build.ps1` 另带 **UTF-8 BOM**。原因：Windows PowerShell 5.1 解析纯 LF 文件时，行尾反引号续行不可靠
> （会报「一元运算符 -- 后面缺少表达式」）；无 BOM 时按系统代码页（中文 Windows 为 GBK）解码，中文注释全乱。
> 另注意：原生 exe 的非 0 退出码不会触发 `$ErrorActionPreference`，脚本内每处调用都显式检查 `$LASTEXITCODE`，失败即中断。

产物在 `dist\ocr-win\`（或 `dist\ocr-win.exe`）。打包会带上 paddlex 与 paddle 的模型加载代码，
体积较大（约 1.5–2 GB），这是正常的。

## 与 NAS 直扫的一致性怎么验（任务书 4.4 用例 6）

口径是「**同一份文件，PC 导入 vs NAS 直扫，字符数差异 < 5%**，关键字段（编号/金额/日期）抽样人工无实质错误」，
**不要求逐字节相同**——PyMuPDF 与 pdftoppm 渲染不同源，逐字节相同不可能。

做法：
1. 挑 10 个 NAS 上已经 `done` 的 PDF（它们的文本已在 `netdisk_index.text`），把这 10 个文件拷到 PC 上。
2. **回导前**先在 NAS 上把基线存下来（只读，不写库）：

```bash
docker exec siyuan-share-web-test php -r '$p=new PDO("sqlite:/var/www/html/storage/app.db");
foreach($p->query("SELECT uri,LENGTH(text) len FROM netdisk_index ORDER BY uri") as $r)
  echo $r["uri"],"\t",$r["len"],"\n";' > /tmp/nas-before.tsv
```

3. PC 上跑本工具回导，再在 NAS 重建索引一圈（这批文件走内容寻址命中，不再调侧车）。
4. 用同一条命令导出 `/tmp/nas-after.tsv`，逐文件比 `len`：

```bash
paste /tmp/nas-before.tsv /tmp/nas-after.tsv | awk -F'\t' '
  $1==$3 && $2>0 { d=($4-$2)/$2; if (d<0) d=-d; printf "%s  %d -> %d  %.2f%%\n", $1, $2, $4, d*100 }'
```

差异都 < 5% 且抽样字段（编号 / 金额 / 日期）无实质错误即通过。
**如果重建后 `len` 完全没变**，说明命中的是 0024 的结果表旧行而不是本轮导入的新行——
先确认 NAS 的 `params_key` 与 PC 拉到的 `params_key` 是同一个。

## 已知边界

- `--workers > 1` 时每个线程各自加载一份模型（paddlex pipeline 非线程安全），内存随之翻倍。
- 断点续跑依赖本地 `done.jsonl`；**NAS 侧改了 DPI/档位（`params_key` 变了）会全部重跑**，这是正确行为。
- 工具不比对云端 `updated_at`（PC 无法得知），只回传 `size`；`size` 与已有记录不符时由**服务端**判 `size_mismatch`/`stale` 拒写。
- 单行结果超过 4 MB 无法回导（服务端单批上限），本地记 `text_too_large` 并跳过；这类文件建议回 NAS 端处理。
- `.docx/.xlsx/.pptx` 不支持；加密 PDF 会进 `failed.jsonl`。
- 引擎结果字段形状在 paddlex 3.x 各小版本间有差异，`extract_rec_texts()` 做容错提取（`rec_texts` 可能出现在 `res` / `prunedResult` / `.json` 下）。
- 本工具回导的 `engine` 写 `paddle5`，NAS 侧车写 `paddleocr-sidecar`——这是**有意差异**，配 `source`（`win` / `nas`）可一眼区分某条结果是 PC 导的还是 NAS 扫的。副作用：只按 `engine='paddleocr-sidecar'` 过滤的统计会漏掉 PC 导入的结果，统计口径请改用 `source` 或不过滤 `engine`（`params_key` 本身也以 `paddle5|` 开头）。
