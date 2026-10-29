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

自检**会真初始化一次 OCR 流水线**（`create_pipeline(pipeline="OCR")`），不是只比版本号：
`paddlex[ocr]` 的 extras（`cv2` / `sklearn` / `tokenizers` …）是懒导入的，只查版本号会漏报、
真跑时才炸（见下面「打包 exe」）。所以：

- 首次自检会下载 PP-OCRv5 模型（数百 MB）到 `%USERPROFILE%\.paddlex`，之后很快；
- 只查依赖、不加载模型：加 `--no-pipeline`；
- 依赖不全时退出码 `4`，并逐条打印缺哪个包（如 `import cv2 失败（发行包 opencv-contrib-python）`）；
- 子命令也容忍写成 `--self-check`（带横杠），不会掉进 `run` 分支去要 `--input`。
- 自检查**两个维度**：`import` 能不能找到模块（对应 `--collect-all`），以及**发行包元数据在不在**
  （`importlib.metadata.version()`，对应 `--copy-metadata`）。缺后者会逐条打印
  `元数据缺失：scikit-learn（发行包已打包但无 dist-info，paddlex 会误判 extras 未安装，需 --copy-metadata）`，
  同样退出码 `4` —— 这条正是「模块全在、paddlex 却说没装依赖」的真凶（见「打包 exe → 两类派生」）。

## 第二步：先 dry-run，再真跑

```powershell
# 只看会处理什么，不渲染不识别不回导
python -m ocrwin run --input "D:\扫描" --server http://192.168.1.111:3003 --token <Key> --dry-run

# 真跑（可随时 Ctrl+C，重跑自动续）
python -m ocrwin run --input "D:\扫描" --server http://192.168.1.111:3003 --token <Key> --workers 2
```

跑完会打印：

```
[ocr-win] 完成 图纸A.pdf pages=8 识别=1043.2 秒（130.40 秒/页）
[ocr-win] 完成 图纸B.pdf pages=1 识别=132.6 秒（132.60 秒/页）
[ocr-win] 进度 2/305（成功 2、失败 0、已缓冲 2 行）0.19 个/秒
[ocr-win] 汇总：扫描 320、扩展名跳过 12、已完成跳过 0、同内容重复 3、不可读 0
[ocr-win] 汇总：识别成功 305、失败 0、回导 accepted=305 rejected=0 批次=7 用时 1854.3 秒
[ocr-win] 汇总：总页数 1840、平均 0.99 秒/页（逐文件累计 1820.4 秒）、59.52 页/分钟
```

两点关于这些数字（都不影响结果正确性）：

- **`已缓冲 N 行` 不是「服务端已收下 N 行」**：回导是按批攒的（`--batch-size` 默认 50），
  凑满一批才 POST 一次，最后一批在退出时提交。所以进度行里看到的只是缓冲区行数，
  真正入库数看汇总行的 `回导 accepted=`（旧文案写「已导入」，永远显示 0，已修正）。
- **每文件那行是「渲染 + 识别」的总耗时**，除以页数得到单页成本。
  `平均 秒/页` 按逐文件耗时累计算（多 worker 时是各文件之和，不是墙钟时间）；
  `页/分钟` 按墙钟时间算。这两个数才是判断「慢在渲染还是慢在识别」的依据——
  提高 `--workers` 只会改善 `页/分钟`，不会改善单页成本。

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
| `--device` | `cpu` | paddlex 设备；NVIDIA 独显装 GPU 版 wheel 后可填 `gpu:0`（见「GPU 加速」） |
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

## GPU 加速（可选，只走源码模式）

推理代码**不用改**：`--device` 一路透传给 `create_pipeline`，换 wheel 就能用显卡。
参考环境（真机实测路径）：**NVIDIA GeForce RTX 4060 Laptop / 8 GB 显存 / 驱动 572.83（CUDA 12.8）/ Python 3.12 / 24 GB 内存**。

