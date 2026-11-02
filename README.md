# 🔗 思源分享（Siyuan Share）

**思源分享（Siyuan Share）** 是一款 **免费、开源** 的思源笔记插件，用于将 **笔记本、单篇文档、文档及子文档生成可访问的分享链接**。  
它支持 **访问密码、到期时间、访客数量限制**，并提供 **访问统计概览**，适合知识分享、协作与临时公开展示等场景。

🌍 文档语言：  
[中文 README](https://github.com/804260247/siyuan-plugin-share/blob/main/README.md) ｜ [English README](https://github.com/804260247/siyuan-plugin-share/blob/main/README_EN.md)

> ### 🔀 本仓库说明（Fork / 增强分支）
>
> 本仓库 **[804260247/siyuan-plugin-share](https://github.com/804260247/siyuan-plugin-share)** 是原项目 **[b8l8u8e8/siyuan-plugin-share](https://github.com/b8l8u8e8/siyuan-plugin-share)**（作者「取舍」）的 **增强分支（fork）**，由 [804260247](https://github.com/804260247) 维护。
>
> - 本仓库承接后续维护，目前新增两项特性，**已合并进 `main`**（原以上游 PR 形式提交）：
>   - **分享页右侧目录 / 最近更新栏** —— 分支 `feat/share-column-layout`，上游 [PR #72](https://github.com/b8l8u8e8/siyuan-plugin-share/pull/72)
>   - **笔记级公告提示** —— 分支 `feat/share-announcement`，上游 [PR #73](https://github.com/b8l8u8e8/siyuan-plugin-share/pull/73)
> - 本仓库的更新记录见 **[CHANGELOG.md](https://github.com/804260247/siyuan-plugin-share/blob/main/CHANGELOG.md)**。
> - 功能说明、部署教程、服务端搭建等内容沿用原项目文档；版权、赞赏与 Star 均归原作者所有。

📝 使用文档：

[保姆级教程使用文档](https://share.b0x.top/s/8c34c022)

---

## 🚀 插件优势

🌐 **开箱即用，部署自由**    

本体**完全开源免费**。支持 `share.b0x.top` 快速开始，亦可私有化部署（支持 **Docker / NAS / 宝塔面板 / 其他面板**）。 

🎯 **灵活分享，精准受控**    

支持**单篇、子文档或全笔记本**分享。可**一键排除**特定文档，确保私密内容不被公开。 

⚡ **高效同步，内置统计**    

采用**增量更新**算法（省流、极速）。自带分享访问统计功能，让你对分享内容的**访客人数、地理位置等数据**了如指掌。

🔄 **自动更新，省心省力**

开启后可在后台**自动检测文档变更并更新分享页**，无需反复手动点击更新；并提供**运行状态、队列与历史记录**，让分享内容始终保持最新。

---

## 📦 安装方式

本插件**暂未上架思源官方集市**，需手动安装：

1. 在 GitHub 仓库页面点击 `Code → Download ZIP` 下载压缩包
2. 解压后得到 `siyuan-plugin-share-main` 文件夹
3. 将该文件夹**重命名为 `siyuan-plugin-share`**（必须去掉 `-main` 后缀）
4. 将重命名后的文件夹放入思源工作空间的 `data/plugins/` 目录
5. 重启思源，在 `设置 → 插件` 中启用本插件

> 💡 提示：直接下载的 ZIP 包含一级目录。若通过集市「本地安装 / 导入」方式安装，需先解压并重命名文件夹，再重新压缩为 ZIP 后导入。

---

## ✨ 使用方法

### 📌 操作入口
在 **文档树** 中，对 **笔记本或文档** 右键点击 → 进入 **插件菜单**，可使用以下功能：

- 🆕 创建分享 / 管理分享  
- 🔄 更新分享  
- 📋 复制分享链接  
- 🗑️ 删除分享  

### 🗂️ 分享管理
进入 **插件设置页面**，即可查看 **所有已创建的分享列表**，并进行统一管理与维护。

---

## 🔐 访问设置说明

- **访问密码**  
  为分享设置访问密码，访客需输入正确密码才能查看内容。

- **到期时间**  
  超过到期时间后，分享将被标记为 **已过期**，无法访问。  
  > ⚠️ 云端数据不会自动删除，可手动清理以节省空间。

- **访客上限**  
  设置最大访客数量。  
  
  - 超出上限后，新访客将无法访问  
  - 已访问过的访客仍可继续访问  
  - 同一浏览器多次访问仅计为 **1 位访客**
  
- **链接后缀**

  自定义分享链接后缀。  

---

## ⚠️ 注意事项

- **内容更新机制**  
  
  - 若 **修改了文档或笔记本内容**，请使用 **「更新分享」**  
    → 可保持原分享链接不变，同时同步最新内容  
  - 若仅 **修改访问设置（密码 / 到期时间 / 访客上限 / 链接后缀）**，请使用 **「更新访问设置」**  
    → 无需重新上传内容，节省时间与流量
  
- **链接变动规则**  
  若执行 **「删除分享」后再「创建分享」**，  
  👉 系统将生成 **新的分享链接**，旧链接将立即失效。
  
  👉 如有需要，也可通过 **修改访问设置中的链接后缀**，将链接恢复为之前的形式。

---

## ⚙️ 插件配置说明

本插件依赖服务端网站：  
🌐 **[share.b0x.top](https://share.b0x.top)**（用于数据存储与访问）

### 1️⃣ 配置步骤

1. 前往服务端网站注册账号  
2. 在网站中生成 **API Key**  
3. 将 API Key 填入插件设置中  
4. 配置完成后，即可开始分享  
   > 分享操作本质上是将对应文档 / 笔记本上传至服务端

### 2️⃣ 服务端说明

- **公共服务说明**  
  当前的 `share.b0x.top` 为作者提供的 **公共服务端**，  
  主要用于 **功能体验与短期使用**。

- **数据清理策略**  
  受限于服务器带宽与存储成本，  
  可能会 **清理体积较大的文档或笔记本**。

- **私有化部署建议（强烈推荐）**  
  若你有 **长期分享的需求**，  
  请参考 👉 **[服务端网站搭建教程](https://ccnwc9xa9692.feishu.cn/wiki/MQCtwMtQaifPuak4zl3cIMCqnLx)**  
  自行部署私有服务端。

---

## 🌐 自建 Cloudreve + Collabora 部署排障（同局域网场景）

> 本节只记录**实测踩过的坑与判定方法**，不是 Cloudreve / Collabora 的安装教程。
> 文中一律使用占位符，请替换为你自己的实际值：`<反代主机IP>` 指反向代理（Nginx Proxy Manager 等）所在的内网主机 IP，`<内网主机IP>` 指 Cloudreve / Collabora 各自所在部署机的内网 IP（与反代主机不一定是同一台），`<Cloudreve域名>` / `<分享域名>` / `<Collabora域名>` 指各自的公网域名，`:4344` / `:5212` / `:9980` 仅为示例端口。

### 一、思源分享控制台配置

1. **Cloudreve 连接的「站点地址」推荐填外网 `https://<Cloudreve域名>:4344`**，前提是插件容器**能解析并回流到该域名**（见第二类第 1 条）。改填内网地址（如 `http://<内网主机IP>:5212`）只有当「容器能直连该地址」**且**「Cloudreve 返回的直链主机同样容器可达」时才成立：Cloudreve 4.19.1 的直链按面板「主站点 URL」生成，未必等于这一项配置（见第二类第 4 条），所以内网地址不是稳妥选项；最稳的是容器可达的公网 https + `extra_hosts`。
2. **Office / Collabora 应用地址必须使用 `https`**（Collabora 官方要求 `ssl.enable=true`）。填内网 `http://<内网主机IP>:9980` 会直接报「校验失败：无法访问 …/hosting/discovery」。内网 http 单轨不可用，对外分享与预览应统一走外网 https 单轨。
3. **报错文案与真实原因往往不一一对应**，先按下面这张映射表判断，不要急着查端口：

   | 控制台 / 页面表现 | 更常见的真实原因 |
   | --- | --- |
   | 「连接被拒绝（端口未监听或被防火墙拦截）」 | 容器到该域名不可达（多为回流失败），通常不是端口问题 |
   | 分享页 `GET /s/<分享码>/netdisk/list` 返回 502 | web 容器取不到 Cloudreve API，同上 |
   | 「未授权的WOPI主机」 | Collabora 容器回连不到 WOPI 主机，或 `aliasgroup1` 不匹配（见第二类第 2 条） |
   | `Refused to frame … frame-ancestors …` | `aliasgroup1` 未包含实际访问分享页的 Origin（见第二类第 3 条） |

### 二、三件套部署（Cloudreve / 思源分享 / Collabora 同局域网）

1. **内网回流（hairpin NAT）是本场景的头号坑。**
   - 现象：容器把公网域名解析成路由器的公网 IP，出站报文绕到公网口后回不到内网反代。表现为分享页取列表/取直链 502、Collabora discovery 直接被拒、WebSocket 握手中断。
   - 解法：给**插件容器**和 **Collabora 容器**都加 `extra_hosts`，把这些公网域名钉回内网反代所在主机：

     ```yaml
     extra_hosts:
       - "<Cloudreve域名>:<反代主机IP>"
       - "<分享域名>:<反代主机IP>"
       - "<Collabora域名>:<反代主机IP>"
     ```

   - 浏览器侧仍按公网域名访问，两者不冲突。
   - ⚠️ 该配置属**部署层**，不进入仓库；插件不会对直链做落点改写（那会掩盖配置问题）。
2. **Collabora 收到 WOPISrc 后会从容器内主动回连该主机**做 CheckFileInfo。缺 `extra_hosts` 时报「未授权的WOPI主机」——这个文案同时掩盖了「连不上」和「alias 不匹配」两种原因，别被字面误导。
3. **`aliasgroup1` 必须包含实际访问分享页的 Origin（含端口）**，否则 iframe 被 CSP `frame-ancestors` 拒嵌，浏览器控制台报 `Refused to frame …`。
4. **Cloudreve Pro 4.19.1 的直链主机由面板「主站点 URL」与直链缓存决定**，可能与插件里配置的站点地址不一致（实测：配置内网 `:5212`，返回外网 `:4344` 直链）。这属预期行为，不是插件 bug——插件只做诊断透传，不替换直链主机。
5. **OCR sidecar 首次启动要 pip 装包并下载/转换模型（约 1–3 GB，10–30 分钟）**，日志前缀为 `[ocr-init]`，此时不是卡死。若日志出现 `paddlex` 的 `EOFError: EOF when reading a line`，说明初始化脚本在无 TTY 环境下触发了交互提示，请升级到已修复版本。
6. **OCR sidecar 装系统库时若 `apt-get update` 超时**（日志大量 `Err: Unable to connect to deb.debian.org`，末行 `[ocr-init] FATAL: apt-get update failed`），说明容器到 Debian 官方源 80 端口不通（pip 走 443 通常仍可达）。给 `ocr` 服务设置环境变量 `OCR_APT_MIRROR` 指向一个容器可达的镜像主机名（如 `mirrors.aliyun.com`；连通性可用 `docker run --rm python:3.11-slim bash -c 'timeout 5 bash -c "</dev/tcp/<镜像主机>/80" && echo OK'` 探测，5 秒内打出 OK 即可达），脚本会自动把 `deb.debian.org` / `security.debian.org` 替换为该镜像，且 `apt-get update` 单独判错快速失败，不再刷两百行超时日志。
7. **OCR sidecar 装 pip 依赖时若下载卡住或 `ReadTimeoutError`**（日志见 `pip._vendor.urllib3.exceptions.ReadTimeoutError`、下载速率只有几 kB/s，如 `paddlepaddle` 192 MB 下到一半超时），说明容器到 `files.pythonhosted.org` 链路慢，不是脚本 bug。给 `ocr` 服务设置环境变量 `OCR_PIP_INDEX=https://mirrors.aliyun.com/pypi/simple/`（注意：与 `OCR_APT_MIRROR` 写法不同，这个要带 `https://` 前缀、以 `/simple/` 结尾），超时与重试可用 `OCR_PIP_TIMEOUT`（默认 120 秒）/`OCR_PIP_RETRIES`（默认 5 次）调整；已下载的包会缓存进模型卷（`$PADDLEX_HOME/pip-cache`），重建容器不再重下。
8. **`php-site/config.php` 必须外部挂载**，镜像不会生成该文件；不挂载站点起不来。
9. **不想手动估档位：`OCR_PROFILE=auto`**。OCR sidecar 启动脚本会读容器内存上限（cgroup v2 `memory.max` / v1 `limit_in_bytes`；未设 `mem_limit` 或值为 `max`/哨兵值时回退 `/proc/meminfo` 的 MemTotal）与核数（`nproc`，不可用回退 `getconf`，仍不可用按 2），**可用内存 ≥ 4 GiB 且核数 ≥ 6 自动选 high（4 线程），其余选 low（2 线程 + 关 ONNX 内存竞技场）**；内存依据完全不可知时保守选 low。判定依据在启动日志一行打全：`[ocr-init] profile=auto resolved=high (mem_limit=8192MB mem_total=16384MB nproc=8)`。显式 `OCR_THREADS` 仍最高优先；显式 `low`/`high` 不经过 auto 判定；未知档位值打 WARN 后按 low 处理，不会挡住容器启动。

### 三、NPM / nginx 反代

1. **反代 Collabora 的 scheme 必须选 `https`**（Collabora 侧为自签证书），并配 `proxy_ssl_verify off;`。用 `http` 会遇到 `Mixed Content` 与 `An insecure WebSocket connection may not be initiated from a page loaded over HTTPS`。
2. **不要盲目给 Collabora 站点加 `location /cool/` 的 WebSocket 补丁。** 本项目实测：加上后会连带打断同域其它应用（如 Cloudreve）的外网预览，标准补法在本环境证伪。已确认可用的基线是「HTTP/2 开启 + Custom configuration 留空 + Custom Nginx Configuration 仅 `proxy_ssl_verify off;`」。
3. **测 WebSocket 透传要用 `curl --http1.1`**：HTTP/2 不承载裸 `Upgrade` 握手，默认 curl 拿不到响应不代表反代有问题。

   ```bash
   curl -sk --http1.1 -o /dev/null -D - "https://<Collabora域名>:4344/cool/ws" \
     -H "Connection: Upgrade" -H "Upgrade: websocket" \
     -H "Sec-WebSocket-Version: 13" \
     -H "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ=="
   ```

   返回任意状态行（101 最佳，400/404 也能说明 Upgrade 已透传）即表示握手到达了 Collabora；一个字都没返回才说明代理吃掉了握手。
4. **排查阶段先关掉会改写响应的选项**（Cacheable Assets、压缩等），避免干扰定位。

### 四、管理端「默认根目录」的显示与存储口径

1. **界面上看到的是可读路径，库里存的是编码路径。** Cloudreve 的 `path` 原生就是 percent-encoded（中文目录在 API 里就是 `%E4%B8%AD%E6%96%87` 这种形式），`cloudreve_configs.default_root` 必须原样存这份编码态——`/file?uri=` 会把它整串再 query 编码一次发出去，解码回来正好是 Cloudreve 的规范串。所以管理端表格「默认根目录」列与表单输入框改走 `netdisk_root_display()`，按 `/` 分段逐段解码成人可读路径（不做整体解码，避免段内的 `%2F` 被打散成层级）；数据库里的值一个字节都不改。
2. **手工粘贴中文、空格、裸 `&` / `#` / `?` 现在也能存对。** 保存时 `netdisk_root_encode()` 逐段判断——段内出现不可打印字符（含空格与中文字节）或裸 `&` / `#` / `?` 时，先 `rawurldecode()` 再 `rawurlencode()`（**先解码再编码**，否则段内已有的 `%E6` 会被二次编码成 `%25E6`，既不幂等、又会让 Cloudreve 列错目录）；其余段（含已编码段）原样拼接，因此对已编码串幂等——反复保存不会让 `%26` 漂移成 `&`，也不会出现双重编码；scheme 段（`my` / `xxx@share`）整体跳过，不会被编码成 `xah5%40share`。也就是说在输入框里直接敲 `cloudreve://my/我的库/机械制图` 保存，入库即为编码串；不过仍建议优先用「选择目录」挑，免得自己拼错层级。字面 `%` 不在触发集内（与已编码串无法区分，主动编码会破坏幂等），**手工输入含字面 `%` 的路径仍需改用「选择目录」**。
3. **「测试连接」测的是表单里当前填的，不是库里已保存的。** 编辑连接时改了地址或根目录但还没保存，点「测试连接」看到的就是新值（提示里的根目录也打解码后的可读路径）；只有表单里留空/未渲染的字段（典型是密码「留空表示不修改」）才回退数据库旧值。于是「测试结果还是旧路径」不再等于「保存失败」——要确认是否真写进库，看表格「默认根目录」列或重新进一次编辑页。注意连接列表行内的「测试连接」按钮不带表单，测的仍是已保存值。
4. **「选择目录」弹窗会按当前根目录显示完整面包屑。** 弹窗初始定位在连接已配置的根目录（而不是网盘顶层），面包屑逐级可点回；点「根目录」仍回 `cloudreve://my`。若该根目录本身不可列，服务端会回落到可列目录，面包屑会跟着改到实际列出的位置，避免看起来停在一条不存在的路径上。

### 五、OCR 入池判定：文本层有脏残留也算「没文本」

1. **扫描件只抽到几个字符的页眉/水印（如 `CAD ( )`），现在也会送 OCR 了。** 旧实现只看「文本层是不是空串」，非空即视为「已有正文」，于是一份 42 页扫描 PDF 因为抽到 7 个字符就永远不识别，勾「强制重跑 OCR」也无效。现在统一走 `netdisk_ocr_text_needs_ocr()`：空串，或非空但未达 `netdisk_ocr_index_quality_ok()` 门槛（默认可读率 `0.3`、且 ≥ `20` 字符），都算需要 OCR。
2. **「强制重跑 OCR」现在对已有正文也生效。** `force_ocr=1` 会绕过上面的正文判定，达标文本也会重新识别；若识别失败或返回空，索引里的原有正文**不会被清空**，状态行落 `failed`/`invalid` 并记一条错误原因，下一轮重建继续重试。
3. **已知边界：识别结果本身也不达标时会每轮重扫。** 例如同一份图册识别出来仍是 `CAD ( )`，状态行会记为 `done`、失败计数归零，于是下一轮增量跳过不成立，每次重建都会重新下载并重跑一遍，且「连续失败 3 次熔断」永远不会触发。属既有调度设计，批量重建前建议先确认这类纯图册是否需要进索引（可在连接配置里关掉 OCR，或把它放到 root 之外）。

### 六、管理端「重建索引」的范围与全量入口

1. **点错连接不再「假装已加入队列」。** 每个连接行内的重建按钮只重建**绑定该连接**的分享；落在范围外的分享原先被静默 `continue` 丢掉，响应却照样回「已加入后台重建队列」。现在改为计数并回执：响应体新增 `out_of_scope` 与 `out_of_scope_shares`（后者最多列 20 条 `{id, slug, config_id}`，超出部分只体现在计数里，**不是全量**），前端按 `data.queued` 说真话——一个任务都没建时直接显示服务端的 `msg` 并标红，不再报「已加入队列」；`code:0` 只代表请求被受理，不代表建了任务。
2. **新增「重建全部索引」入口（`config_id=0`）。** 表格下方的 ghost 按钮复用同一个事件处理器，`config_id=0` 是后端既有的「不过滤」语义：重建所有已启用连接下的分享，以及未绑定连接的分享。该入口**不带「强制重跑 OCR」**——需要强制重跑请用上方各连接自己的按钮（勾选框在那一侧）。
3. **已知边界：** `migrate()` 每次开库都会把「已启用网盘且 `netdisk_config_id=0`」的分享绑到第一个启用连接（`index.php:815` 那段 `UPDATE shares`），因此「已启用网盘但未绑定连接」的分享只在一个启用连接都没有时才长期存在；属既有行为，本轮不改。

---

## 📖 功能演示（动图源自 GitHub，加载可能需“魔法”）

### ① 生成 API Key 并填入插件

![生成 API Key 并填入](https://github.com/user-attachments/assets/8ad5e431-8a60-4e83-a594-ff1de28af68d)

---

### ② 分享文档 / 笔记本
仅以分享笔记本为例，分享单篇文档或文档及子文档也可以

![分享笔记本](https://github.com/user-attachments/assets/62faf774-16e7-4b48-9dff-d738749ee4d5)

---

### ③ 打开分享链接查看内容

![打开分享链接](https://github.com/user-attachments/assets/155ee85c-1a73-49e4-b03e-69b9a31f2727)

---

### ④ 访问统计概览

![Access statistics](https://github.com/user-attachments/assets/b2690a03-6e97-4845-87be-1ab3e5406847)

---

## ☕ 支持作者

如果你觉得这个项目对你有帮助，欢迎支持作者 ❤️  
你的支持将激励我 **持续维护与优化**，打造更好用的工具。

> ⬆️ 下方 Star 徽章与赞赏码均指向 **原项目与原作者**，与 fork 维护者无关。
> 如果你愿意，也可以给[本仓库](https://github.com/804260247/siyuan-plugin-share)点个 ⭐ 支持后续维护。

<div align="center">
    <a href="https://github.com/b8l8u8e8/siyuan-plugin-share">
        <img src="https://img.shields.io/github/stars/b8l8u8e8/siyuan-plugin-share?style=for-the-badge&color=ffd700&label=%E7%BB%99%E4%B8%AAStar%E5%90%A7" alt="Github Star">
    </a>
</div>

<div align="center" style="margin-top: 40px;">
    <div style="display: flex; justify-content: center; align-items: center; gap: 30px;">
        <div style="text-align: center;">
            <img src="https://github.com/user-attachments/assets/81d0a064-b760-4e97-9c9b-bf83f6cafc8a" 
                 style="height: 280px; width: auto; border-radius: 10px; border: 2px solid #07c160;">
            <br/>
            <b style="color: #07c160; margin-top: 10px; display: block;">微信支付</b>
        </div>
        <div style="text-align: center;">
            <img src="https://github.com/user-attachments/assets/9e1988d0-4016-4b8d-9ea6-ce8ff714ee17" 
                 style="height: 280px; width: auto; border-radius: 10px; border: 2px solid #1677ff;">
            <br/>
            <b style="color: #1677ff; margin-top: 10px; display: block;">支付宝</b>
        </div>
    </div>
    <p style="margin-top: 20px;"><i>你的支持，是我持续迭代的最大动力 🙏</i></p>
</div>

---

## 🛠️ 其他信息

- 🔀 原项目（上游）：
  [b8l8u8e8/siyuan-plugin-share](https://github.com/b8l8u8e8/siyuan-plugin-share)

- 🐞 问题反馈（本仓库）：  
  [GitHub Issues](https://github.com/804260247/siyuan-plugin-share/issues)

- 📄 开源协议：  
  [MIT License](https://github.com/804260247/siyuan-plugin-share/blob/main/LICENSE)

- 🧾 更新日志：  
  [CHANGELOG.md](https://github.com/804260247/siyuan-plugin-share/blob/main/CHANGELOG.md)
  
- 🏅 贡献列表：  

  [Contributors List](https://github.com/804260247/siyuan-plugin-share/graphs/contributors)
  
- 💖 赞助列表：  
[Sponsor List](https://github.com/804260247/siyuan-plugin-share/blob/main/sponsor-list.md)

