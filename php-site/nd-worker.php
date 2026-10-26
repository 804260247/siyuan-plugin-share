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

function nd_worker_log(string $msg): void {
    @error_log('[nd-worker] ' . $msg);
}

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

nd_worker_log('worker started');

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