```powershell
# 1) 卸掉 CPU 版 paddle（版本号必须与 requirements.txt 的 3.0.0 一致）
.venv\Scripts\python.exe -m pip uninstall -y paddlepaddle

# 2) 装 GPU wheel：--no-deps 必须带（理由见下），cu126 源里有 cp312 轮子，对应 Python 3.12
.venv\Scripts\python.exe -m pip install --no-deps paddlepaddle-gpu==3.0.0 -i https://www.paddlepaddle.org.cn/packages/stable/cu126/

# 3) 自检：GPU 行应变成「paddlepaddle-gpu 3.0.0，GPU 可用：<卡名> ×1，CUDA 已编译，cuDNN xxxx」
.venv\Scripts\python.exe run_ocr_win.py self-check --server https://<你的站点>:4344 --token <Key>

# 4) 真跑时指定设备；8 GB 显存机型 --workers 必须 1
.venv\Scripts\python.exe run_ocr_win.py run --input "E:\待扫目录" --server https://<你的站点>:4344 --token <Key> --device gpu:0 --workers 1
```

- **`--no-deps` 不能省**：Windows 版 `paddlepaddle-gpu` 轮子自带 cuDNN/cuBLAS DLL；按常规 `pip install` 会把 CPU 版 `paddlepaddle` 当依赖再拉回来，两个 dist 同时存在、`import paddle` 命中哪个不确定，典型症状就是「装了 GPU 版，self-check 却说只有 CPU」。
- **不用装 CUDA Toolkit、不用降驱动**：cu126 二进制在 CUDA 12.8 驱动上向下兼容。若 cu126 轮子报 DLL 缺失，退 cu118 源——同一条命令把 URL 里的 `cu126` 换成 `cu118`，版本号仍是 `paddlepaddle-gpu==3.0.0`。
- **只认 NVIDIA**：`paddlepaddle-gpu` 需要 CUDA，AMD / Intel 核显这条路不存在，`--device gpu:0` 会初始化失败。
- **GPU 下 `--workers` 用 1**：每多一个 worker 就多占一份显存，8 GB 显存跑 2 份模型通常直接 OOM；多卡机器按卡数分配。`self-check` 的 GPU 探测只是打印、不影响退出码（GPU 属可选加速，没有也照常跑 CPU）。
- **self-check 的 GPU 行有四种取值，直接看它判断装对没有**：
  - `仅 CPU：paddlepaddle 3.0.0（CPU 版，未编译 CUDA）` → 还是 CPU 版 wheel，第 1、2 步没生效；
  - `paddlepaddle-gpu 3.0.0（GPU 版但未检测到设备）` → wheel 对了，但机器/驱动没把卡暴露给 paddle，先用 `nvidia-smi` 确认驱动与占用；
  - `paddlepaddle-gpu 3.0.0，GPU 可用：<卡名> ×1，CUDA 已编译，cuDNN xxxx` → 可以加 `--device gpu:0`；
  - `仅 CPU（未安装 paddlepaddle：…）` → 连 CPU 版都没装，先按上面「安装」一节装依赖。
- **exe 不打 GPU 版**（有意取舍）：GPU 版 PyInstaller 产物 3 GB 起，且 CUDA/cuDNN 的 DLL 极容易漏收（就是 0031 那类问题的放大版，还更难查）。要 GPU 就用 venv 源码模式跑；`dist\ocr-win\ocr-win.exe` 永远是 CPU 版。真需要 GPU 版 exe 请单独立项。
- 首次用 GPU 仍会下载模型（几百 MB），之后缓存命中。
- **GPU 只加速识别**：`PyMuPDF` 渲染与缩放是纯 CPU 的，页多、墨迹重的 PDF 渲染本身就是大头——先看逐文件行的 `pages=N 识别=Z 秒（W 秒/页）` 再决定值不值得装 GPU。

### Windows GPU 前置条件（必读：缺一样就是 `error code is 126`）

**Windows 版 `paddlepaddle-gpu` 轮子不包含 cuDNN**，也不需要 CUDA Toolkit（驱动够新即可），但 cuDNN 的 DLL 必须自己装：

