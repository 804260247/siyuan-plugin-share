<?php
declare(strict_types=1);

// 后台索引重建 worker：由 supervisor 常驻运行，逐个处理 netdisk_index_jobs 中 pending 任务。
// 用法：php /var/www/html/nd-worker.php
// 通过 require index.php 复用全部网盘函数；index.php 在 CLI 下会提前 return（仅定义函数，不跑 web 路由）。

require_once __DIR__ . '/index.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "nd-worker must run under CLI\n");
    exit(1);
}

set_time_limit(0);
error_reporting(E_ALL & ~E_DEPRECATED);
// 0019: worker 为 CLI 单作业串行进程，解除 php.ini 的 1G 上限（真机三连崩根因：
// Allowed memory size of 1073741824 bytes exhausted）。安全边界不靠该上限，
// 靠 netdisk_ocr_memory_budget()：cgroup 上限 − 已用，再乘 60% 安全系数做解码预算守卫。
ini_set('memory_limit', '-1');

// 0019: 所有日志行加时间戳（supervisord 只给自己的行打时间，child 行没有——
// 排障时无法与 supervisord.log 的崩溃时刻对齐）。
function nd_worker_log(string $msg): void {
    @error_log(date('Y-m-d H:i:s') . ' [nd-worker] ' . $msg);
}

