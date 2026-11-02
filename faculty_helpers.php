<?php
// Fixed faculty/department categories for the university cohort & faculty
// breakdown (keeps the breakdown groupable); "Other" lets a student type
// their own when none of these fit -- see register.php / complete_profile.php.
function faculty_categories() {
    return [
        'Engineering',
        'Business & Management',
        'Science',
        'Information Technology / Computing',
        'Arts & Social Science',
        'Medicine & Health Sciences',
        'Law',
        'Education',
    ];
}

// Resolves the posted faculty select + "Other" free-text box into the single
// value that actually gets stored, or '' if nothing usable was submitted.
function resolve_posted_faculty(array $post): string {
    $selected = trim($post['faculty'] ?? '');
    if ($selected === 'Other') {
        return trim($post['faculty_other'] ?? '');
    }
    return in_array($selected, faculty_categories(), true) ? $selected : '';
}
