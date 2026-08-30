<?php
declare(strict_types=1);

/**
 * BasketAnalysisService — Stage 4D.
 *
 * PHP's job: query real completed orders (via `orders`/`order_items`,
 * `orders.status = 'completed'` — the exact same completed-order
 * definition OrderService/AnalyticsService already use elsewhere, so
 * cancelled/pending orders are excluded automatically, not by a special
 * rule invented here), build one "basket" (list of distinct product IDs)
 * per order, hand that to python/basket_analysis.py as JSON over STDIN,
 * and read the JSON result back over STDOUT. See that script's docstring
 * for why this integration shape (JSON stdin/stdout, no DB access from
 * Python, no argv) was chosen.
 *
 * Nothing here is AI/ML — it's a database query plus a call to a small,
 * auditable Python script that does arithmetic on the exact data it was given.
 */
final class BasketAnalysisService
{
    private const PYTHON_SCRIPT = __DIR__ . '/../python/basket_analysis.py';

    /**
     * @param array $filters date_from, date_to (both required by caller —
     *   the UI page supplies sane defaults), category_id (optional — when
     *   set, restricts to baskets built from THIS CATEGORY'S items only,
     *   i.e. a "basket" becomes "which of this category's products were
     *   bought together", not "all items in orders that happened to also
     *   contain this category").
     * @throws RuntimeException on anything that should show a clear,
     *   user-facing message (no Python found, process couldn't start,
     *   script failed, malformed output) — see class docblock on why this
     *   is expected to happen sometimes (missing Python on a shared host)
     *   and must fail gracefully rather than crash the page.
     */
    public static function run(array $filters, float $minSupport, float $minConfidence, float $minLift, int $maxResults): array
    {
        $minSupport = min(max($minSupport, 0.0), 1.0);
        $minConfidence = min(max($minConfidence, 0.0), 1.0);
        $minLift = max($minLift, 0.0);
        $maxResults = min(max($maxResults, 1), 200);

        $transactions = self::buildBaskets($filters);
        $totalTransactions = count($transactions);

        if ($totalTransactions === 0) {
            return ['status' => 'empty', 'total_transactions' => 0, 'pairs' => []];
        }

        $payload = json_encode([
            'transactions' => array_values($transactions),
            'min_support' => $minSupport,
            'min_confidence' => $minConfidence,
            'min_lift' => $minLift,
            'max_results' => $maxResults,
        ]);
        if ($payload === false) {
            throw new RuntimeException('Could not prepare transaction data for analysis.');
        }

        $result = self::invokePython($payload);

        // Resolve product info + inventory context for every product that appears in a result pair.
        $productIds = [];
        foreach ($result['pairs'] as $pair) {
            $productIds[] = $pair['product_a'];
            $productIds[] = $pair['product_b'];
        }
        $productIds = array_values(array_unique($productIds));

        $productInfo = self::productInfoWithContext($productIds, $filters);

        $enrichedPairs = [];
        foreach ($result['pairs'] as $pair) {
            $infoA = $productInfo[$pair['product_a']] ?? null;
            $infoB = $productInfo[$pair['product_b']] ?? null;
            // A product could have been deleted/archived since the order was placed —
            // skip rather than show a broken row (never fabricate missing product info).
            if (!$infoA || !$infoB) {
                continue;
            }
            $pair['product_a_info'] = $infoA;
            $pair['product_b_info'] = $infoB;
            $pair['recommendation'] = self::recommend((float) $pair['lift'], max((float) $pair['confidence_a_to_b'], (float) $pair['confidence_b_to_a']), $minConfidence);
            $enrichedPairs[] = $pair;
        }

        $result['pairs'] = $enrichedPairs;
        return $result;
    }

    /** @return array<int, array<int>> one array of distinct product IDs per order. */
    private static function buildBaskets(array $filters): array
    {
        $where = ["o.status = 'completed'"];
        $params = [];

        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(o.created_at) >= :date_from';
            $params['date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(o.created_at) <= :date_to';
            $params['date_to'] = $filters['date_to'];
        }
        if (!empty($filters['category_id'])) {
            $where[] = 'p.category_id = :category_id';
            $params['category_id'] = (int) $filters['category_id'];
        }
        $whereSql = implode(' AND ', $where);

        // DISTINCT protects against duplicate order_items rows for the same product
        // (shouldn't happen post-Stage-3, but a basket must never double-count).
        $stmt = db()->prepare(
            "SELECT DISTINCT oi.order_id, oi.product_id
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             JOIN products p ON p.id = oi.product_id
             WHERE $whereSql"
        );
        $stmt->execute($params);

        $baskets = [];
        foreach ($stmt->fetchAll() as $row) {
            $baskets[(int) $row['order_id']][] = (int) $row['product_id'];
        }

        return $baskets;
    }