// 0019: Fatal 归因与崩溃循环保护：同一 job 连续 crash 2 次即标 failed，不再自动重拾
// （旧行为：fatal → supervisord 拉起 → 重拾同一 job → 同一文件再 fatal，无限循环）。
// 单独成函数供 shutdown 注册与桩测直调。
function nd_worker_handle_fatal(array $err): void {
    $peak = memory_get_peak_usage(true);
    $msg = sprintf('%s @%s:%d peak=%dMB', (string)($err['message'] ?? ''), (string)($err['file'] ?? ''), (int)($err['line'] ?? 0), (int)($peak / 1048576));
    nd_worker_log('FATAL ' . $msg);
    $jobId = (int)($GLOBALS['ND_WORKER_JOB'] ?? 0);
    if ($jobId <= 0 || !isset($GLOBALS['config']['db_path'])) {
        return;
    }
    // 独立连接：崩溃现场 db() 静态连接可能已不可用
    try {
        $pdo = new PDO('sqlite:' . $GLOBALS['config']['db_path']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA busy_timeout = 5000;');
        $crash = (int)$pdo->query('SELECT crash_count FROM netdisk_index_jobs WHERE id=' . $jobId)->fetchColumn() + 1;
        if ($crash >= 2) {
            $pdo->prepare('UPDATE netdisk_index_jobs SET status="failed", crash_count=:c, error=:e, finished_at=:t, heartbeat_at=:t WHERE id=:id')
                ->execute([':c' => $crash, ':e' => 'worker 连续崩溃 ' . $crash . ' 次，已停止自动重试：' . substr($msg, 0, 200), ':t' => time(), ':id' => $jobId]);
            nd_worker_log("job=$jobId crash=$crash (>=2) marked failed, stop auto-retry");
        } else {
            $pdo->prepare('UPDATE netdisk_index_jobs SET status="pending", crash_count=:c, started_at=0, finished_at=0, error=:e, heartbeat_at=:t WHERE id=:id')
                ->execute([':c' => $crash, ':e' => 'worker 崩溃已重置待重试（第 ' . $crash . ' 次，连续 2 次即放弃）：' . substr($msg, 0, 150), ':t' => time(), ':id' => $jobId]);
            nd_worker_log("job=$jobId crash=$crash reset to pending");
        }
    } catch (Throwable $e) {
        nd_worker_log('fatal handler db error: ' . $e->getMessage());
    }
}

register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    if (!in_array((int)$err['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE, E_USER_ERROR], true)) {
        return;
    }
    nd_worker_handle_fatal($err);
});

// 0019: 启动首行打内存三件套，确证运行期上限口径（memory_limit/cgroup 上限/当前用量）
$__ndMem = sprintf('memory_limit=%s cgroup_max=%d cgroup_current=%d usage=%d', (string)ini_get('memory_limit'), netdisk_ocr_cgroup_limit(), netdisk_ocr_cgroup_current(), memory_get_usage(true));

// 卡住的 running 任务超时回收（清理 worker 崩溃遗留的任务）。
// 0) 锁错误自愈：running 且 error 含“锁”且心跳超 180s → 重置为 pending（保留 attempts 计数），
//    worker 下个周期自动续跑，而非永久 failed。
// 1) 心跳超时：running 且 heartbeat_at 距今 >300s（worker 已死/卡死）→ 重置为 failed
// 2) 无心跳遗留：running 且 heartbeat_at=0 但 started_at 距今 >600s（旧代码/崩溃遗留）→ 重置
// 3) 兜底：started_at 距今 >24h 仍未完成 → 重置为 failed（真实大扫描件 OCR 可能跑数小时）
function nd_worker_reap_stuck(): void {
    try {
        $now = time();
        $lockCutoff = $now - 180;  // 锁冲突后 3 分钟无心跳 → 自动重置待重试
        $hbCutoff = $now - 300;   // 5 分钟无心跳视为 worker 中断
        $noHbStart = $now - 600;  // 无心跳且启动超 10 分钟视为遗留卡死
        $stCutoff = $now - 86400; // 24h 兜底
        db()->prepare('UPDATE netdisk_index_jobs SET status="pending", started_at=0, finished_at=0
            WHERE status="running" AND error LIKE "%锁%" AND heartbeat_at>0 AND heartbeat_at<:lock')
            ->execute([':lock' => $lockCutoff]);
        db()->prepare('UPDATE netdisk_index_jobs SET status="failed", error="worker 心跳超时，重建已中断，请重新触发", finished_at=:now
            WHERE status="running" AND heartbeat_at>0 AND heartbeat_at<:hb')
            ->execute([':now' => $now, ':hb' => $hbCutoff]);
        // pending 超时自愈：创建超 30 分钟却始终 pending（worker 死/未拾取），
        // 且该分享当前无 running job 在真正处理 → 标 failed 提示重新触发，避免无限"排队中"
        $pendingCutoff = $now - 21600; // 6 小时（大文件 OCR 需长时，避免误杀）
        db()->prepare('UPDATE netdisk_index_jobs SET status="failed", error="队列等待超时，重建未开始，请重新触发", finished_at=:now
            WHERE status="pending" AND created_at>0 AND created_at<:pc
              AND NOT EXISTS (SELECT 1 FROM netdisk_index_jobs AS j2 WHERE j2.share_id = netdisk_index_jobs.share_id AND j2.status="running")')
            ->execute([':now' => $now, ':pc' => $pendingCutoff]);
        db()->prepare('UPDATE netdisk_index_jobs SET status="failed", error="worker 中断遗留，重建已重置，请重新触发", finished_at=:now
            WHERE status="running" AND heartbeat_at=0 AND started_at>0 AND started_at<:ns')
            ->execute([':now' => $now, ':ns' => $noHbStart]);
        db()->prepare('UPDATE netdisk_index_jobs SET status="failed", error="超时未完成，已重置", finished_at=:now
            WHERE status="running" AND started_at>0 AND started_at<:st')
            ->execute([':now' => $now, ':st' => $stCutoff]);
    } catch (Throwable $e) {
        // 瞬时锁/写冲突：跳过本轮，下轮重试
    }
}

nd_worker_log('worker started ' . $__ndMem);

while (true) {
    try {
        nd_worker_reap_stuck();
        // 存量失败项自动重试：下载失败且 attempts<3 → 重置 pending 继续
        try {
            db()->prepare('UPDATE netdisk_ocr_queue SET status="pending", started_at=0, error=""
                WHERE status="failed" AND error="下载失败" AND attempts<3')->execute();
        } catch (Throwable $e) {}

        $stmt = db()->query('SELECT id, share_id, force_ocr FROM netdisk_index_jobs WHERE status = "pending" ORDER BY id ASC LIMIT 1');
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor(); // 释放 db() 连接的活动语句，避免后续 job_update 同连接 bad parameter

        if (!$job) {
            // 无重建任务：检查低优先级 OCR 队列，空闲时处理一批（时间盒 120s）。
            // 不在此用全局 OCR 开关拦截——批处理内部按各项 config_id 的连接级配置处理。
            $pendingOcr = (int)db()->query('SELECT COUNT(*) FROM netdisk_ocr_queue WHERE status="pending"')->fetchColumn();
            if ($pendingOcr > 0) {
                nd_worker_log("idle: processing low-priority OCR queue, pending=$pendingOcr");
                try {
                    $doneOcr = netdisk_process_ocr_queue_batch(120);
                    nd_worker_log("idle: ocr queue batch done=$doneOcr");
                } catch (Throwable $e) {
                    nd_worker_log("idle: ocr queue batch error: " . $e->getMessage());
                }
                // 不要立即 exit：回到主循环重新查询 pending 索引 job，
                // 避免低优 OCR 队列堆积时索引重建永远排不上（此前每批 exit+重启，OCR 富时重建一直轮不到拾取）。
                // 若批处理因 pending 索引 job 出现而让位，本循环下轮就能拾取它。
                sleep(2);
                continue;
            }
            sleep(5);
            continue;
        }

        $jobId = (int)$job['id'];
        $shareId = (int)$job['share_id'];
        $forceOcr = (int)($job['force_ocr'] ?? 0) === 1; // 0018: 强制重跑 OCR（重建入口勾选）
        nd_worker_log("start job=$jobId share=$shareId" . ($forceOcr ? " force_ocr=1" : ""));
        $GLOBALS['ND_WORKER_JOB'] = $jobId; // 0019: fatal 归因用——shutdown handler 据此落 job 行

        // 抢占（幂等：仅当仍为 pending 时置 running）
        $up = db()->prepare('UPDATE netdisk_index_jobs SET status="running", started_at=:t WHERE id=:id AND status="pending"');
        $up->execute([':t' => time(), ':id' => $jobId]);
        if ($up->rowCount() === 0) {
            nd_worker_log("job=$jobId taken by another worker, skip");
            continue;
        }

        $share = db()->prepare('SELECT * FROM shares WHERE id=:id AND deleted_at IS NULL');
        $share->execute([':id' => $shareId]);
        $shareRow = $share->fetch(PDO::FETCH_ASSOC);
        $share->closeCursor(); // 释放 db() 连接活动语句，避免与 build 的独立连接写并发冲突
        if (!$shareRow) {
            netdisk_index_job_update($jobId, ['status' => 'failed', 'error' => '分享不存在或已删除', 'finished_at' => time()]);
            continue;
        }

        $base = netdisk_share_config($shareRow);
        if (!$base['enabled'] || !$base['cfg']) {
            netdisk_index_job_update($jobId, ['status' => 'failed', 'error' => '分享未启用网盘', 'finished_at' => time()]);
            continue;
        }

        $root = netdisk_normalize_root($base['root']);
        $allowOcr = netdisk_index_ocr_enabled((int)($base['config_id'] ?? 0));

        $res = netdisk_build_index_share($shareRow, $base, $root, $allowOcr, $jobId, $forceOcr);
        nd_worker_log("done job=$jobId files=" . $res['files'] . " folders=" . $res['folders']
            . " texts=" . $res['texts'] . " errors=" . $res['errors'] . " skipped=" . $res['skipped']
            . " ocrDone=" . $res['ocr_done'] . " ocrQueued=" . $res['ocr_queued']);
        // 0019: 成功完成 → 崩溃循环计数清零（下次偶发 fatal 仍有一次自动重试机会）
        try {
            netdisk_index_job_update($jobId, ['crash_count' => 0]);
        } catch (Throwable $ign) {
        }
        $GLOBALS['ND_WORKER_JOB'] = 0;
    } catch (Throwable $e) {
        nd_worker_log("loop error: " . $e->getMessage());
        nd_worker_log("trace: " . str_replace("\n", " | ", $e->getTraceAsString()));
        if (isset($jobId) && $jobId > 0) {
            $msg = (string)$e->getMessage();
            // 瞬时 SQLite 锁错误（database is locked / General error: 5）：不标 failed，
            // 保留 running 交给下个 worker 周期重试（否则索引一次锁冲突就永久失败）。
            if (stripos($msg, 'database is locked') !== false || stripos($msg, 'General error: 5') !== false) {
                // 锁冲突：记录重试次数。≥5 次仍失败 → 标 failed；否则重置为 pending，
                // worker 重启后主循环会重新拾取，从当前进度续跑。
                $att = 0;
                try {
                    $att = (int)db()->query('SELECT attempts FROM netdisk_index_jobs WHERE id=' . (int)$jobId)->fetchColumn();
                } catch (Throwable $ignored) {
                    // 查询失败按 0 处理，仍走重试分支
                }
                $att++;
                if ($att >= 5) {
                    nd_worker_log("job=$jobId lock-conflict attempts=$att, marking failed");
                    netdisk_index_job_update($jobId, [
                        'status' => 'failed',
                        'error' => '数据库锁冲突超过 5 次，重建中止，请稍后重新触发',
                        'finished_at' => time(),
                        'attempts' => $att,
                    ]);
                    exit(0);
                }
                nd_worker_log("job=$jobId lock-conflict attempts=$att, reset to pending, wait 60s");
                netdisk_index_job_update($jobId, [
                    'status' => 'pending',
                    'started_at' => 0,
                    'finished_at' => 0,
                    'heartbeat_at' => time(),
                    'error' => '数据库锁冲突，已重置待重试（第' . $att . '次，上限 5 次）',
                    'current_detail' => '数据库锁，稍后自动重试',
                    'attempts' => $att,
                ]);
                // 等待锁释放后再重启 worker，避免快速重启加剧锁竞争
                sleep(60);
                exit(0);
            }
            netdisk_index_job_update($jobId, [
                'status' => 'failed',
                'error' => substr($msg, 0, 300),
                'finished_at' => time(),
            ]);
        }
    }

    // 每处理一个任务后退出，让 supervisor 重启进程。
    // 这样每次任务都使用全新的数据库连接与内存状态，避免长驻进程
    // 因 WAL/SQLite 连接陈旧导致后续 pending 任务不被拾取。
    if (isset($jobId) && $jobId > 0) {
        exit(0);
    }

    // 无任务时短暂停顿再轮询
    sleep(5);
}
