<?php
/**
 * Application-wide constants.
 */

declare(strict_types=1);

define('APP_NAME', 'Three Sisters\' Olshoppe');
define('APP_TAGLINE', 'Beauty Products Management System');

// BASE_URL auto-detects the app's install path so links work whether it's
// served from the domain root or a subfolder (e.g. /three-sisters/).
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$scriptDir = preg_replace('#/(auth|admin|owner|staff|distributor|products|inventory|pos|orders|customers|resellers|promotions|analytics|distributors|tiktok|reports|payments|receipts|notifications|audit|settings|api)(/.*)?$#', '', $scriptDir);
define('BASE_URL', rtrim($scriptDir, '/'));

// Roles — must match `roles.name` in the database exactly.
define('ROLE_ADMIN', 'admin');
define('ROLE_OWNER', 'owner');
define('ROLE_STAFF', 'staff');
define('ROLE_DISTRIBUTOR', 'distributor');

// Each role's landing dashboard, used after login.
const ROLE_DASHBOARDS = [
    ROLE_ADMIN       => '/admin/dashboard.php',
    ROLE_OWNER       => '/owner/dashboard.php',
    ROLE_STAFF       => '/staff/dashboard.php',
    ROLE_DISTRIBUTOR => '/distributor/dashboard.php',
];

// Upload rules for payment proofs (spec section 7 & 15).
define('UPLOAD_MAX_BYTES', 5 * 1024 * 1024); // 5MB
const UPLOAD_ALLOWED_MIME = ['image/jpeg', 'image/png', 'application/pdf'];

// Optional: explicit path to a Python 3 executable, used by Basket Analysis
// (core/BasketAnalysisService.php). Needed on hosts — notably Windows/XAMPP —
// where Apache's own PATH doesn't include Python even though a normal
// Command Prompt finds it fine (Apache runs as its own service/process
// with its own environment), or where only the Microsoft Store "python.exe"
// alias is registered (that alias is a stub, not a real interpreter, and
// is explicitly rejected even if auto-detected).
//
// Leave as '' to auto-detect 'py' (Windows launcher), 'python', then
// 'python3' from PATH. To set explicitly, EITHER:
//   1. Edit the line below directly, e.g.:
//      define('PYTHON_BIN', 'C:\\Users\\yourname\\AppData\\Local\\Programs\\Python\\Python312\\python.exe');
//   2. OR set an environment variable PYTHON_BIN instead (e.g. via
//      Apache's SetEnv in httpd-xampp.conf) and leave this file untouched —
//      the getenv() fallback below picks it up automatically.
// Either way, restart Apache after changing this.
if (!defined('PYTHON_BIN')) {
    define('PYTHON_BIN', getenv('PYTHON_BIN') ?: 'C:\\Users\\Fahryst Guille\\AppData\\Local\\Python\\pythoncore-3.14-64\\python.exe');
}

// Session
define('SESSION_LIFETIME_SECONDS', 60 * 60 * 8); // 8-hour work day

/**
 * Returns the URL for a front-end library asset — the local vendored copy
 * if it exists on disk, otherwise the given CDN URL. This is how the app
 * works fully offline once real vendor files are copied in (see
 * assets/vendor/README.md), while still working out-of-the-box via CDN
 * before that's done — a page never requests both, so nothing is loaded
 * twice.
 *
 * Server-side file_exists() (rather than a client-side onerror fallback
 * chain, as used for Chart.js) is used deliberately for these: CSS/JS
 * files that the rest of the page depends on synchronously (Bootstrap CSS
 * for layout, Bootstrap JS for dropdowns/modals that inline scripts wire
 * up immediately after) — a runtime fallback would mean briefly attempting
 * a CDN request even when fully offline, which is exactly what this is
 * trying to avoid.
 */
function vendorOrCdn(string $relativeVendorPath, string $cdnUrl): string
{
    $fsPath = __DIR__ . '/../assets/vendor/' . $relativeVendorPath;
    return file_exists($fsPath) ? (BASE_URL . '/assets/vendor/' . $relativeVendorPath) : $cdnUrl;
}
