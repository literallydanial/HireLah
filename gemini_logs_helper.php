<?php
// gemini_logs_helper.php
// Records every real attempt made against the Gemini API (including each
// model tried in a single logical call's fallback chain), so admins can see
// exactly what is consuming API quota and when -- nothing like this existed
// before, so there is no historical data, only what gets logged from here on.

function ensure_gemini_api_logs_table_exists($pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS gemini_api_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            source VARCHAR(50) NOT NULL,
            model_requested VARCHAR(100) NOT NULL,
            attempt_number INT NOT NULL,
            http_status INT DEFAULT NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            latency_ms INT DEFAULT NULL,
            error_message VARCHAR(500) DEFAULT NULL,
            status_label VARCHAR(30) DEFAULT NULL,
            message VARCHAR(500) DEFAULT NULL,
            user_id INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (source),
            INDEX (created_at),
            INDEX (success)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // Self-heal: add the newer columns for any DB that already created this
        // table before status_label/message existed (e.g. during development).
        $cols = $pdo->query("SHOW COLUMNS FROM gemini_api_logs")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('status_label', $cols, true)) {
            $pdo->exec("ALTER TABLE gemini_api_logs ADD COLUMN status_label VARCHAR(30) DEFAULT NULL AFTER error_message");
        }
        if (!in_array('message', $cols, true)) {
            $pdo->exec("ALTER TABLE gemini_api_logs ADD COLUMN message VARCHAR(500) DEFAULT NULL AFTER status_label");
        }
    } catch (\Throwable $e) {
        error_log("Failed to create/update gemini_api_logs table: " . $e->getMessage());
    }
}

/**
 * Logs one real attempt against the Gemini API. Called once per model tried
 * inside call_gemini_api()'s fallback loop (and once for check_api_key_status),
 * never once-per-logical-action -- that's what makes a 1-click action that
 * quietly retried across 4 models before succeeding visible as 4 rows, not 1.
 *
 * $status_label/$message are only meaningful for the 'health_check' source
 * (active/busy/invalid_key/etc, see check_api_key_status()) -- other sources
 * leave them null.
 */
function log_gemini_api_call($pdo, $source, $model_requested, $attempt_number, $http_status, $success, $latency_ms, $error_message = null, $status_label = null, $message = null) {
    try {
        ensure_gemini_api_logs_table_exists($pdo);
        $user_id = $_SESSION['user_id'] ?? null;
        $stmt = $pdo->prepare("INSERT INTO gemini_api_logs (source, model_requested, attempt_number, http_status, success, latency_ms, error_message, status_label, message, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$source, $model_requested, $attempt_number, $http_status, $success ? 1 : 0, $latency_ms, $error_message, $status_label, $message, $user_id]);
    } catch (\Throwable $e) {
        error_log("Failed to log gemini_api_call: " . $e->getMessage());
    }
}

/**
 * Returns the most recently logged health-check result (source='health_check'),
 * or null if none has ever been recorded. Used to show a cached status on the
 * admin dashboard WITHOUT making a fresh live call on every page load -- a
 * live call only happens when an admin explicitly clicks "Check Now".
 */
function get_last_health_check($pdo) {
    try {
        ensure_gemini_api_logs_table_exists($pdo);
        $stmt = $pdo->query("SELECT status_label, message, model_requested, latency_ms, created_at FROM gemini_api_logs WHERE source = 'health_check' ORDER BY created_at DESC LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (\Throwable $e) {
        error_log("Failed to read last gemini health check: " . $e->getMessage());
        return null;
    }
}
