<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

function load_env_file(string $path): void {
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $separatorPos = strpos($line, '=');
        if ($separatorPos === false) {
            continue;
        }

        $name = trim(substr($line, 0, $separatorPos));
        $value = trim(substr($line, $separatorPos + 1));
        if ($name === '') {
            continue;
        }

        $firstChar = $value[0] ?? '';
        $lastChar = $value === '' ? '' : substr($value, -1);
        if (($firstChar === '"' && $lastChar === '"') || ($firstChar === "'" && $lastChar === "'")) {
            $value = substr($value, 1, -1);
        }

        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        putenv($name . '=' . $value);
    }
}

function env_value(string $key, string $default = ''): string {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($value === false || $value === null) {
        return $default;
    }

    return (string)$value;
}

load_env_file(dirname(__DIR__) . '/.env');
// ============================================================
//  eHealth — config.php
//  DB connection + all shared backend functions
//  require_once this at the top of every .php file
// ============================================================

// ── DB Credentials ──────────────────────────────────────────
define('DB_HOST', env_value('DB_HOST', 'localhost'));
define('DB_NAME', env_value('DB_NAME', 'ehealth_db'));
define('DB_USER', env_value('DB_USER', 'root'));
define('DB_PASS', env_value('DB_PASS', ''));

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$localConfigPath = __DIR__ . '/config.local.php';
if (is_file($localConfigPath)) {
    require_once $localConfigPath;
}

// ── Twilio ──────────────────────────────────────────────────
defined('TWILIO_SID') || define('TWILIO_SID', env_value('TWILIO_SID', ''));
defined('TWILIO_AUTH_TOKEN') || define('TWILIO_AUTH_TOKEN', env_value('TWILIO_AUTH_TOKEN', ''));
defined('TWILIO_PHONE') || define('TWILIO_PHONE', env_value('TWILIO_PHONE', ''));
defined('TWILIO_MESSAGING_SID') || define('TWILIO_MESSAGING_SID', env_value('TWILIO_MESSAGING_SID', ''));

defined('WHEREBY_API_KEY') || define('WHEREBY_API_KEY', env_value('WHEREBY_API_KEY', ''));
defined('WHEREBY_API_URL') || define('WHEREBY_API_URL', env_value('WHEREBY_API_URL', 'https://api.whereby.dev/v1'));


// ── Upload path ─────────────────────────────────────────────
define('UPLOAD_PATH', dirname(__DIR__) . '/uploads/');

// Resolve app base URL from current script (e.g. '/ehealth' for '/ehealth/public/...').
function app_base_url(): string {
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script === '') return '';

    $pos = strpos($script, '/public/');
    if ($pos === false) {
        $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
        return ($dir === '' || $dir === '/' || $dir === '.') ? '' : $dir;
    }

    $base = rtrim(substr($script, 0, $pos), '/');
    return ($base === '/' || $base === '') ? '' : $base;
}

function patient_photo_path(string $photo_filename): string {
    return rtrim(UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . 'patients' . DIRECTORY_SEPARATOR . $photo_filename;
}

// Returns public URL only if the patient photo file exists on disk.
function patient_photo_url(?string $photo_filename): string {
    $filename = trim((string)$photo_filename);
    if ($filename === '') return '';
    if (preg_match('/^[A-Za-z0-9._-]+$/', $filename) !== 1) return '';

    $abs = patient_photo_path($filename);
    if (!is_file($abs)) return '';

    return app_base_url() . '/uploads/patients/' . rawurlencode($filename);
}

// ── DB Connection (call db() anywhere to get the connection) ─
// Mask Aadhaar so only the last 4 digits remain visible.
function mask_aadhar_last4(?string $aadhar_no): string {
    $digits = preg_replace('/\D/', '', (string)$aadhar_no);
    if ($digits === '') return '';

    $len = strlen($digits);
    if ($len <= 4) return $digits;

    return str_repeat('X', $len - 4) . substr($digits, -4);
}

function db(): mysqli {
    static $conn = null;
    if ($conn === null) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn->connect_error) {
            http_response_code(500);
            die(json_encode(['success' => false, 'error' => 'Database connection failed']));
        }
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

// Ensure schema support for new-vs-old patient flow (N/O flag).
function ensure_patient_revisit_schema(mysqli $db): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $col = $db->query("SHOW COLUMNS FROM patients LIKE 'patient_flag'");
    if ($col && $col->num_rows > 0) return;

    $ok = $db->query("ALTER TABLE patients ADD COLUMN patient_flag ENUM('N','O') NOT NULL DEFAULT 'N' AFTER patient_id");
    if (!$ok) {
        error_log('ensure_patient_revisit_schema: ' . $db->error);
    }
}

