<?php
declare(strict_types=1);

/**
 * Validates and stores payment-proof uploads (spec section 7/15):
 * whitelisted MIME types, size cap, randomized filename, stored outside
 * any directly-guessable path. Files are served back only through
 * payments/proof.php, which is permission-gated — never linked directly.
 */
final class UploadHelper
{
    /**
     * @return array{path: string, mime: string, size: int}|null  null if no file was submitted.
     * @throws RuntimeException on validation failure (safe message for the user).
     */
    public static function handlePaymentProof(?array $file): ?array
    {
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('The uploaded file could not be processed. Please try again.');
        }
        if ($file['size'] > UPLOAD_MAX_BYTES) {
            throw new RuntimeException('Proof of payment file is too large (max 5MB).');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, UPLOAD_ALLOWED_MIME, true)) {
            throw new RuntimeException('Proof of payment must be a JPG, PNG, or PDF file.');
        }

        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'application/pdf' => 'pdf',
            default => 'bin',
        };

        $dir = __DIR__ . '/../uploads/payment_proofs';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        $destination = $dir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new RuntimeException('Could not save the uploaded file. Please try again.');
        }

        return [
            'path' => 'payment_proofs/' . $filename, // relative — resolved by payments/proof.php
            'mime' => $mime,
            'size' => (int) $file['size'],
        ];
    }
}
