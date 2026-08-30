<?php
require_once __DIR__ . '/../core/bootstrap.php';
requirePermission('analytics.view');

$report = BasketAnalysisService::diagnose();

$stageOrder = ['proc_open', 'binary', 'script', 'json'];
$stageLabels = [
    'proc_open' => 'PHP can run external processes (proc_open)',
    'binary' => 'PHP can execute the configured Python',
    'script' => 'basket_analysis.py runs without error',
    'json' => 'PHP can read valid JSON from the script',
];

$pageTitle = 'Python Integration Check';
$activeNav = 'analytics';
require __DIR__ . '/../components/header.php';
?>
<div class="page-header">
    <nav class="breadcrumb"><a href="<?= BASE_URL ?>/analytics/basket.php" class="text-decoration-none">Basket Analysis</a>&nbsp;/&nbsp;Python Check</nav>
    <h1 class="page-title h4 mb-0">Python Integration Check</h1>
    <p class="text-muted mb-0 small">Runs a harmless self-test (no database access) through the exact same PHP→Python pipeline Basket Analysis uses.</p>
</div>

<div class="ts-card p-4 mb-3">
    <div class="alert <?= $report['stage_failed'] === null ? 'alert-success' : 'alert-danger' ?> py-2 mb-4">
        <?= htmlspecialchars($report['message']) ?>
    </div>

    <table class="table table-sm align-middle mb-0">
        <thead><tr><th>Stage</th><th>Result</th></tr></thead>
        <tbody>
            <?php foreach ($stageOrder as $stage): ?>
                <?php
                    if ($report['stage_failed'] === null) {
                        $status = 'pass';
                    } elseif ($report['stage_failed'] === $stage) {
                        $status = 'fail';
                    } elseif (array_search($report['stage_failed'], $stageOrder) > array_search($stage, $stageOrder)) {
                        $status = 'pass';
                    } else {
                        $status = 'skipped';
                    }
                    $badge = ['pass' => 'badge-success', 'fail' => 'badge-danger', 'skipped' => 'badge-neutral'][$status];
                    $label = ['pass' => 'Pass', 'fail' => 'FAILED HERE', 'skipped' => 'Not reached'][$status];
                ?>
                <tr>
                    <td class="small"><?= htmlspecialchars($stageLabels[$stage]) ?></td>
                    <td><span class="badge-status <?= $badge ?>"><?= $label ?></span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="ts-card p-4">
    <h2 class="h6 mb-3">Raw Diagnostic Details</h2>
    <dl class="row small mb-0">
        <dt class="col-4 text-muted">Configured PYTHON_BIN</dt>
        <dd class="col-8"><?= $report['configured_path'] !== '' ? '<code>' . htmlspecialchars($report['configured_path']) . '</code>' : '<span class="text-muted">(not set — auto-detect only)</span>' ?></dd>

        <dt class="col-4 text-muted">Binary actually used</dt>
        <dd class="col-8"><?= $report['binary_path'] ? '<code>' . htmlspecialchars($report['binary_path']) . '</code>' : '—' ?></dd>

        <dt class="col-4 text-muted">Detected version</dt>
        <dd class="col-8"><?= $report['binary_version'] ? htmlspecialchars($report['binary_version']) : '—' ?></dd>

        <dt class="col-4 text-muted">Script exit code</dt>
        <dd class="col-8"><?= $report['script_exit_code'] !== null ? (int) $report['script_exit_code'] : '—' ?></dd>

        <dt class="col-4 text-muted">Script stderr</dt>
        <dd class="col-8"><?= $report['script_stderr'] ? '<code>' . htmlspecialchars($report['script_stderr']) . '</code>' : '<span class="text-muted">(empty)</span>' ?></dd>

        <dt class="col-4 text-muted">Valid JSON returned</dt>
        <dd class="col-8"><?= $report['json_valid'] ? 'Yes' : 'No' ?></dd>
    </dl>
</div>
<?php require __DIR__ . '/../components/footer.php'; ?>
