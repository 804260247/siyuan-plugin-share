# 更新日志

> 📌 **本仓库 [804260247/siyuan-plugin-share](https://github.com/804260247/siyuan-plugin-share) 是原项目 [b8l8u8e8/siyuan-plugin-share](https://github.com/b8l8u8e8/siyuan-plugin-share) 的增强分支（fork）。**
> `0.5.5` 及更早的条目均来自上游原作者，未作修改；自 `v0.5.6` 起由本仓库维护发布。

---

## 未发布（待验证）：Cloudreve 网盘扩展

> 本节的改动位于分支 `feat/netdisk-cloudreve`，以 PR 形式提交，尚未合并、尚未发布；验证通过后再确定版本号。
> 移植自 `siyuan-share-netdisk:fix-final` 镜像（基线：上游 v0.5.5 `263921d5`），并保留本仓库 v0.5.6 的全部分叉改动。

### 网盘接入与文件浏览

- [x] 新增 Cloudreve v4 多连接配置，支持连接测试、默认目录、启用/停用
- [x] 分享页新增「网盘」标签页，支持目录浏览、面包屑、文件缩略图与下载规则
- [x] 下载/预览沿用分享门禁：分享密码、有效期、访问次数与网盘启用状态逐项校验

### 在线预览

- [x] 支持图片、PDF、文本；Office 文档通过 OnlyOffice/Collabora（WOPI）在线编辑/预览
- [x] 新增 `assets/vendor/xlsx.full.min.js`，无 WOPI 时可直接预览表格
- [x] WOPI token 使用 HMAC-SHA256 签名并校验过期时间

### 全文索引与 OCR

- [x] 新增 `netdisk_index`、`netdisk_index_jobs`、`netdisk_ocr_queue` 等 7 张表
- [x] 支持 PDF/Office/纯文本抽取；扫描版 PDF 与图片走 OCR（PaddleOCR sidecar，未启用时回退本地 tesseract）
- [x] 新增常驻 `php-site/nd-worker.php`，由 supervisord 托管，持续消费索引/OCR 队列并回收卡死任务
- [x] 分享页搜索支持精确、模糊与全文召回；索引结果按当前分享 root 二次校验

### 安全加固（移植时补充）

- [x] `@share` 链接的 root 归属改为别名白名单校验，拒绝 `..`、反斜杠与任意 `@share` URI
- [x] Cloudreve 密码、access_token、refresh_token 使用 AES-256-GCM 加密落库（`storage/.netdisk_secret`，0600），兼容存量明文自动迁移
- [x] 管理端不再回显网盘密码，编辑时留空表示不修改
- [x] 缩略图/内容响应增加 MIME 白名单、`X-Content-Type-Options: nosniff` 与 SVG 沙箱 CSP
- [x] WOPI 回调校验 fileId；OCR 全部外部命令经 `escapeshellarg` 转义并设置超时

### 稳定性修复（NAS 实测后追加）

- [x] 修复控制台渲染撞 SQLite 升级死锁：「新增连接」后重定向渲染 /admin 报 `Fatal error: database is locked`
- [x] 修复 WOPI 应用校验失败报错不明：新增 `netdisk_office_fetch()` 统一抓取 `/hosting/discovery`，https 自签证书场景自动绕过校验降级重试一次，失败原因（DNS / 拒连 / 超时 / 证书 / HTTP 状态码）透传到报错文案
- [x] `recalculate_user_storage()` 读快照未关游标即发 UPDATE 的模式全库改造：单行取值后统一 `closeCursor()`，关键写入用 `netdisk_exec_retry()` 包裹，重试耗尽后降级读取库中已存值，渲染路径绝不向上抛
- [x] 控制台用户列表改为批量聚合重算（整页最多 3 条 SQL，值未变的用户跳过 UPDATE），从源头消除逐用户循环的高频写入
- [x] php-fpm 错误可见性加固：`display_errors=Off`、`log_errors=On`，配合 `catch_workers_output=yes` 导入 supervisor 日志，生产页面不再泄露堆栈与绝对路径
- [x] 「网盘标签为何没出现」可诊断：控制台「分享绑定」表格新增「网盘标签」列，按与分享页完全同口径的 `netdisk_tab_state()` 输出「可显示 / 不可显示：原因」（未开启、无可用连接、绑定连接停用/缺少地址或邮箱、连接不存在、单文档分享页等原因逐项区分），不再需要打开分享页猜

### 部署

- [x] Dockerfile 增加 `supervisor`、`poppler-utils`、`tesseract-ocr` 及中英文语言包；`memory_limit` 1G、`max_execution_time` 0
- [x] 新增 `deploy/supervisord.conf`，统一托管 php-fpm、nginx、nd-worker
- [x] docker-compose 增加 `ocr-data` 共享卷，供 OCR sidecar 与主容器交换图片
- [x] docker-compose 增加可选 `ocr:` 服务段（PaddleX serving，`deploy/ocr/init.sh`）：环境变量开放 `OCR_ENGINE`/`OCR_DEVICE`/`OCR_PORT`/`OCR_PROFILE`/`OCR_THREADS`/`OCR_PIP_INDEX`，内存/CPU 限额可调；sidecar 端口统一为 8080，未启用时自动回退 tesseract
- [x] 保留本仓库分叉：nginx worker `www-data`、页脚 `804260247`、`site_version` 0.5.6、PR #72/#73 的列布局与公告

### 已知边界

- `config.php` 仍需外部挂载，镜像/仓库不会自动生成
- 单文档分享页保留本仓库既有布局，不显示网盘标签页
- 升级或修改网盘 root 后，`@share` 索引命中可能短暂隐藏，待 worker 重建别名缓存后恢复
- 尚未在真实 Docker/NAS 环境完成构建验证；合并前需执行 `docker compose up -d --build` 并按验收清单逐项确认

## v0.5.6 2026-10-19

> 本节由 fork 维护者 [804260247](https://github.com/804260247) 添加。以下改动**已合并进本仓库 `main`**；
> 对应的上游 PR [#72](https://github.com/b8l8u8e8/siyuan-plugin-share/pull/72)、[#73](https://github.com/b8l8u8e8/siyuan-plugin-share/pull/73) 自 2026/8 起一直处于 open 状态，未被上游合并。
> 本仓库 `plugin.json` 的 `url` 已指向本仓库，`version` 记为 `0.5.6`；本仓库暂不上架思源集市，可手动安装。

### 🗂️ 分享页右侧栏：目录 / 最近更新（分支 `feat/share-column-layout`，7 次提交，2026/8/13 ~ 8/21）

- [x] 分享页新增右侧**目录栏**与「**最近更新**」栏，支持栏位拖拽与折叠
- [x] 优化分享页栏位交互细节与提示位置
- [x] 「最近更新」日期改用笔记**真实修改时间**（front matter `lastmod`），不再使用上传时间
- [x] 分享页时区统一为 `Asia/Shanghai`
- [x] 「最近更新」新增数量下拉（5 / 10 / 20 / 50），面板头部改为上下两行
- [x] 修复全局 `width:100%` 导致下拉框过长的问题（下拉改回与标题同行，利用右侧留白）
- [x] 新增 `.gitattributes`，将 `Dockerfile` 与备份脚本行尾固定为 **LF**，修复 Windows 检出后容器无法启动的问题

### 📢 笔记级公告（分支 `feat/share-announcement`，5 次提交，2026/8/13 ~ 8/19）

- [x] 新增**笔记级公告提示**：支持思源笔记内渲染、公告弹窗、插件端同步
- [x] 笔记本分享基址直接展示公告内容；无公告时保留占位并补齐右侧栏
- [x] 补充 i18n 中英文案（`i18n/zh_CN.json`、`i18n/en_US.json`）

### 📁 涉及文件

- 插件端：`index.js`、`index.css`、`i18n/zh_CN.json`、`i18n/en_US.json`
- 服务端：`php-site/index.php`、`php-site/assets/app.js`、`php-site/assets/style.css`
- 其他：`.gitattributes`（新增）

### ⚠️ 已知边界

- 两项特性**已合并进 `main`**，`main` 现为「上游 v0.5.5 + 两项特性 + 本仓库维护性更新」。
- `plugin.json` 的 `url` 已改为本仓库地址，`version` 记为 `0.5.6`；本仓库暂不上架思源集市，用户可手动安装。

### 🚀 本仓库维护性更新（2026/10）

- [x] 默认 README 切换为中文，英文版移至 `README_EN.md`；两份 README 新增「安装方式」章节（未上架集市，ZIP 手动安装）— `09b925c`
- [x] 分享页与站点页脚署名改为「由 804260247 提供支持」并指向本仓库 — `d635c45`
- [x] 修复 Docker 部署下静态资源不可读导致分享页只有文字（目录权限 `a+rX`；nginx worker 改 `www-data` 并新增 `deploy/nginx.conf` 只读挂载）— `f5f663a`、`a17cd57`
- [x] `plugin.json` 的 `url` 指向本仓库、`version` 0.5.6；`php-site/config.example.php` 的 `site_version` 同步 0.5.6；`sync-to-gitee` 源端改为本仓库 — `0590b93`、`c204f19`、`09b925c`


## 0.5.5 2026/7/12

- [x] [Chen017](https://github.com/Chen017) 修复部分包含嵌套美元符号的数学公式显示异常问题 [PR 69](https://github.com/b8l8u8e8/siyuan-plugin-share/pull/69)

## 0.5.4 2026/4/6

- [x] 修复插件端部分bug
- [x] 服务端分享列表状态筛选新增“已过期”条件，便于快速定位过期分享
- [x] 优化服务端表格样式  [Issues 58](https://github.com/b8l8u8e8/siyuan-plugin-share/issues/58)
- [x] 登录与注册页面新增双吉祥物互动动画

## 0.5.3 2026/3/19

- [x] 服务端后台新增自定义 CSS 和 JS 功能，提升用户对网站界面与行为的自定义能力。  [Issues 55](https://github.com/b8l8u8e8/siyuan-plugin-share/issues/55)

## 0.5.2 2026/3/14

- [x] 透明图片分享页预览显示棋盘格背景 [Issues 54](https://github.com/b8l8u8e8/siyuan-plugin-share/issues/54)

## 0.5.1 2026/3/12

- [x] 调整分享页列表样式，统一有序列表为 Markdown 默认风格 [Issues 51](https://github.com/b8l8u8e8/siyuan-plugin-share/issues/51)

## 0.5.0 2026/3/3

- [x] 修复部分数学公式渲染问题

## 0.4.9 2026/2/27

- [x] 优化与修复服务端登录和注册的相关问题

## 0.4.8 2026/2/24

- [x] 优化自动更新分享功能的性能
- [x] 启动自动更新功能时，提示开启自动更新可能会使得思源笔记资源占用更高

## 0.4.7 2026/2/23

- [x] 新增[保姆级教程使用文档](https://share.b0x.top/s/8c34c022)
- [x] 新增文档块数缓存增强增量检测，升级后首次可能触发一次全量更新分享以建立新缓存，后续继续增量更新

## 0.4.6 2026/2/22

- [x] 优化自动更新体验以及修复部分bug
- [x] 关闭笔记本时对应分享会跳过自动更新并记录历史  [Issues 48](https://github.com/b8l8u8e8/siyuan-plugin-share/issues/48)
- [x] 重构容器运行栈并优化镜像体积，镜像大小由 500+MB 降至 90+MB。

## 0.4.5 2026/2/21

- [x] 优化插件移动端使用体验 

- [x] 插件总设置新增自动更新分享「静默时间」自定义设置：若在静默时间内无更改则自动更新分享，有更改则重置计时，防止频繁触发自动更新分享
- [x] 新增纯 Docker 命令行部署方案

## 0.4.4 2026/2/20

- [x] 新增自动更新分享功能：开启后可自动检测文档变更并更新分享，支持查看自动更新状态、排队任务与历史记录。

## 0.4.3 2026/2/18

- [x] 插件端新增分享链接二维码，移动场景常见需求，方便现场扫码访问
![分享链接二维码](https://github.com/user-attachments/assets/41923cd8-e94d-43fd-890c-55caf620f8f1)
- [x] 修复部分bug
- [x] 服务端分享页新增搜索功能
![分享页搜索功能](https://github.com/user-attachments/assets/2f60c3aa-f66e-430d-9986-3efb3932c847)


## 0.4.2 2026/2/16

- [x] 修复部分bug
- [x] 资源访问统一走服务端校验，分享页附件资源同步绑定密码、到期与访客人数限制
- [x] 修复分享密码更新后已验证会话未失效的问题，修改密码后需重新验证

## 0.4.1 2026/2/14

- [x] 访问设置新增「链接后缀」：留空自动随机；自定义支持小写字母和数字（6-32位），并支持后续手动修改分享链接后缀
- [x] 新增 404 及分享失效页面，告别浏览器自带的 404 Not Found 白屏
- [x] 调整插件界面布局

## 0.4.0 2026/2/13

- [x] [Chen017](https://github.com/Chen017) 新增支持在管理员后台向 HTML `<head>` 插入自定义脚本
- [x] 分享时，增量分析完成后，「继续上传」按钮将进入 10 秒倒计时；若在倒计时内无任何操作，系统将自动点击「继续上传」。 [Issues 44](https://github.com/b8l8u8e8/siyuan-plugin-share/issues/44)
- [x] 增加分享排除文档功能，支持在分享时选择不参与分享的文档，并支持按文档层级进行排除。 [Issues 28](https://github.com/b8l8u8e8/siyuan-plugin-share/issues/28)
- [x] 修复部分大文档分享时上传失败的bug
- [x] 性能优化

## 0.3.9 2026/2/10

- [x] 脚注弹窗预览位置优化
- [x] 脚注弹窗在新标签页打开的功能细节优化。 [Issues 41](https://github.com/b8l8u8e8/siyuan-plugin-share/issues/41)

## 0.3.8 2026/2/8

- [x] 将全量分享优化为增量分享，仅同步变更内容；通过优化内部逻辑，显著提升了分享速度。
- [x] [Chen017](https://github.com/Chen017)  为非分享页面适配了深色模式。
- [x] 新增分享导出本地缓存：上传失败后复用导出结果并增量合并，思源笔记启动与插件重载时自动清理缓存
- [x] 优化增量更新，导出只导出更新过的，而不是全部导出
- [x] 修复其他bug

## 0.3.7 2026/2/6

- [x] 修复表格中有合并分享后布局错乱及列数显示异常的问题 [Issues 22](https://github.com/b8l8u8e8/siyuan-plugin-share/issues/22)
- [x] 内嵌 IFrame 支持全屏模式，并在右上角提供全屏切换及取消控件。 [Issues 23](https://github.com/b8l8u8e8/siyuan-plugin-share/issues/23)
- [x] 修复公式排版中的垂直对齐偏移问题。 [Issues 26](https://github.com/b8l8u8e8/siyuan-plugin-share/issues/26)
- [x] 修复有时候第一次打开分享页不渲染的bug  [Issues 26](https://github.com/b8l8u8e8/siyuan-plugin-share/issues/26)
- [x] 修复其他数学公式显示问题 [Issues 33](https://github.com/b8l8u8e8/siyuan-plugin-share/issues/33)
- [x] 修复笔记本分享页在未加载文档时文档树位置偏上的布局偏移问题
- [x] 对引用分享生成的脚注，除脚注跳转外，支持窗口打开方式：点击脚注预览图标可弹窗查看内容，阅读时无需再在正文与脚注间来回切换。
- [x] [Chen017](https://github.com/Chen017) 修复了在深色模式下代码高亮的适配问题

## 0.3.6 2026/2/3

- [x] 提示块适配自定义标题，提示块适配自定义表情，同时提示块样式更符合思源笔记默认风格 [Issues 18](https://github.com/b8l8u8e8/siyuan-plugin-share/issues/18)

- [x] 修复部分分享页面自定义表情显示不出来的 Bug 
- [x] 修复移动端滑动时，在部分显示区域（如表格区域等）无法滑动的问题  [Issues 20](https://github.com/b8l8u8e8/siyuan-plugin-share/issues/20)
- [x] 修复电脑端、手机端、分享页在深色模式下的适配问题 [Issues 21](https://github.com/b8l8u8e8/siyuan-plugin-share/issues/21)
- [x] 优化目录显示细节：解决标题级别跳跃时的缩进问题。例如当 h1 下直接包裹 h6，而另一个 h1 下包裹 h2 时，第一个 h1 下的 h6 不应按原 h6 层级深层缩进，而应自动对齐至 h2 的位置显示(因缺少 h2-h5 级标题)
- [x] 移动端 IFrame 应禁用原生滚动，改为根据屏幕宽度自动缩放并居中适配。
- [x] 修复移动端分享页面视频纵横比失真问题，实现自适应比例渲染。

## 0.3.5 2026/2/1

- [x] 支持分享页内容动态加载，加载期间用户可正常进行其他操作，消除等待焦虑。
- [x] 优化文档树交互逻辑，进入文档时不再整体刷新，保持当前的展开/折叠状态，确保阅读连续性。
- [x] 重构目录布局，将“正文”与“引用/脚注”标题彻底分离；引用内容的标题后新增脚注标记，支持一键跳转回原文位置。
- [x] 插件端新增分享检测机制，若思源笔记引用设置不当可能导致分享的引用失效时，插件将主动提醒，确保分享内容的完整性。
- [x] 修复了正文与引用脚注混杂时的目录层级错位问题（如正文 H2 与引用 H1 的逻辑对齐优化）。
- [x] 优化其他细节
- [x] 修复其他bug

## 0.3.4 2026/1/27

- [x] 新增`管理员后台站点设置-网站地址`设置及说明弹窗，可固定分享链接前缀来解决部分问题，例如容器是http，反向代理是https，导致分享链接全部是http的问题、使用ip+端口服务端，分享链接里面没有端口的问题，都可以固定分享链接前缀彻底解决该问题 [Issues 11](https://github.com/b8l8u8e8/siyuan-plugin-share/issues/11)
- [x] 数据统计功能的优化和增加
- [x] 优化其他细节
- [x] 分享页文档树和文档增加支持思源笔记添加的自定义表情 [Issues 16](https://github.com/b8l8u8e8/siyuan-plugin-share/issues/16)
- [x] 修复其他bug

## 0.3.3 2026/1/26

- [x] 笔记本或文档及子文档分享后，顺序严格与思源笔记文档树中顺序一致，包括手动调整顺序后，更新内容对应分享顺序也应该保持一致 [Issues 14](https://github.com/b8l8u8e8/siyuan-plugin-share/issues/14)
- [x] 修复分享时资源获取失败的bug，例如分享思源官方的笔记本`思源笔记用户指南 ` [Issues 5](https://github.com/b8l8u8e8/siyuan-plugin-share/issues/5)
- [x] 分享页面文档树美化和功能增加(一键折叠、展开，文档树样式优化，文档树显示图标)
- [x] 插件端小细节优化
- [x] 修复其他bug

## 0.3.2 2026/1/24

- [x] 优化服务端分享页图片预览功能细节 [Issues 12](https://github.com/b8l8u8e8/siyuan-plugin-share/issues/12)
- [x] 优化分享页目录细节
- [x] 其他小细节优化
- [x] 插件端分享上传优化

## 0.3.1 2026/1/22

- [x] 服务端修复分享页图片显示问题 [Issues 9](https://github.com/b8l8u8e8/siyuan-plugin-share/issues/9)
- [x] 用户访问概览访问记录显示具体链接，不再显示根文档链接 [Issues 6](https://github.com/b8l8u8e8/siyuan-plugin-share/issues/6)

## 0.3.0 2026/1/22

- [x] 分享页增加适配 `提示块、脑图、图表、五线谱、Graphviz、Flowchart、PlantUML` 和 `代码块、IFrame` 细节优化
- [x] 分享文档默认只分享该单篇文档，可以勾选同时分享子文档 [Issues 6](https://github.com/b8l8u8e8/siyuan-plugin-share/issues/6)
- [x] 修复安全问题，若您使用的是Docker部署，只需更新到本版本或更高版本，无需其他操作；若使用的是面板部署，则需要修改伪静态配置，具体操作请参见该版本的发布页面[Release](https://github.com/b8l8u8e8/siyuan-plugin-share/releases/tag/v0.3.0)。 [Issues 8](https://github.com/b8l8u8e8/siyuan-plugin-share/issues/8)
- [x] 优化部分细节

## 0.2.7 2026/1/20

- [x] 插件端加入复制分享信息，类似百度网盘，包含分享的标题，链接，密码，到期时间，访客人数限制等等，复制后不单单只有链接，可以直接粘贴发送其他人
- [x] 服务端管理员后台增加数据统计，显示面板所有相关指标的数据统计

![数据统计图](https://github.com/user-attachments/assets/1912b571-423e-4691-8d64-8244be456d2e)

- [x] 服务端站点设置违禁词同时应用在分享页评论中，触发违禁词将无法评论
- [x] 服务端违禁词扫描除了扫描上传分享的内容也扫描评论内容
- [x] 服务端分享页面提供查看markdown源码功能，更便利的复制分享内容

## 0.2.6 2026/1/19

- [x] 插件文档增加赞助者列表
- [x] 服务端后台管理员可以手动创建账号 [Issues 4](https://github.com/b8l8u8e8/siyuan-plugin-share/issues/4)
- [x] 服务端新版本提示优化
- [x] 服务端细节优化

## 0.2.5 2026/1/15

- [x] 服务端分享页面图片点开可以放大预览 [Issues 2](https://github.com/b8l8u8e8/siyuan-plugin-share/issues/2)
- [x] 服务端手机版分享页面评论显示问题修复
- [x] 插件端文档增加贡献列表
- [x] [CoderXiaopang](https://github.com/CoderXiaopang) 为服务端增加Docker、Nas部署方法

## 0.2.4 2026/1/13

- [x] 插件端增加多站点设置，可以配置多个服务端一键切换，不用每次换服务端重新填写
- [x] 修复分享时资源上传过多会失败如200+若有一个资源失败则全会失败的问题，加入失败重试机制
- [x] 分享上传优化机制
- [x] 插件端细节优化

## 0.2.3 2026/1/9

- [x] 服务端网站密码页底部设计问题修复
- [x] 增加分享页底部评论功能，同时网站分享列表可以开启评论邮件通知
- [x] 服务端网站账号设置中添加换绑邮箱的功能
- [x] 优化服务端网站细节
- [x] 分享页面添加举报功能，举报后会出现在管理员后台举报管理中

## 0.2.2 2026/1/8

- [x] 服务端登录有效期改为30天，避免一关闭浏览器就需要重新登录导致频繁登录
- [x] 服务端访问统计支持显示记录已经占用了多少空间
- [x] 服务端网站增加新版本提示，当前版本显示
- [x] 增加分享的访客数限制，超过访客数后不能继续访问，和到期时间到期类似
- [x] 分享上传优化，动态调整

## 0.2.1 2026/1/6

- [x] 移动端分享界面调整
- [x] 增加只更新访问密码和到期时间的按钮(更新访问设置)，避免更新这些也要上传整个文档导致时间增加 
- [x] 分享上传改为并发上传，速度更快
- [x] 分享页面字体调整更似markdown字体风格
- [x] 笔记本分享的文档树保持持久化，而不是点击一个文档之后全部折叠
- [x] 服务端网站用户端增加访问统计，可以让用户了解自己分享的文档访问情况如何
- [x] 修复移动端分享完文档树没有出现分享图标，需要退出重进软件才有

## 0.2.0 2026/1/5

- [x] 优化服务端登录和注册
- [x] 服务端管理员后台增加手动分片清理的功能，对于用户上传一半取消的情况，缓存的分片可以通过后台清理
- [x] 修复上传一半取消服务端网站有分享记录的问题
- [x] 修复服务端网站不适配移动端的问题
- [x] 修复katex公式显示与默认显示风格不一样的问题

## 0.1.2 2026/1/5

- [x] 优化细节
- [x] 分享文档和笔记本时上传改为分片上传，避免太大导致上传失败

## 0.1.1 2026/1/4

- [x] 插件初版
- [x] 服务端初版

