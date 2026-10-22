<?php
/**
 * Converts the lightweight formatting markers inserted by the job
 * description toolbar (**bold**, "- " bullets, "## " headings) into
 * safe HTML for display to candidates.
 *
 * Security note: the raw text is escaped FIRST with htmlspecialchars,
 * so anything a user typed that merely looks like a tag or script is
 * neutralized before any markup is added back in. Only the fixed set
 * of tags below are ever introduced (<strong>, <ul>, <li>, <h4>, <p>,
 * <br>), and they are never built from unescaped user input.
 */
function format_job_description(?string $raw): string {
    $raw = trim((string)$raw);
    if ($raw === '') {
        return '';
    }

    // Escape first — everything from here on in $escaped is inert text.
    $escaped = htmlspecialchars($raw, ENT_QUOTES, 'UTF-8');

    // Bold: **text** -> <strong>text</strong>
    $escaped = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $escaped);

    $lines = preg_split('/\r\n|\r|\n/', $escaped);
    $html = '';
    $in_list = false;
    $in_para = false;
    $count = count($lines);

    for ($idx = 0; $idx < $count; $idx++) {
        $trimmed = trim($lines[$idx]);

        if ($trimmed === '') {
            // A blank line between bullets (common while typing, for
            // readability) shouldn't split one list into several separate
            // <ul> blocks -- browsers add their own margin around every
            // <ul>, so that used to stack up into large gaps between what
            // should be one continuous list. Only close the list if what
            // comes next genuinely isn't another bullet.
            if ($in_list) {
                $next_is_bullet = false;
                for ($j = $idx + 1; $j < $count; $j++) {
                    $peek = trim($lines[$j]);
                    if ($peek === '') continue;
                    $next_is_bullet = preg_match('/^[-*•]\s+/u', $peek) === 1;
                    break;
                }
                if ($next_is_bullet) continue;
            }
            if ($in_list) { $html .= "</ul>\n"; $in_list = false; }
            if ($in_para) { $html .= "</p>\n"; $in_para = false; }
            continue;
        }

        // Heading: ## Heading text
        if (preg_match('/^#{1,3}\s+(.*)$/', $trimmed, $m)) {
            if ($in_list) { $html .= "</ul>\n"; $in_list = false; }
            if ($in_para) { $html .= "</p>\n"; $in_para = false; }
            $html .= '<h4 class="job-spec-block-title" style="margin-top:16px;">' . $m[1] . "</h4>\n";
            continue;
        }

        // Bullet: - item, * item, or • item (the toolbar offers both a dash
        // and a round-bullet button — either way it renders as a real <li>)
        if (preg_match('/^[-*•]\s+(.*)$/u', $trimmed, $m)) {
            if ($in_para) { $html .= "</p>\n"; $in_para = false; }
            if (!$in_list) { $html .= "<ul class=\"job-bullet-list\">\n"; $in_list = true; }
            // Defensive cleanup: a handful of postings saved before an earlier
            // toolbar bug was fixed can have a second leading marker baked in
            // (e.g. "• - text"). Strip a second one too so those display
            // cleanly without needing to be manually re-edited.
            $item_content = preg_replace('/^[-*•]\s+/u', '', $m[1]);
            $html .= '<li>' . $item_content . "</li>\n";
            continue;
        }

        // Plain paragraph text
        if ($in_list) { $html .= "</ul>\n"; $in_list = false; }
        if (!$in_para) {
            $html .= '<p class="job-desc-paragraph">';
            $in_para = true;
        } else {
            $html .= '<br>';
        }
        $html .= $trimmed;
    }

    if ($in_list) { $html .= "</ul>\n"; }
    if ($in_para) { $html .= "</p>\n"; }

    return $html;
}

/**
 * Splits a saved job description back into [title, content] section pairs,
 * the reverse of what the "section card" builder on the post/edit job forms
 * produces (each section was joined as "## Title\ncontent"). Used to
 * pre-populate the section-card UI when editing an existing job.
 *
 * If the text has no "## " markers at all (e.g. an older posting written
 * before this feature existed, or one typed as plain prose), the whole
 * thing is returned as a single section so nothing is lost.
 */
function parse_job_description_sections(?string $raw): array {
    $raw = trim((string)$raw);
    if ($raw === '') {
        return [['title' => '', 'content' => '']];
    }

    $lines = preg_split('/\r\n|\r|\n/', $raw);
    $sections = [];
    $current_title = '';
    $current_lines = [];
    $has_any_heading = false;

    foreach ($lines as $line) {
        if (preg_match('/^#{1,3}\s+(.*)$/', trim($line), $m)) {
            $has_any_heading = true;
            if ($current_title !== '' || trim(implode("\n", $current_lines)) !== '') {
                $sections[] = ['title' => $current_title, 'content' => trim(implode("\n", $current_lines))];
            }
            $current_title = $m[1];
            $current_lines = [];
        } else {
            $current_lines[] = $line;
        }
    }
    if ($current_title !== '' || trim(implode("\n", $current_lines)) !== '') {
        $sections[] = ['title' => $current_title, 'content' => trim(implode("\n", $current_lines))];
    }

    if (!$has_any_heading) {
        // No title here -- jobs.php already prints its own "📄 Job
        // Description" header above this content, so defaulting this
        // section's title to that same text would print it twice. Leaving
        // it blank also matches exactly how this content looked before this
        // feature existed: plain text under that one existing header.
        return [['title' => '', 'content' => $raw]];
    }

    return $sections ?: [['title' => '', 'content' => '']];
}

/**
 * Human-readable unit suffix for a job's salary_rate_type ('daily',
 * 'weekly', 'monthly'). Defaults to monthly for any unrecognized or
 * missing value, matching the column's own DB default.
 */
function job_salary_unit_label(?string $rate_type): string {
    switch ($rate_type) {
        case 'daily':
            return '/ day';
        case 'weekly':
            return '/ week';
        default:
            return '/ month';
    }
}
