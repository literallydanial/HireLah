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
            user_id INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (source),
            INDEX (created_at),
            INDEX (success)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (\Throwable $e) {
        error_log("Failed to create gemini_api_logs table: " . $e->getMessage());
    }
}

/**
 * Logs one real attempt against the Gemini API. Called once per model tried
 * inside call_gemini_api()'s fallback loop (and once for check_api_key_status),
 * never once-per-logical-action -- that's what makes a 1-click action that
 * quietly retried across 4 models before succeeding visible as 4 rows, not 1.
 */
function log_gemini_api_call($pdo, $source, $model_requested, $attempt_number, $http_status, $success, $latency_ms, $error_message = null) {
    try {
        ensure_gemini_api_logs_table_exists($pdo);
        $user_id = $_SESSION['user_id'] ?? null;
        $stmt = $pdo->prepare("INSERT INTO gemini_api_logs (source, model_requested, attempt_number, http_status, success, latency_ms, error_message, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$source, $model_requested, $attempt_number, $http_status, $success ? 1 : 0, $latency_ms, $error_message, $user_id]);
    } catch (\Throwable $e) {
        error_log("Failed to log gemini_api_call: " . $e->getMessage());
    }
}
