# 🔗 Siyuan Share · Enhanced Fork

This repository **[804260247/siyuan-plugin-share](https://github.com/804260247/siyuan-plugin-share)** is an **enhanced fork** of the original project **[b8l8u8e8/siyuan-plugin-share](https://github.com/b8l8u8e8/siyuan-plugin-share)** by "取舍", maintained by [804260247](https://github.com/804260247).

The plugin is free and open-source. Core sharing features — **share links, access passwords, expiration, visitor limits, access statistics** — come from upstream and are not re-documented here.

🌍 Languages: [中文](https://github.com/804260247/siyuan-plugin-share/blob/main/README.md) ｜ [English](https://github.com/804260247/siyuan-plugin-share/blob/main/README_EN.md)

> ### 📖 For the original project docs, see upstream
>
> Feature descriptions, tutorials, server setup and operations docs live upstream and are not copied here:
>
> - Original repository: [b8l8u8e8/siyuan-plugin-share](https://github.com/b8l8u8e8/siyuan-plugin-share)
> - Upstream Chinese README: [README.md](https://github.com/b8l8u8e8/siyuan-plugin-share/blob/main/README.md)
> - Beginner tutorial: [share.b0x.top/s/8c34c022](https://share.b0x.top/s/8c34c022)
> - Server setup guide: [Feishu doc](https://ccnwc9xa9692.feishu.cn/wiki/MQCtwMtQaifPuak4zl3cIMCqnLx)
>
> Copyright, upstream donations and Stars belong to the original author; this fork has its own sponsorship section below.

---

## ✨ What this fork adds

### v0.5.7 — Cloudreve netdisk integration

Turns "share a note" into "share your netdisk": netdisk files and notes share one access gate (password / expiry / visit count / enabled state).

| Capability | Description |
|---|---|
| **Multiple netdisk connections** | Cloudreve v4 multi-connection config with connection test, default root, enable/disable; passwords and tokens encrypted at rest with AES-256-GCM |
| **Netdisk tab** | Share page gains a netdisk tab: directory browsing, breadcrumbs, thumbnails, download rules |
| **Online preview** | Images / PDF / text preview directly; Office documents via OnlyOffice or Collabora (WOPI); `xlsx.full.min.js` previews spreadsheets without WOPI |
| **Full-text index** | PDF / Office / plain-text extraction; share-page search supports exact, fuzzy and full-text recall, re-validated against the current share root |
| **OCR** | Scanned PDFs and images go through OCR: PaddleX sidecar (high accuracy) first, fallback to built-in `tesseract` |
| **Incremental + circuit breaker** | Files that already meet the quality bar are skipped; forced re-run switch (`force_ocr=1`); 3 consecutive failures trip the breaker; queue starvation is marked `starved`, not failed |
| **Content-addressed OCR reuse** | Results keyed by `content_hash + params_key`, decoupled from file paths: renames, moves and multi-share attachments all hit the cache |
| **PC OCR tool** | `tools/ocr-win/`: NAS builds index only → PC renders and recognizes locally on GPU → results uploaded back, bypassing the NAS CPU bottleneck |
| **Resident worker** | `php-site/nd-worker.php` under supervisord, continuously draining index / OCR queues and reaping stuck jobs |
| **Stability hardening** | Memory budget guard before decoding huge images, SQLite deadlock rewrites, crash self-healing with heartbeats, one retry on OCR 5xx |
| **Security hardening** | `@share` root alias allowlist, passwords never echoed back, MIME allowlist and SVG sandbox CSP, WOPI fileId validation, all external commands escaped |

### v0.5.6 — Share page experience

- **Right sidebar: table of contents / recent updates** — draggable and collapsible; "recent updates" uses the note's real modification time (front matter `lastmod`), timezone fixed to `Asia/Shanghai`, count selectable 5 / 10 / 20 / 50
- **Note-level announcements** — rendered inside Siyuan, shown as a popup on the share page, synced with the plugin; a placeholder keeps the sidebar layout when there is no announcement
- **i18n** — Chinese and English strings for announcements

### Maintenance changes

- Default README in Chinese, English moved to `README_EN.md`; both include install instructions
- Share page and site footer credit changed to "supported by 804260247", linking to this fork
- Fixed unreadable static assets in Docker deployments (`a+rX` permissions, nginx worker as `www-data`, read-only `deploy/nginx.conf` mount)
- `.gitattributes` pins `Dockerfile` / `*.sh` to LF and `*.ps1` / `*.cmd` to CRLF so cross-platform checkouts don't break containers or PowerShell parsing
- `plugin.json` `url` points to this fork; `version` updated per release

---

## 📦 Installation

This plugin is **not listed on the SiYuan marketplace** yet; install manually (or grab `package.zip` from [Releases](https://github.com/804260247/siyuan-plugin-share/releases)):

1. Download `package.zip` (or `Code → Download ZIP`) and extract
2. Rename the folder to **`siyuan-plugin-share`** (drop the `-main` suffix)
3. Put it into the SiYuan workspace `data/plugins/` directory
4. Restart SiYuan and enable the plugin in `Settings → Plugins`

> 💡 Installing via the marketplace "local install / import" flow requires extracting, renaming the folder, re-zipping and importing.

---

## 📚 Docs in this fork

| Document | Contents |
|---|---|
| [README_NAS.md](README_NAS.md) | NAS deployment (named volumes, permissions, OCR sidecar) — Chinese |
| [README_DOCKER_BUILD.md](README_DOCKER_BUILD.md) | Docker Compose build-from-source deployment — Chinese |
| [README_DOCKER_PULL.md](README_DOCKER_PULL.md) | Docker Compose pull-image deployment — Chinese |
| [README_DOCKER_CLI.md](README_DOCKER_CLI.md) | Plain `docker` commands, no orchestration — Chinese |
| [README_OCR_REBUILD_WIN.md](README_OCR_REBUILD_WIN.md) | PC-side OCR rebuild and result upload guide (`tools/ocr-win`) — Chinese |
| [NETDISK_TROUBLESHOOTING.md](NETDISK_TROUBLESHOOTING.md) | Self-hosted Cloudreve + Collabora troubleshooting, index/OCR judgment rules — Chinese |
| [CHANGELOG.md](CHANGELOG.md) | Full changelog (`0.5.5` and earlier from upstream) — Chinese |
| [tools/ocr-win/README.md](tools/ocr-win/README.md) | The PC OCR tool's own README — Chinese |

---

## 🧾 Versions and releases

- Current version: `version` in [plugin.json](plugin.json)
- Release artifacts in [Releases](https://github.com/804260247/siyuan-plugin-share/releases): `package.zip` (SiYuan plugin) and `php-site-vX.Y.Z.zip` (server)
- Rebuild the image after upgrading the server: `docker compose up -d --build`; front-end assets are strongly cached, so hard-refresh with `Ctrl+Shift+R`

---

## ☕ Sponsor

The netdisk integration, full-text index / OCR and the PC-side GPU OCR tool in this fork are built and maintained in the author's spare time. If it saved you time, you can buy me a coffee via WeChat Pay:

<p align="center">
  <img src="sponsor-wechat.png" width="240" alt="WeChat Pay donation QR code">
</p>

- Sponsorship is entirely optional and **unlocks no extra features**; no dedicated support or custom work is promised
- To be listed, open an [issue](https://github.com/804260247/siyuan-plugin-share/issues) after donating with the amount, date and the name you want shown
- See [sponsor-list.md](sponsor-list.md) for this fork's own sponsor list (unrelated to the upstream list); upstream has its own donation channel, and copyright and Stars remain with the original author

---

## 🐞 Feedback and license

- Issues: [GitHub Issues](https://github.com/804260247/siyuan-plugin-share/issues)
- License: [MIT License](https://github.com/804260247/siyuan-plugin-share/blob/main/LICENSE)
- Contributors: [Contributors](https://github.com/804260247/siyuan-plugin-share/graphs/contributors)

If upstream helped you, consider starring [the original repository](https://github.com/b8l8u8e8/siyuan-plugin-share); starring [this fork](https://github.com/804260247/siyuan-plugin-share) also supports ongoing maintenance.
