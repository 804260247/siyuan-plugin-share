# 🔗 思源分享（Siyuan Share）· 增强分支

本仓库 **[804260247/siyuan-plugin-share](https://github.com/804260247/siyuan-plugin-share)** 是原项目 **[b8l8u8e8/siyuan-plugin-share](https://github.com/b8l8u8e8/siyuan-plugin-share)**（作者「取舍」）的 **增强分支（fork）**，由 [804260247](https://github.com/804260247) 维护。

插件本体免费开源；**分享链接、访问密码、到期时间、访客上限、访问统计**等基础能力来自上游，本 README 不再重复介绍。

🌍 语言：[中文](https://github.com/804260247/siyuan-plugin-share/blob/main/README.md) ｜ [English](https://github.com/804260247/siyuan-plugin-share/blob/main/README_EN.md)

> ### 📖 上游介绍请看原仓库
>
> 原项目功能说明、使用教程、服务端搭建与运维文档均在上游，本仓库不复制：
>
> - 原项目主页：[b8l8u8e8/siyuan-plugin-share](https://github.com/b8l8u8e8/siyuan-plugin-share)
> - 上游中文 README：[README.md](https://github.com/b8l8u8e8/siyuan-plugin-share/blob/main/README.md)
> - 保姆级使用教程：[share.b0x.top/s/8c34c022](https://share.b0x.top/s/8c34c022)
> - 服务端搭建教程：[飞书文档](https://ccnwc9xa9692.feishu.cn/wiki/MQCtwMtQaifPuak4zl3cIMCqnLx)
>
> 版权、上游赞赏码与 Star 均归原作者所有；本仓库自己的赞助入口见下方「☕ 赞助支持」。

---

## ✨ 本仓库新增了什么

### v0.5.7 —— Cloudreve 网盘接入

把「分享笔记」扩展成「分享自己的网盘」：网盘文件与笔记共用一套分享门禁（密码 / 有效期 / 访问次数 / 启用状态）。

| 能力 | 说明 |
|---|---|
| **网盘多连接** | Cloudreve v4 多连接配置，支持连接测试、默认根目录、启用 / 停用；密码与 token 用 AES-256-GCM 加密落库 |
| **网盘标签页** | 分享页新增「网盘」标签，支持目录浏览、面包屑、缩略图与下载规则 |
| **在线预览** | 图片 / PDF / 文本直接预览；Office 文档走 OnlyOffice 或 Collabora（WOPI）；无 WOPI 时用 `xlsx.full.min.js` 预览表格 |
| **全文索引** | PDF / Office / 纯文本抽取入库，分享页搜索支持精确、模糊与全文召回；索引结果按当前分享 root 二次校验 |
| **OCR 识别** | 扫描版 PDF 与图片走 OCR：PaddleX sidecar（高精度）优先，未启用时回退镜像内置 `tesseract` |
| **增量与熔断** | 已达标文件不再重扫；强制重跑开关（`force_ocr=1`）；连续失败 ≥3 次熔断；队列让位记 `starved` 而不判失败 |
| **OCR 结果内容寻址复用** | 结果按 `content_hash + params_key` 存表，与文件路径解耦：改名、移动、同文件挂多个分享都能命中缓存 |
| **PC 端 OCR 工具** | `tools/ocr-win/`：NAS 只建索引 → PC 用 GPU 本地渲染识别 → 结果回导服务端复用，绕开 NAS 的 CPU 串行瓶颈 |
| **常驻 worker** | `php-site/nd-worker.php` 由 supervisord 托管，持续消费索引 / OCR 队列并回收卡死任务 |
| **稳定性加固** | 巨图解码内存预算守卫、SQLite 死锁改写、崩溃自愈与心跳、OCR 服务端 5xx 自动重试一次 |
| **安全加固** | `@share` 根目录别名白名单、网盘密码不回显、MIME 白名单与 SVG 沙箱 CSP、WOPI fileId 校验、外部命令全量转义 |

### v0.5.6 —— 分享页体验

- **右侧目录 / 最近更新栏**：分享页右栏展示目录与最近更新，支持拖拽与折叠；「最近更新」用笔记真实修改时间（front matter `lastmod`），时间统一 `Asia/Shanghai`，数量可下拉 5 / 10 / 20 / 50
- **笔记级公告**：公告在思源笔记内渲染、分享页弹窗展示，插件端同步；无公告时保留占位并补齐右侧栏
- **i18n**：公告相关中英文案

### 维护性改动

- 默认 README 中文、英文移至 `README_EN.md`；两份 README 含安装方式
- 分享页与站点页脚署名改为「由 804260247 提供支持」并指向本仓库
- 修复 Docker 部署下静态资源不可读（目录权限 `a+rX`、nginx worker 改 `www-data`、新增 `deploy/nginx.conf` 只读挂载）
- `.gitattributes` 固定 `Dockerfile` / `*.sh` 为 LF、`*.ps1` / `*.cmd` 为 CRLF，避免跨平台检出后容器起不来或 PowerShell 解析失败
- `plugin.json` 的 `url` 指向本仓库，`version` 随发版更新

---

## 📦 安装方式

本插件**暂未上架思源官方集市**，需手动安装（也可从 [Releases](https://github.com/804260247/siyuan-plugin-share/releases) 下载 `package.zip`）：

1. 下载 `package.zip`（或仓库 `Code → Download ZIP`）并解压
2. 将解压出的文件夹**重命名为 `siyuan-plugin-share`**（去掉 `-main` 后缀）
3. 放入思源工作空间的 `data/plugins/` 目录
4. 重启思源，在 `设置 → 插件` 中启用

> 💡 通过集市「本地安装 / 导入」方式安装时，需先解压、重命名文件夹，再重新压缩为 ZIP 后导入。

---

## 📚 本仓库文档

| 文档 | 内容 |
|---|---|
| [README_NAS.md](README_NAS.md) | NAS 部署（Named Volumes、权限、OCR 侧车启用） |
| [README_DOCKER_BUILD.md](README_DOCKER_BUILD.md) | Docker Compose 本地构建部署 |
| [README_DOCKER_PULL.md](README_DOCKER_PULL.md) | Docker Compose 直拉镜像部署 |
| [README_DOCKER_CLI.md](README_DOCKER_CLI.md) | 纯 `docker` 命令部署（不用编排工具） |
| [README_OCR_REBUILD_WIN.md](README_OCR_REBUILD_WIN.md) | PC 端 OCR 重建与结果回导操作指引（`tools/ocr-win`） |
| [NETDISK_TROUBLESHOOTING.md](NETDISK_TROUBLESHOOTING.md) | 自建 Cloudreve + Collabora 部署排障与索引/OCR 判定口径 |
| [CHANGELOG.md](CHANGELOG.md) | 完整更新日志（`0.5.5` 及更早来自上游） |
| [tools/ocr-win/README.md](tools/ocr-win/README.md) | PC 端 OCR 工具自身说明 |

---

## 🧾 版本与发布

- 当前版本见 [plugin.json](plugin.json) 的 `version` 字段
- 发布包在 [Releases](https://github.com/804260247/siyuan-plugin-share/releases)：`package.zip`（思源插件包）、`php-site-vX.Y.Z.zip`（服务端包）
- 服务端升级后需重建镜像：`docker compose up -d --build`；前端强缓存，浏览器 `Ctrl+Shift+R`

---

## ☕ 赞助支持

网盘接入、全文索引 / OCR、PC 端 GPU 重建工具这些增强能力，都是维护者在业余时间开发和维护的。如果它帮你省下了时间，可以微信扫码请我喝杯咖啡：

<p align="center">
  <img src="sponsor-wechat.png" width="240" alt="微信赞助收款码">
</p>

- 赞助完全自愿，**不影响任何功能的可用性**，也不承诺专属支持或定制开发
- 想留名可以在赞助后开一个 [Issue](https://github.com/804260247/siyuan-plugin-share/issues) 说明（金额、时间、想署的名字），我会补到名单里
- 赞助名单见 [sponsor-list.md](sponsor-list.md)（本仓库自己的名单，与上游名单无关）；上游有自己的赞赏渠道，版权与 Star 仍归原作者

---

## 🐞 反馈与许可

- 问题反馈：[GitHub Issues](https://github.com/804260247/siyuan-plugin-share/issues)
- 开源协议：[MIT License](https://github.com/804260247/siyuan-plugin-share/blob/main/LICENSE)
- 贡献列表：[Contributors](https://github.com/804260247/siyuan-plugin-share/graphs/contributors)

如果你觉得上游项目有帮助，欢迎给[原仓库](https://github.com/b8l8u8e8/siyuan-plugin-share)点 ⭐；也欢迎给[本仓库](https://github.com/804260247/siyuan-plugin-share)点 ⭐ 支持后续维护。
