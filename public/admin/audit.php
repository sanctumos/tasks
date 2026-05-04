<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/_helpers.php';

requireAdmin();

$limit = isset($_GET['limit']) ? max(10, min(500, (int)$_GET['limit'])) : 100;
$offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
$auditLogs = listAuditLogs($limit, $offset);

$pageTitle = 'Audit log';
require __DIR__ . '/_layout_top.php';
?>

<?= st_back_link('/admin/', 'Tasks') ?>

<div class="page-header">
    <div class="page-header__title">
        <h1>Audit log</h1>
        <div class="subtitle"><?= count($auditLogs) ?> entries · most recent first</div>
    </div>
    <div class="page-header__actions">
        <?php
            $prevOffset = max(0, $offset - $limit);
            $nextOffset = $offset + $limit;
            $hasPrev = $offset > 0;
            $hasNext = count($auditLogs) >= $limit;
        ?>
        <a class="btn btn-sm btn-outline-secondary <?= $hasPrev ? '' : 'disabled' ?>" href="?limit=<?= $limit ?>&amp;offset=<?= $prevOffset ?>"><i class="bi bi-chevron-left"></i> Newer</a>
        <a class="btn btn-sm btn-outline-secondary <?= $hasNext ? '' : 'disabled' ?>" href="?limit=<?= $limit ?>&amp;offset=<?= $nextOffset ?>">Older <i class="bi bi-chevron-right"></i></a>
    </div>
</div>

<div class="surface">
    <table class="task-table">
        <thead>
            <tr>
                <th style="width: 170px;">When (UTC)</th>
                <th style="width: 140px;">Actor</th>
                <th style="width: 180px;">Action</th>
                <th>Entity</th>
                <th style="width: 130px;">IP</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($auditLogs as $log): ?>
                <tr>
                    <td class="small text-muted"><?= htmlspecialchars($log['created_at']) ?></td>
                    <td><?= htmlspecialchars((string)($log['actor_username'] ?? 'system')) ?></td>
                    <td><span class="tag-chip"><i class="bi bi-tag me-1"></i><?= htmlspecialchars($log['action']) ?></span></td>
                    <td class="small"><?= htmlspecialchars($log['entity_type']) ?> <span class="text-muted"><?= htmlspecialchars((string)($log['entity_id'] ?? '')) ?></span></td>
                    <td class="small text-muted"><?= htmlspecialchars((string)($log['ip_address'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$auditLogs): ?>
                <tr><td colspan="5" class="text-muted text-center py-4">No audit log entries.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/_layout_bottom.php'; ?>
