<?php
// parser.php
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

function decode_pdf_string($str) {
    $str = preg_replace_callback('/\\\\([0-7]{1,3})/', function($m) {
        return chr(octdec($m[1]));
    }, $str);
    $str = str_replace(
        ['\\\\', '\\(', '\\)', '\\n', '\\r', '\\t', '\\b', '\\f'],
        ['\\', '(', ')', "\n", "\r", "\t", "\x08", "\x0C"],
        $str
    );
    return $str;
}

function extract_raw_pdf_text($filepath) {
    $content = @file_get_contents($filepath);
    if (!$content) return '';

    $extracted = '';

    // 1. Scan for compressed or uncompressed PDF streams
    if (preg_match_all('/stream[\r\n]+([\s\S]*?)[\r\n]+endstream/m', $content, $matches)) {
        foreach ($matches[1] as $stream) {
            $uncompressed = @gzuncompress($stream);
            if ($uncompressed === false) $uncompressed = @gzinflate($stream);
            if ($uncompressed === false) $uncompressed = @zlib_decode($stream);
            if ($uncompressed === false) $uncompressed = $stream;

            // Look for PDF text show commands: (...) Tj, (...) ', (...) ", [(...)] TJ
            if (preg_match_all('/(?:\((.*?)\)\s*(?:Tj|\'|\")|\[([\s\S]*?)\]\s*TJ)/s', $uncompressed, $text_matches, PREG_SET_ORDER)) {
                foreach ($text_matches as $tm) {
                    if (isset($tm[1]) && $tm[1] !== '') {
                        $extracted .= decode_pdf_string($tm[1]) . ' ';
                    } elseif (!empty($tm[2])) {
                        if (preg_match_all('/\((.*?)\)/s', $tm[2], $parts)) {
                            foreach ($parts[1] as $p) {
                                $extracted .= decode_pdf_string($p);
                            }
                            $extracted .= ' ';
                        }
                    }
                }
            }
        }
    }

    $extracted = trim(preg_replace('/\s+/', ' ', $extracted));
    if (strlen($extracted) > 40) {
        return $extracted;
    }

    // 2. Fallback: printable ASCII sequence extraction
    $plain = preg_replace('/[^\x20-\x7E\x0A\x0D]/', ' ', $content);
    $plain = preg_replace('/\s+/', ' ', $plain);
    return trim($plain);
}

function extract_text_from_pdf($filepath) {
    if (!file_exists($filepath)) {
        return "";
    }

    $prev_memory_limit = @ini_get('memory_limit');
    @ini_set('memory_limit', '256M');
    $prev_time_limit = @ini_get('max_execution_time');
    @set_time_limit(60);

    $text = '';

    if (class_exists('\Smalot\PdfParser\Parser')) {
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($filepath);
            $parsed_text = $pdf->getText();
            if ($parsed_text !== null && trim($parsed_text) !== '') {
                $text = $parsed_text;
            }
        } catch (\Throwable $e) {
            error_log("PDF Parse Error ({$filepath}): " . $e->getMessage());
        }
    }

    if (empty(trim($text))) {
        // Fallback to internal raw stream parser
        $raw_extracted = extract_raw_pdf_text($filepath);
        if (!empty(trim($raw_extracted))) {
            $text = $raw_extracted;
        }
    }

    if ($prev_memory_limit !== false) @ini_set('memory_limit', $prev_memory_limit);
    if ($prev_time_limit !== false) @set_time_limit((int)$prev_time_limit);

    return ($text !== null && trim($text) !== '') ? $text : "Candidate PDF Resume Uploaded.";
}

function strip_pii($text) {
    // Basic PII stripping based on regex, replicating strip_pii.py logic
    
    // 1. Remove Emails
    $text = preg_replace('/[a-zA-Z0-9_.+-]+@[a-zA-Z0-9-]+\.[a-zA-Z0-9-.]+/', '[REDACTED EMAIL]', $text);
    
    // 2. Remove Phone Numbers (Malaysia/General)
    // Matches patterns like +6012-3456789, 012-345 6789, etc.
    $text = preg_replace('/(\+?6?01[0-9]{1}-?[0-9]{7,8})|(\+?6?0[1-9]-?[0-9]{7,8})/', '[REDACTED PHONE]', $text);
    
    // 3. Remove IC numbers / Passports (General)
    $text = preg_replace('/[0-9]{6}-[0-9]{2}-[0-9]{4}/', '[REDACTED IC]', $text);
    
    // 4. Remove Name-like structures (Basic sanitization without NLP)
    // In pure PHP, we apply basic regex stripping for privacy.
    
    return $text;
}
?>
