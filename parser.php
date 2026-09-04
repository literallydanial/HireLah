<?php
// parser.php
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

function extract_text_from_pdf($filepath) {
    if (!class_exists('\Smalot\PdfParser\Parser')) {
        // Fallback plain text reader if the vendor folder wasn't fully deployed
        $content = @file_get_contents($filepath);
        if ($content) {
            $plain = preg_replace('/[^\x20-\x7E\x0A\x0D]/', ' ', $content);
            return strlen(trim($plain)) > 50 ? $plain : "Candidate PDF Resume Uploaded.";
        }
        return "Candidate Resume File Uploaded";
    }

    // Larger/more complex PDFs (embedded images, fonts, multi-page layouts) can be
    // slower and more memory-hungry to parse than a plain single-page text resume —
    // give this a bit more headroom so it doesn't hit PHP's default limits.
    $prev_memory_limit = @ini_get('memory_limit');
    @ini_set('memory_limit', '256M');
    $prev_time_limit = @ini_get('max_execution_time');
    @set_time_limit(60);

    try {
        $parser = new \Smalot\PdfParser\Parser();
        $pdf = $parser->parseFile($filepath);
        $text = $pdf->getText();
        return ($text !== null && trim($text) !== '') ? $text : "Candidate PDF Resume Uploaded.";
    } catch (\Throwable $e) {
        // \Throwable (not just \Exception) — smalot/pdfparser can raise plain PHP
        // errors (e.g. TypeError) on malformed/complex PDF structures, which a
        // narrower "catch (Exception $e)" does NOT catch and would otherwise crash
        // the whole request with an uncaught fatal error ("the page goes down").
        error_log("PDF Parse Error ({$filepath}): " . $e->getMessage());
        return "Resume document uploaded (PDF text extraction notice: " . $e->getMessage() . ")";
    } finally {
        if ($prev_memory_limit !== false) @ini_set('memory_limit', $prev_memory_limit);
        if ($prev_time_limit !== false) @set_time_limit((int)$prev_time_limit);
    }
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
