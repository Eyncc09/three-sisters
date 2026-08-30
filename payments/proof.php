<?php
require_once __DIR__ . '/../core/bootstrap.php';
requirePermission('payments.verify');

$paymentId = (int) ($_GET['payment_id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM payment_proofs WHERE payment_id = :pid ORDER BY id DESC LIMIT 1');
$stmt->execute(['pid' => $paymentId]);
$proof = $stmt->fetch();

if (!$proof) {
    http_response_code(404);
    exit('Not found.');
}

$fullPath = __DIR__ . '/../uploads/' . $proof['file_path'];
$realBase = realpath(__DIR__ . '/../uploads/payment_proofs');
$realPath = realpath($fullPath);

// Defensive path check — never stream a file outside the payment_proofs dir.
if (!$realPath || !$realBase || strpos($realPath, $realBase) !== 0) {
    http_response_code(404);
    exit('Not found.');
}

AuditLogger::log((int) $_SESSION['user_id'], 'payment.proof_viewed', 'payments', 'payments', $paymentId);

header('Content-Type: ' . $proof['mime_type']);
header('Content-Length: ' . filesize($realPath));
header('Content-Disposition: inline; filename="proof-' . $paymentId . '"');
header('X-Content-Type-Options: nosniff');
readfile($realPath);
exit;
