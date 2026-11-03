# NAS 部署指南

## 问题说明

NAS 上使用 bind mount (直接挂载主机目录) 可能会遇到权限问题,因为:
- NAS 通常使用 NFS、CIFS/SMB 等网络文件系统
- 这些文件系统不完全支持 Unix 权限管理
- `chown` 命令可能失败或被忽略

## 解决方案:使用 Docker Named Volumes

### 1. 使用 NAS 专用配置文件

```bash
# 在 NAS 上使用这个配置文件
docker-compose -f docker-compose.nas.yml up -d
```

### 2. 配置说明

`docker-compose.nas.yml` 使用 named volumes 而不是 bind mounts:

```yaml
volumes:
  - storage-data:/var/www/html/storage   # Docker 管理的 volume
  - uploads-data:/var/www/html/uploads   # Docker 管理的 volume
```

**优点**:
- ✅ Docker 自动管理权限
- ✅ 兼容所有 NAS 文件系统
- ✅ 性能更好
- ✅ 数据持久化

**缺点**:
- ❌ 数据不直接在主机目录中可见
- ❌ 需要使用 Docker 命令查看/备份

### 3. 数据管理

#### 查看数据位置

```bash
docker volume ls
docker volume inspect siyuan-plugin-share_storage-data
```

#### 查看数据内容

```bash
# 查看 storage 数据
docker run --rm -v siyuan-plugin-share_storage-data:/data alpine ls -lah /data

# 查看数据库
docker run --rm -v siyuan-plugin-share_storage-data:/data alpine cat /data/app.db | wc -c
```

#### 备份数据

```bash
# 使用提供的备份脚本
./backup-volumes.sh
```

手动备份:
```bash
# 备份 storage
docker run --rm \
  -v siyuan-plugin-share_storage-data:/source:ro \
  -v $(pwd)/backups:/backup \
  alpine tar czf /backup/storage-$(date +%Y%m%d).tar.gz -C /source .

# 备份 uploads  
docker run --rm \
  -v siyuan-plugin-share_uploads-data:/source:ro \
  -v $(pwd)/backups:/backup \
  alpine tar czf /backup/uploads-$(date +%Y%m%d).tar.gz -C /source .
```

#### 恢复数据

```bash
# 恢复 storage
docker run --rm \
  -v siyuan-plugin-share_storage-data:/target \
  -v $(pwd)/backups:/backup \
  alpine sh -c "cd /target && tar xzf /backup/storage-20260114.tar.gz"

# 恢复 uploads
docker run --rm \
  -v siyuan-plugin-share_uploads-data:/target \
  -v $(pwd)/backups:/backup \
  alpine sh -c "cd /target && tar xzf /backup/uploads-20260114.tar.gz"
```

### 4. 迁移现有数据 (如果有)

如果你之前使用 bind mount 并且已有数据:

```bash
# 1. 停止服务
docker-compose down

# 2. 备份现有数据
cp -r php-site/storage backups/storage-old
cp -r php-site/uploads backups/uploads-old

# 3. 使用新配置启动
docker-compose -f docker-compose.nas.yml up -d

# 4. 复制数据到 volume
docker run --rm \
  -v siyuan-plugin-share_storage-data:/target \
  -v $(pwd)/backups:/backup \
  alpine sh -c "cp -r /backup/storage-old/* /target/"

docker run --rm \
  -v siyuan-plugin-share_uploads-data:/target \
  -v $(pwd)/backups:/backup \
  alpine sh -c "cp -r /backup/uploads-old/* /target/"

# 5. 重启服务
docker-compose -f docker-compose.nas.yml restart
```

### 5. 定期备份设置

在 NAS 上设置定时任务:

**群晖 NAS**:
1. 控制面板 → 任务计划
2. 新增 → 自定义脚本
3. 设置每天运行
4. 脚本内容: `cd /volume1/docker/siyuan-plugin-share && ./backup-volumes.sh`

**QNAP**:
1. 控制台 → 系统 → 排程
2. 添加任务
3. 脚本: `cd /share/Container/siyuan-plugin-share && ./backup-volumes.sh`

## OCR 启用（可选，网盘全文索引高精度识别）

网盘全文索引默认使用镜像内置的 `tesseract`（开箱即用，无需额外配置）。如需更高精度的中文 OCR（PaddleX serving），按以下步骤启用：

### 1. 打开 web 侧开关

编辑 `docker-compose.nas.yml`（或 `docker-compose.yml`），取消 `web` 服务中这两行的注释：

```yaml
      - NETDISK_OCR_SIDECAR=1
      - NETDISK_OCR_SIDECAR_URL=http://ocr:8080
```

> 注释掉（关闭）即自动回退 tesseract，功能不中断、精度略低。

### 2. 按需调整 ocr 服务资源参数

`ocr` 服务段支持以下环境变量（都有默认值，可在 `.env` 或 compose 里覆盖）：

