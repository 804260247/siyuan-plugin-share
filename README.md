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