    private static function invokePython(string $jsonPayload): array
    {
        if (!function_exists('proc_open')) {
            throw new RuntimeException(
                'This server does not allow running external processes (proc_open is disabled in php.ini). '
                . 'Basket analysis cannot run here — enable proc_open, or run python/basket_analysis.py manually.'
            );
        }

        $detected = self::detectPython();
        if (!$detected) {
            throw new RuntimeException(self::pythonNotFoundMessage());
        }

        $cmd = array_merge([$detected['bin']], self::candidatePrefixArgs($detected['bin']), [self::PYTHON_SCRIPT]);

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($cmd, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException(
                "Python was detected ({$detected['bin']}, {$detected['version']}) but the analysis process could not "
                . 'be started. This is usually a file-permission issue for the account Apache/PHP runs as.'
            );
        }

        fwrite($pipes[0], $jsonPayload);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $message = self::firstLine($stderr) ?: self::firstLine($stdout) ?: 'Unknown error.';
            throw new RuntimeException("The Python script ({$detected['bin']}) exited with an error: " . $message);
        }

        $result = json_decode($stdout, true);
        if (!is_array($result) || ($result['status'] ?? '') !== 'ok') {
            $message = is_array($result) ? ($result['message'] ?? 'Unexpected output.') : 'Unreadable output from the analysis script.';
            throw new RuntimeException('Basket analysis returned an unexpected result: ' . $message);
        }