| 变量 | 默认 | 说明 |
|---|---|---|
| `OCR_ENGINE` | `onnxruntime` | 推理引擎，`onnxruntime` CPU 内存占用更低；可选 `paddle` |
| `OCR_DEVICE` | `cpu` | 推理设备，GPU 填 `gpu:0` |
| `OCR_PORT` | `8080` | 监听端口，与 web 侧 `NETDISK_OCR_SIDECAR_URL` 保持一致 |
| `OCR_PROFILE` | `low` | `low`=2 线程+关内存竞技场（防低配 OOM）；`high`=4 线程吞吐优先 |
| `OCR_THREADS` | （空） | 显式线程数，优先生效；`0`=引擎默认 |
| `OCR_PIP_INDEX` | （空） | pip 国内镜像源，如 `https://pypi.tuna.tsinghua.edu.cn/simple` |
| `OCR_MEM_LIMIT` | `1500m` | 容器内存上限 |
| `OCR_MEM_RESERVATION` | `768m` | 内存软限制 |
| `OCR_CPUS` | `1.0` | CPU 上限 |

各档建议值：

| 机器规格 | 建议配置 |
|---|---|
| 2GB 内存 | `OCR_PROFILE=low`、`OCR_MEM_LIMIT=1500m`、`OCR_MEM_RESERVATION=768m`、`OCR_CPUS=1.0` |
| 4GB 内存 | `OCR_PROFILE=low`、`OCR_MEM_LIMIT=2500m`、`OCR_MEM_RESERVATION=1g`、`OCR_CPUS=1.5` |
| 8GB+ 内存 | `OCR_PROFILE=high`、`OCR_MEM_LIMIT=4g`、`OCR_MEM_RESERVATION=1500m`、`OCR_CPUS=2.0` |

### 3. 启动

```bash
docker compose -f docker-compose.nas.yml up -d
# 观察首次安装/建模进度（首次约 10-30 分钟，视网速）
docker compose -f docker-compose.nas.yml logs -f ocr
```

首次启动 `init.sh` 会：安装 paddlex/onnxruntime → 下载官方 OCR 模型 → 按 `OCR_ENGINE` 转换并缓存到模型卷（NAS 版为 named volume `ocr-models`）→ 拉起 serving。重建容器不会重复下载。

### 4. 验证

```bash
# 健康探测（PHP 侧同款检查）
docker compose -f docker-compose.nas.yml exec web curl -sf http://ocr:8080/openapi.json | head -c 100
```

返回 JSON 即 sidecar 存活；web 端在管理后台触发「重建索引」后，扫描件/图片应能搜到内容。若 ocr 容器不可用，web 自动回退 tesseract，不影响使用。

## 快速部署步骤

```bash
# 1. 从仓库拉取源码。如果不使用 Git，也可以直接下载仓库的压缩包，解压后放到当前目录，效果是一样的。
git clone https://github.com/804260247/siyuan-plugin-share.git

# 2. 准备配置文件(必须复制)
cd siyuan-plugin-share
cp php-site/config.example.php php-site/config.php

# 3. 使用 NAS 配置启动
docker-compose -f docker-compose.nas.yml up -d

# 4. 可选：查看日志
docker-compose -f docker-compose.nas.yml logs -f

# 5. 打开浏览器并访问
#http://your-nas-ip:8080

# 6. 初始账号密码
#管理员账号密码默认为admin/123456 登录后尽快修改密码
```

## 更新应用

更新前建议先备份（见“数据管理”里的备份脚本/命令），避免意外。

### 更新仓库代码后重启容器

```bash
# 1. 使用 Git 拉取最新源码；
# 如果不使用 Git，也可以直接下载仓库的最新压缩包，解压后覆盖当前目录中的源码。
#
# 如果拉取时提示冲突（conflict），说明：
# 你本地修改过的文件，刚好也在云端被更新了。
# 为了防止你的修改被直接覆盖，Git 会要求你先确认如何处理。
#
# 这是正常现象，不是报错。
# 根据提示处理完成后，再次执行 git pull 即可。
git pull

# 2. 重新构建镜像并启动容器（named volumes 会保留数据）
docker-compose -f docker-compose.nas.yml up -d --build

# 3. 可选：查看日志
docker-compose -f docker-compose.nas.yml logs -f
```

> 说明：使用 named volumes（storage-data / uploads-data）时，更新不会影响已有数据。

## 故障排查

### 如果还是有权限问题

先按下面步骤排查：

```bash
# 1) 查看容器日志
docker-compose -f docker-compose.nas.yml logs --tail=200 web

# 2) 进入容器检查目录是否可写
docker-compose -f docker-compose.nas.yml exec web sh -lc "id && ls -ld /var/www/html/storage /var/www/html/uploads"
docker-compose -f docker-compose.nas.yml exec web sh -lc "touch /var/www/html/storage/.rw && rm /var/www/html/storage/.rw && echo storage-ok"
docker-compose -f docker-compose.nas.yml exec web sh -lc "touch /var/www/html/uploads/.rw && rm /var/www/html/uploads/.rw && echo uploads-ok"

# 3) 确认 volume 挂载状态
docker volume inspect siyuan-plugin-share_storage-data
docker volume inspect siyuan-plugin-share_uploads-data
```

如果仍有问题，建议先备份后重建 volume 再恢复数据。

### 检查 Docker 版本

```bash
docker --version
docker-compose --version
```

确保 Docker 版本 >= 20.10

### 查看 volume 驱动

```bash
docker volume inspect siyuan-plugin-share_storage-data | grep Driver
```

应该显示 `"Driver": "local"`