| wheel 来源 | 匹配 CUDA | 需要 cuDNN |
| --- | --- | --- |
| `cu126`（上面那条命令） | 12.6（驱动 572.83 / CUDA 12.8 实测可用） | **cuDNN 9.x for CUDA 12** |
| `cu118`（cu126 报 DLL 缺失时退这条） | 11.8 | **cuDNN 8.6 for CUDA 11** |

装法（不用装 CUDA Toolkit、不用降驱动）：

1. 从 NVIDIA 开发者站下 cuDNN 对应大版本的 Windows zip（需登录）；
2. 解压后把 `bin` 目录（里面有 `cudnn64_9.dll`）加进 PATH，或直接把 DLL **复制进 `<venv>\Lib\site-packages\paddle\libs\`**（后者最稳，优先级也最高）；
3. cuDNN 9 被拆成多个包（`cudnn_graph` / `cudnn_ops` / `cudnn_engines_*` 等），报错可能逐个出现，缺哪个补哪个。

排查三连（按顺序，把输出贴给别人看就够定位）：

```powershell
# a) 只能有一条 paddle 记录；出现两条说明 CPU 版没卸干净，会「装了 GPU 版却说只有 CPU」
.venv\Scripts\python.exe -m pip list | findstr /i paddle

# b) paddle\libs 里有没有 cuDNN（没有就是没装进 venv/系统 PATH）
dir .venv\Lib\site-packages\paddle\libs\*cudnn*

