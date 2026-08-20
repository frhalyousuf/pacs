<?php
// ============================================================
//  Configuration
// ============================================================
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'u136620672_VpD3pAX8u_dicomviewer');
define('DB_USER', 'u136620672_VpD3pAX8u_dicomviewer');
define('DB_PASS', 'b^B2!$&TEO');
define('DB_CHARSET', 'utf8mb4');

define('PACS_INGEST_SECRET', 'Kx7mQ2vP9wR4tY6nB1zL8sJ3fH5aD0cE');
define('PACS_DEFAULT_DOCTOR_ID', 13); // set to the real doctor ID once you create the "PACS Auto-Intake" account

define('UPLOAD_DIR', __DIR__ . '/storage/dicom/');
define('UPLOAD_URL',  '/storage/dicom/');
define('MAX_FILE_SIZE', 200 * 1024 * 1024);   // 200 MB per file
define('ALLOWED_EXT', ['dcm', 'dicom', 'ima', '']);

define('APP_NAME',    'DICOM Viewer Pro');
define('APP_VERSION', '1.0.0');
define('APP_URL', 'https://4f-medical.com/dicom-viewer');
define('SHARE_SECRET', 'PUT_A_LONG_RANDOM_SECRET_HERE');
define('TIMEZONE',    'UTC');

date_default_timezone_set(TIMEZONE);

// ----------------------------------------------------------
//  PDO connection (singleton)
// ----------------------------------------------------------
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );
        $opts = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
    }
    return $pdo;
}

// ----------------------------------------------------------
//  JSON response helper
// ----------------------------------------------------------
function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ----------------------------------------------------------
//  Create upload directory if missing
// ----------------------------------------------------------
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

define('APP_URL', 'https://leenlife.clinic'); // change to your real domain
define('SHARE_SECRET', 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET_64+CHARS');

function b64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function b64url_decode(string $data): string|false {
    $pad = strlen($data) % 4;
    if ($pad) $data .= str_repeat('=', 4 - $pad);
    return base64_decode(strtr($data, '-_', '+/'), true);
}

/**
 * Create signed patient share token.
 * payload: patient id + issued time
 */
function makePatientShareToken(int $patientId): string {
    $payload = json_encode([
        'pid' => $patientId,
        'iat' => time(),
    ], JSON_UNESCAPED_UNICODE);

    $payloadB64 = b64url_encode((string)$payload);
    $sig = hash_hmac('sha256', $payloadB64, SHARE_SECRET, true);
    $sigB64 = b64url_encode($sig);

    return $payloadB64 . '.' . $sigB64;
}

/**
 * Validate token and return patientId or null.
 */
function parsePatientShareToken(string $token): ?int {
    $parts = explode('.', $token);
    if (count($parts) !== 2) return null;

    [$payloadB64, $sigB64] = $parts;

    $expectedSig = b64url_encode(hash_hmac('sha256', $payloadB64, SHARE_SECRET, true));
    if (!hash_equals($expectedSig, $sigB64)) return null;

    $json = b64url_decode($payloadB64);
    if ($json === false) return null;

    $data = json_decode($json, true);
    if (!is_array($data) || empty($data['pid'])) return null;

    return max(0, (int)$data['pid']);
}

function ravcoDb(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=localhost;port=3306;dbname=u136620672_VpD3pAX8u_ravco;charset=utf8mb4';
        $opts = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        $pdo = new PDO($dsn, 'u136620672_VpD3pAX8u_ravco', '~xa&ecz=PNWf1', $opts);
    }
    return $pdo;
}

function fetchRavcoPatientInfo(string $patientId): ?array {
    try {
        $stmt = ravcoDb()->prepare("SELECT pat_id, pat_name, pat_age, pat_weight FROM pat_radio WHERE pat_id = ? ORDER BY no DESC LIMIT 1");
        $stmt->execute([$patientId]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null; // ravco DB unreachable or no match — caller falls back gracefully
    }
}


?>