        return $result;
    }

    /**
     * Stage-by-stage self-test, run on demand (never automatically), that
     * distinguishes exactly where the PHP<->Python pipeline breaks:
     *   1. Can PHP execute the configured/detected Python path at all?
     *   2. Does basket_analysis.py itself run without error?
     *   3. Can PHP parse valid JSON back out of it?
     * Uses a trivial, harmless payload (an empty transaction list) — this
     * never touches the database and never runs the real analysis.
     *
     * @return array{
     *   proc_open_available: bool, configured_path: string,
     *   binary_found: bool, binary_path: ?string, binary_version: ?string,
     *   script_executed: bool, script_exit_code: ?int, script_stderr: ?string,
     *   json_valid: bool, stage_failed: ?string, message: string
     * }
     */
    public static function diagnose(): array
    {
        $report = [
            'proc_open_available' => function_exists('proc_open'),
            'configured_path' => (defined('PYTHON_BIN') ? PYTHON_BIN : ''),
            'binary_found' => false,
            'binary_path' => null,
            'binary_version' => null,
            'script_executed' => false,
            'script_exit_code' => null,
            'script_stderr' => null,
            'json_valid' => false,
            'stage_failed' => null,
            'message' => '',
        ];

        if (!$report['proc_open_available']) {
            $report['stage_failed'] = 'proc_open';
            $report['message'] = 'proc_open() is disabled in php.ini — nothing else can be tested until this is enabled.';
            return $report;
        }

        // Stage 1: can PHP execute the configured/detected Python at all?
        $detected = self::detectPython();
        if (!$detected) {
            $report['stage_failed'] = 'binary';
            $report['message'] = 'PHP could not execute the configured or auto-detected Python path. ' . self::pythonNotFoundMessage();
            return $report;
        }
        $report['binary_found'] = true;
        $report['binary_path'] = $detected['bin'];
        $report['binary_version'] = $detected['version'];

        // Stage 2 + 3: run the real script with a harmless empty-transactions
        // payload — exercises the exact same stdin/stdout path as a real run.
        $cmd = array_merge([$detected['bin']], self::candidatePrefixArgs($detected['bin']), [self::PYTHON_SCRIPT]);
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($cmd, $descriptors, $pipes);

        if (!is_resource($process)) {
            $report['stage_failed'] = 'binary';
            $report['message'] = "Python was detected ({$detected['bin']}, {$detected['version']}) but PHP could not start the process — likely a file-permission issue for the Apache/PHP service account.";
            return $report;
        }

        $testPayload = json_encode([
            'transactions' => [], 'min_support' => 0.01, 'min_confidence' => 0.1, 'min_lift' => 1.0, 'max_results' => 10,
        ]);
        fwrite($pipes[0], $testPayload);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $report['script_executed'] = true;
        $report['script_exit_code'] = $exitCode;
        $report['script_stderr'] = trim($stderr) ?: null;

        if ($exitCode !== 0) {
            $report['stage_failed'] = 'script';
            $report['message'] = "Python started successfully but basket_analysis.py exited with an error: " . (self::firstLine($stderr) ?: self::firstLine($stdout) ?: 'unknown error');
            return $report;
        }

        $result = json_decode($stdout, true);
        if (!is_array($result) || ($result['status'] ?? '') !== 'ok') {
            $report['stage_failed'] = 'json';
            $report['message'] = 'The script ran and exited successfully (exit code 0), but PHP could not read valid JSON from its output. Raw output: ' . self::firstLine($stdout);
            return $report;
        }

        $report['json_valid'] = true;
        $report['stage_failed'] = null;
        $report['message'] = "All checks passed. Python {$detected['version']} at \"{$detected['bin']}\" successfully ran basket_analysis.py and returned valid JSON.";
        return $report;
    }

    /**
     * Finds a Python 3 interpreter that actually works — verified by running
     * `--version` (or `-3 --version` for the `py` launcher) and checking the
     * exit code AND the output text, not just assuming a found binary is real.
     * This second check specifically catches the Windows Store "python.exe"
     * alias, which can exist on PATH, sometimes even exit 0, and still print
     * an install-nagging message instead of a real version string.
     *
     * Order (first working candidate wins):
     *   1. PYTHON_BIN constant/env var, if explicitly configured (config/constants.php)
     *   2. OS-appropriate auto-detected commands from PATH:
     *      Windows: 'py' (the official launcher — most reliable on Windows,
     *               since 'python'/'python3' are the names Microsoft's Store
     *               alias also squats on), then 'python', then 'python3'.
     *      Other:   'python3' (standard on Linux/macOS), then 'python'.
     *
     * @return array{bin: string, version: string}|null
     */
    public static function detectPython(): ?array
    {
        $candidates = [];

        if (defined('PYTHON_BIN') && PYTHON_BIN !== '') {
            $candidates[] = PYTHON_BIN;
        }

        $isWindows = stripos(PHP_OS, 'WIN') === 0;
        $candidates = array_merge($candidates, $isWindows ? ['py', 'python', 'python3'] : ['python3', 'python']);

        foreach (array_unique($candidates) as $bin) {
            $version = self::testBinary($bin);
            if ($version !== null) {
                return ['bin' => $bin, 'version' => $version];
            }
        }

        return null;
    }

    /**
     * The Windows `py` launcher needs a `-3` argument to select Python 3 (it
     * is not a plain "executable + script" invocation like the others) —
     * detected uniformly by filename so this works whether 'py' came from
     * auto-detection or from an explicit PYTHON_BIN pointing at a py.exe path.
     */
    private static function candidatePrefixArgs(string $bin): array
    {
        $base = strtolower(pathinfo($bin, PATHINFO_FILENAME));
        return $base === 'py' ? ['-3'] : [];
    }

    /**
     * Runs `<bin> [prefix-args] --version` via array-form proc_open (no shell
     * interpolation) and returns the version string only if the process
     * actually succeeded AND produced real Python version output — never
     * just "did a process start."
     */
    private static function testBinary(string $bin): ?string
    {
        if (!function_exists('proc_open')) {
            return null;
        }
        if (trim($bin) === '') {
            return null;
        }

        $cmd = array_merge([$bin], self::candidatePrefixArgs($bin), ['--version']);
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            return null;
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            return null;
        }

        // Python 3.4- printed the version to stderr; modern Python 3 prints to
        // stdout. Check both so we don't depend on which stream it used.
        $output = trim($stdout) !== '' ? trim($stdout) : trim($stderr);

        if ($output === '') {
            return null;
        }
        // Reject the Windows Store alias stub and any other non-interpreter
        // output explicitly, even if it somehow exited 0.
        if (stripos($output, 'Microsoft Store') !== false || stripos($output, 'was not found') !== false) {
            return null;
        }
        if (stripos($output, 'Python') === false) {
            return null; // a real interpreter's --version always starts with "Python "
        }

        return $output;
    }

    private static function pythonNotFoundMessage(): string
    {
        return 'No working Python 3 interpreter could be found. On Windows/XAMPP this is almost always because '
            . 'Apache runs with its own environment and PATH, separate from your normal Command Prompt — so '
            . 'Python can work perfectly when you type "python" yourself and still be invisible to this app. '
            . 'It can also mean only the Microsoft Store "python.exe" alias is present, which is not a real '
            . "interpreter and is deliberately rejected here. Fix: find your real python.exe path (in Command "
            . 'Prompt, try "where python" or "py -3 -c \"import sys; print(sys.executable)\""), then either set it '
            . 'as a PYTHON_BIN environment variable for Apache (in XAMPP: edit apache/conf/extra/httpd-xampp.conf '
            . 'and add SetEnv PYTHON_BIN "C:\\full\\path\\to\\python.exe"), or open config/constants.php and set '
            . "define('PYTHON_BIN', 'C:\\\\full\\\\path\\\\to\\\\python.exe'); directly — then restart Apache.";
    }

    private static function firstLine(string $s): string
    {
        $s = trim($s);
        if ($s === '') return '';
        return explode("\n", $s)[0];
    }

    /**
     * Product display info + inventory context (Stage 4D section 11) for the
     * given product IDs, reusing AnalyticsService::stockAnalysis()'s
     * already-tested calculations rather than duplicating them.
     */
    private static function productInfoWithContext(array $productIds, array $filters): array
    {
        if (!$productIds) return [];

        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $stmt = db()->prepare(
            "SELECT p.id, p.sku, p.name, p.selling_price, c.name AS category_name
             FROM products p LEFT JOIN categories c ON c.id = p.category_id
             WHERE p.id IN ($placeholders)"
        );
        $stmt->execute($productIds);
        $basic = [];
        foreach ($stmt->fetchAll() as $row) {
            $basic[(int) $row['id']] = $row;
        }

        $stockRows = AnalyticsService::stockAnalysis(
            ['date_from' => $filters['date_from'] ?? date('Y-m-d', strtotime('-29 days')), 'date_to' => $filters['date_to'] ?? date('Y-m-d')],
            $productIds
        );
        $stockByProduct = [];
        foreach ($stockRows as $r) {
            $stockByProduct[$r['product_id']] = $r;
        }

        $expiration = [];
        $expStmt = db()->prepare("SELECT id, expiration_date FROM products WHERE id IN ($placeholders)");
        $expStmt->execute($productIds);
        foreach ($expStmt->fetchAll() as $row) {
            $expiration[(int) $row['id']] = InventoryService::expirationStatus($row['expiration_date']);
        }

        $info = [];
        foreach ($productIds as $pid) {
            if (!isset($basic[$pid])) continue; // product no longer exists
            $stock = $stockByProduct[$pid] ?? null;
            $info[$pid] = [
                'id' => $pid,
                'sku' => $basic[$pid]['sku'],
                'name' => $basic[$pid]['name'],
                'category_name' => $basic[$pid]['category_name'] ?? '—',
                'selling_price' => (float) $basic[$pid]['selling_price'],
                'current_stock' => $stock['current_stock'] ?? 0,
                'stock_status' => $stock['stock_status'] ?? 'safe',
                'units_sold_period' => $stock['units_sold_period'] ?? 0,
                // Same fixed threshold as AnalyticsService::fastSlowMoving() (>2 units = Fast),
                // reapplied per-product here rather than re-deriving a different rule.
                'movement' => ($stock['units_sold_period'] ?? 0) > 2 ? 'Fast' : 'Slow',
                'estimated_days_remaining' => $stock['estimated_days_remaining'] ?? null,
                'expiration_status' => $expiration[$pid] ?? null,
            ];
        }

        return $info;
    }

    /**
     * Transparent, documented recommendation rule (Stage 4D section 10) — not
     * AI-generated. Thresholds are exactly the ones the Owner configured for
     * this run (min confidence) plus one fixed, stated cutoff (lift > 2 for
     * "Strong").
     */
    private static function recommend(float $lift, float $bestConfidence, float $minConfidence): string
    {
        if ($lift > 2.0 && $bestConfidence >= $minConfidence) {
            return 'Strong Bundle Candidate';
        }
        if ($lift > 1.0 && $bestConfidence >= $minConfidence) {
            return 'Potential Bundle';
        }
        return 'Weak Association';
    }
}