function ensure_nursing_staff_table(mysqli $db): void {
    $db->query(
        "CREATE TABLE IF NOT EXISTS nursing_staff (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nursing_id VARCHAR(20) NOT NULL UNIQUE,
            full_name VARCHAR(255) NOT NULL,
            gender ENUM('Male','Female','Other') NOT NULL,
            dob DATE NOT NULL,
            qualification VARCHAR(120) NOT NULL,
            mobile_no VARCHAR(15) NOT NULL,
            email VARCHAR(255) NOT NULL,
            aadhar_no VARCHAR(20) NOT NULL,
            pincode VARCHAR(6) NOT NULL,
            state VARCHAR(100) NOT NULL,
            district VARCHAR(100) NOT NULL,
            city VARCHAR(100) NULL,
            village VARCHAR(100) NULL,
            street VARCHAR(255) NULL,
            password VARCHAR(255) NOT NULL,
            registered_by_admin INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_mobile (mobile_no),
            UNIQUE KEY uniq_email (email),
            UNIQUE KEY uniq_aadhar (aadhar_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    if ($db->error) {
        error_log('ensure_nursing_staff_table: ' . $db->error);
    }
}

function ensure_technician_table(mysqli $db): void {
    $db->query(
        "CREATE TABLE IF NOT EXISTS technicians (
            id INT AUTO_INCREMENT PRIMARY KEY,
            technician_id VARCHAR(20) NOT NULL UNIQUE,
            full_name VARCHAR(255) NOT NULL,
            gender ENUM('Male','Female','Other') NOT NULL,
            dob DATE NOT NULL,
            qualification VARCHAR(160) NOT NULL,
            mobile_no VARCHAR(15) NOT NULL,
            email VARCHAR(255) NOT NULL,
            aadhar_no VARCHAR(20) NOT NULL,
            pincode VARCHAR(6) NOT NULL,
            state VARCHAR(100) NOT NULL,
            district VARCHAR(100) NOT NULL,
            city VARCHAR(100) NULL,
            village VARCHAR(100) NULL,
            street VARCHAR(255) NULL,
            password VARCHAR(255) NOT NULL,
            registered_by_admin INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_mobile (mobile_no),
            UNIQUE KEY uniq_email (email),
            UNIQUE KEY uniq_aadhar (aadhar_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    if ($db->error) {
        error_log('ensure_technician_table: ' . $db->error);
    }
}

function ensure_pharmacy_staff_table(mysqli $db): void {
    $db->query(
        "CREATE TABLE IF NOT EXISTS pharmacy_staff (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pharmacy_id VARCHAR(20) NOT NULL UNIQUE,
            full_name VARCHAR(255) NOT NULL,
            gender ENUM('Male','Female','Other') NOT NULL,
            dob DATE NOT NULL,
            qualification VARCHAR(160) NOT NULL,
            mobile_no VARCHAR(15) NOT NULL,
            email VARCHAR(255) NOT NULL,
            aadhar_no VARCHAR(20) NOT NULL,
            pincode VARCHAR(6) NOT NULL,
            state VARCHAR(100) NOT NULL,
            district VARCHAR(100) NOT NULL,
            city VARCHAR(100) NULL,
            village VARCHAR(100) NULL,
            street VARCHAR(255) NULL,
            password VARCHAR(255) NOT NULL,
            registered_by_admin INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_mobile (mobile_no),
            UNIQUE KEY uniq_email (email),
            UNIQUE KEY uniq_aadhar (aadhar_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    if ($db->error) {
        error_log('ensure_pharmacy_staff_table: ' . $db->error);
    }
}

function ensure_pharmacy_dispense_table(mysqli $db): void {
    $db->query(
        "CREATE TABLE IF NOT EXISTS pharmacy_dispense (
            id INT AUTO_INCREMENT PRIMARY KEY,
            diagnosis_type ENUM('ehealth','teleconsult') NOT NULL,
            diagnosis_id INT NOT NULL,
            patient_id VARCHAR(20) NOT NULL,
            ehealth_doctor_id VARCHAR(20) NULL,
            telestudio_doctor_id VARCHAR(20) NULL,
            pharmacy_staff_id INT NULL,
            pharmacy_staff_name VARCHAR(255) NULL,
            telemedicine_charge DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            telemedicine_paid TINYINT(1) NOT NULL DEFAULT 0,
            total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            medicines_json JSON NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_diag (diagnosis_type, diagnosis_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    if ($db->error) {
        error_log('ensure_pharmacy_dispense_table: ' . $db->error);
    }
}

// ── Session ─────────────────────────────────────────────────
function start_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        // Keep doctor sessions stable during long teleconsultations.
        $sessionLifetime = 43200; // 12 hours
        ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
        session_name('ehealth_session');
        session_set_cookie_params(['lifetime' => $sessionLifetime, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        session_start();
    }
}

function set_user_session(array $user): void {
    $_SESSION['user_id']   = $user['id'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['logged_in'] = true;
}

function is_logged_in(): bool {
    return !empty($_SESSION['logged_in']) && !empty($_SESSION['user_id']);
}

function get_session_role(): ?string {
    return $_SESSION['user_role'] ?? null;
}

function get_session_name(): string {
    return $_SESSION['user_name'] ?? '';
}

function get_session_id(): mixed {
    return $_SESSION['user_id'] ?? null;
}

// Redirect to login if role doesn't match — use in page files
function require_role(string $role, string $login_url): void {
    start_session();
    if (!is_logged_in() || get_session_role() !== $role) {
        header('Location: ' . $login_url);
        exit;
    }
}

function logout_user(): void {
    start_session();
    session_unset();
    session_destroy();
}

// ── JSON helpers (for API/form handlers) ────────────────────
function json_success(array $data = [], string $message = ''): void {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => true, 'message' => $message], $data));
    exit;
}

function json_error(string $error, int $code = 400): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $error]);
    exit;
}

// ── Password helpers ────────────────────────────────────────
function hash_password(string $password): string {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
}

function verify_password(string $password, string $hash): bool {
    // Support legacy plain-text passwords in DB
    if (str_starts_with($hash, '$2')) {
        return password_verify($password, $hash);
    }
    return $hash === $password; // plain-text fallback
}
?>
