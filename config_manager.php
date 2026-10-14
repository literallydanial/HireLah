<?php
// config_manager.php — Centralized and secure configuration manager for Keria

if (!defined('KERIA_INTERNAL')) {
    define('KERIA_INTERNAL', true);
}

/**
 * Returns path to PHP configuration file
 */
function get_config_file_path() {
    return __DIR__ . '/config.php';
}

/**
 * Returns path to legacy JSON configuration file
 */
function get_config_json_path() {
    return __DIR__ . '/config.json';
}

/**
 * Loads configuration safely.
 * If legacy config.json exists, it automatically migrates the settings to config.php
 * and immediately deletes config.json from disk to prevent public web leaks.
 *
 * @return array
 */
function load_app_config() {
    $php_file = get_config_file_path();
    $json_file = get_config_json_path();
    $config = [];

    // Load from secure config.php if present
    if (file_exists($php_file)) {
        $loaded = @include $php_file;
        if (is_array($loaded)) {
            $config = $loaded;
        }
    }

    // Auto-migration from legacy config.json if present
    if (file_exists($json_file)) {
        $raw = @file_get_contents($json_file);
        if ($raw) {
            $json_arr = @json_decode($raw, true);
            if (is_array($json_arr)) {
                $filtered_json = array_filter($json_arr, function($v) { return $v !== null && $v !== ''; });
                $config = array_merge($config, $filtered_json);
                save_app_config($config);
            }
        }
        // Immediately delete config.json to eliminate web vulnerability
        @unlink($json_file);
    }

    if (empty($config['ai_model'])) {
        $config['ai_model'] = 'gemini-3.6-flash';
    }

    return $config;
}

/**
 * Saves configuration to config.php with direct-access execution block.
 *
 * @param array $config
 * @return bool
 */
function save_app_config(array $config) {
    $php_file = get_config_file_path();
    $json_file = get_config_json_path();

    $exported = var_export($config, true);
    $content = "<?php\n"
             . "// Secure Keria Configuration — Direct HTTP web access forbidden\n"
             . "if (!defined('KERIA_INTERNAL') && basename(\$_SERVER['SCRIPT_FILENAME'] ?? \$_SERVER['PHP_SELF'] ?? '') === 'config.php') {\n"
             . "    http_response_code(403);\n"
             . "    exit('403 Forbidden: Direct access to configuration is denied.');\n"
             . "}\n\n"
             . "return {$exported};\n";

    $written = @file_put_contents($php_file, $content);

    // Always delete legacy config.json if present
    if (file_exists($json_file)) {
        @unlink($json_file);
    }

    return $written !== false;
}
