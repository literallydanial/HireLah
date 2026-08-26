<?php
// questionnaire_helpers.php
// Shared helper for reading one item out of a decoded questions_json array.
// An item may be a legacy plain string (pre-typed-questions format) or the
// newer {text, type, required, options} object — this always returns the
// newer shape so callers never need to branch on which format they got.
function normalize_question($q) {
    if (is_string($q)) {
        return [
            'text' => $q,
            'type' => 'long_text',
            'required' => true,
            'options' => [],
        ];
    }
    return [
        'text' => $q['text'] ?? '',
        'type' => $q['type'] ?? 'long_text',
        'required' => array_key_exists('required', $q) ? (bool)$q['required'] : true,
        'options' => $q['options'] ?? [],
    ];
}