# c) 我们的预检：开跑前会自己试加载 cuDNN/cudart，缺了直接给上面这套指引
.venv\Scripts\python.exe run_ocr_win.py run --input "E:\待扫目录" --server https://<你的站点>:4344 --token <Key> --device gpu:0 --workers 1
```

- **GPU 下 `--workers` 只能是 1**：传 >1 现在会在启动阶段直接退 2 并给出提示（每 worker 一份模型，8 GB 显存跑 2 份通常 OOM），不会跑到一半才崩。
- **`self-check` 的 GPU 行查的是「有没有卡」，`run` 打的那行 `GPU 运行库就绪：xxx.dll` 查的是「库能不能加载」**——两件事，都要看。
- 缺库时报错会被翻译成中文指引（原始 `error code is 126` 只在设了 `OCR_WIN_DEBUG=1` 时附带），并退 4。

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

### 依赖是「派生」的，不是手抄的

两个脚本自己不再拼 PyInstaller 参数，统一调 `build_exe.py`（**同一份参数**，不会再出现两边不一致），
`build_exe.py` 从 `paddlex` 的包元数据里筛 `extra == "ocr"` **现算**依赖，并给每个包生成 `--collect-all`：

```powershell
.\.venv\Scripts\python.exe build_exe.py --print-only   # 只打印派生结果与完整命令行，不打包
```

为什么必须派生：`--collect-submodules paddlex` 只收 paddlex 自己的 `.py`，而 OCR pipeline 是懒导入、
静态分析追不到它依赖的第三方包 —— 打出来的 exe 一初始化就报
`OCR requires additional dependencies`。上游给 `ocr` extra 加包时**无需改任何脚本**。

### 两类派生，缺一不可：`--collect-all` 与 `--copy-metadata`

`--print-only` 会把两类都打全（排障先看这两行）：

```
[build] --collect-all 派生模块 15 个：einops, ftfy, imagesize, jinja2, lxml, cv2, openpyxl, …
[build] --copy-metadata 派生发行包 16 个：paddlex, einops, ftfy, imagesize, jinja2, lxml, opencv-contrib-python, …
```

| 参数 | 解决什么 | 缺了会怎样 |
| --- | --- | --- |
| `--collect-all <模块名>` | 把**模块本体**（`.py`/`.pyd`/数据文件）打进包 | `ModuleNotFoundError` / `import cv2 失败` |
| `--copy-metadata <发行包名>` | 把 **`.dist-info` 元数据**打进包 | 模块明明都在，paddlex 仍报 `OCR requires additional dependencies` |

为什么第二条不能省：paddlex 判定 extras 是否可用**不看 import**，而是
`importlib.metadata.version(<发行包>)`（`paddlex/utils/deps.py:is_dep_available`）。
PyInstaller 的产物只有 `.py`/`.pyd`，默认不带 `.dist-info`，元数据查不到 → 判定 extras 未安装。
所以每个 extras 发行包**以及 `paddlex` 自己**都要 `--copy-metadata`：

- 用**发行包名**不是模块名（`opencv-contrib-python`，不是 `cv2`）；
- `paddlex` 自己的元数据不能漏：它的 extras 清单（`EXTRAS`/`DEP_SPECS`）是**导入时**用
  `metadata("paddlex")` + `requires("paddlex")` 算死的常量，元数据缺失时连
  `import paddlex.utils.deps` 都过不去（`PackageNotFoundError`）；
- PyInstaller 的 `--copy-metadata` 是把整个 `<包>-<版本>.dist-info/` 目录复制进包，
  `METADATA` 里的 `Requires-Dist` / `Provides-Extra` **原样保留**，不会被裁剪。
- 还有一条**独立**的启动期依赖：`packaging`。`deps.py` 在**导入期**就
  `from packaging.requirements import Requirement` 逐条解析 `Requires-Dist`，打包漏收它时抛的是
  `ImportError`（进程起不来），和「元数据缺失」（能启动、paddlex 报 extras 未安装）是两条不同的
  失败路径。`build_exe.py` 已显式加 `--hidden-import packaging` / `packaging.requirements` /
  `packaging.version`；`self-check` 也会单独打一行
  `packaging: 可 import，Requirement 可解析 Requires-Dist`，不可用时独立退 `4` 并在结论里点明是
  `ImportError` 而不是 extras 判定失败。

打包末尾还有**冒烟断言**：两个脚本都会跑一次 `dist\ocr-win\ocr-win.exe self-check`（默认带流水线初始化），
非 0 即判定打包失败 —— 「打包完成但产物跑不起来」不会再悄悄通过。

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
- PyInstaller 的 `--copy-metadata` 只带 `.dist-info`（含 `top_level.txt`/`RECORD`），**不带模块代码**；反过来 `--collect-all` 只带模块、不带元数据。两者互补，谁也替代不了谁。
- 排障先分清三条独立链路：`--collect-all` 缺 → `ModuleNotFoundError` / `import xxx 失败`；`--copy-metadata` 缺 → 能启动但 `OCR requires additional dependencies`；`packaging` 缺 → `ImportError`。`self-check` 三者各有对应行，不要只看最后那句结论。
- 打包末尾的冒烟自检默认真初始化流水线：**离线机器第一次打包会因模型下载失败而判定失败**。先在联网环境跑一次 `self-check` 预热 `%USERPROFILE%\.paddlex` 缓存即可；只想跳过着一步验证依赖时手动执行 `dist\ocr-win\ocr-win.exe self-check --no-pipeline`。
- **待识别 0 个时不初始化引擎**：续跑全部命中（`已完成跳过 N`）时直接收尾退出 0，不建 pipeline、不下载模型——否则 GPU 版 paddle 缺 cuDNN 会在「一个文件都不用处理」的情况下崩在启动阶段。
- **GPU 只支持源码模式**：`dist\ocr-win\ocr-win.exe` 是 CPU 版，没有任何 GPU 打包路径（CUDA DLL 收集不可靠、体积 3 GB 起）。需要显卡加速就在 venv 里加 `--device gpu:0`。GPU 存不存在都不影响退出码。
- 本工具回导的 `engine` 写 `paddle5`，NAS 侧车写 `paddleocr-sidecar`——这是**有意差异**，配 `source`（`win` / `nas`）可一眼区分某条结果是 PC 导的还是 NAS 扫的。副作用：只按 `engine='paddleocr-sidecar'` 过滤的统计会漏掉 PC 导入的结果，统计口径请改用 `source` 或不过滤 `engine`（`params_key` 本身也以 `paddle5|` 开头）。
