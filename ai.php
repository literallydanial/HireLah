<?php
// ai.php
require_once __DIR__ . '/config_manager.php';
require_once __DIR__ . '/gemini_logs_helper.php';

function get_api_key() {
    $config = load_app_config();
    if (!empty($config['api_key'])) {
        $_SESSION['api_key'] = $config['api_key'];
        $_SESSION['ai_model'] = $config['ai_model'] ?? ($_SESSION['ai_model'] ?? 'gemini-3.6-flash');
        return $config['api_key'];
    }
    return $_SESSION['api_key'] ?? null;
}

function save_api_config($key, $model = 'gemini-3.6-flash') {
    $_SESSION['api_key'] = $key;
    $_SESSION['ai_model'] = $model;

    $curr_config = load_app_config();
    $curr_config['api_key'] = $key;
    $curr_config['ai_model'] = $model;

    save_app_config($curr_config);
}

function call_gemini_api($api_key, $prompt, $source = 'unknown') {
    global $pdo;
    $selected_model = $_SESSION['ai_model'] ?? 'gemini-1.5-flash';
    $models_to_try = array_unique(array_filter([
        $selected_model,
        'gemini-1.5-flash',
        'gemini-2.0-flash',
        'gemini-2.5-flash',
        'gemini-1.5-pro',
        'gemini-3.6-flash',
        'gemini-3.5-flash-lite',
        'gemini-flash-lite-latest',
        'gemini-flash-latest'
    ]));

    $last_error = null;
    $attempt_number = 0;
    foreach ($models_to_try as $model) {
        $attempt_number++;
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode($model) . ':generateContent?key=' . trim($api_key);

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.2,
                'maxOutputTokens' => 4096
            ]
        ];

        $started_at = microtime(true);
        try {
            $ch = @curl_init($url);
            if ($ch === false) continue;

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_err = curl_error($ch);
            curl_close($ch);
            $latency_ms = (int) round((microtime(true) - $started_at) * 1000);

            if ($http_code === 200 && !empty($response)) {
                $data = json_decode($response, true);
                $text_output = '';
                if (!empty($data['candidates'][0]['content']['parts'])) {
                    foreach ($data['candidates'][0]['content']['parts'] as $part) {
                        if (!empty($part['text'])) {
                            $text_output .= $part['text'];
                        }
                    }
                }
                if (!empty($text_output)) {
                    log_gemini_api_call($pdo, $source, $model, $attempt_number, $http_code, true, $latency_ms);
                    return $text_output;
                }
                log_gemini_api_call($pdo, $source, $model, $attempt_number, $http_code, false, $latency_ms, 'Empty response text');
            } else {
                $msg = !empty($curl_err) ? $curl_err : "HTTP $http_code Response";
                $last_error = "Gemini API Call Failed ($model): " . $msg;
                error_log($last_error);
                log_gemini_api_call($pdo, $source, $model, $attempt_number, $http_code ?: null, false, $latency_ms, substr($msg, 0, 500));
            }
        } catch (Throwable $t) {
            error_log("Gemini cURL Throwable: " . $t->getMessage());
            log_gemini_api_call($pdo, $source, $model, $attempt_number, null, false, null, substr($t->getMessage(), 0, 500));
        }
    }

    return null;
}

/**
 * Actually tests the configured Gemini API key/model against the live API
 * @return array ['status' => 'active'|'not_configured'|'invalid_key'|'model_not_found'|'unreachable'|'error', 'message' => string]
 */
function check_api_key_status($api_key, $model = 'gemini-3.6-flash') {
    global $pdo;
    if (empty($api_key)) {
        return ['status' => 'not_configured', 'message' => 'No Gemini API key has been configured yet.'];
    }

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode($model) . ':generateContent?key=' . trim($api_key);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'contents' => [
            ['role' => 'user', 'parts' => [['text' => 'ping']]]
        ],
        'generationConfig' => [
            'maxOutputTokens' => 1
        ]
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);

    $started_at = microtime(true);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);
    $latency_ms = (int) round((microtime(true) - $started_at) * 1000);

    if ($http_code === 0) {
        $result = ['status' => 'unreachable', 'message' => $curl_err ?: 'Could not reach the Google Gemini API.'];
        log_gemini_api_call($pdo, 'health_check', $model, 1, null, false, $latency_ms, $curl_err ?: 'Unreachable', $result['status'], $result['message']);
        return $result;
    }
    if ($http_code === 200) {
        $result = ['status' => 'active', 'message' => 'Gemini API key and model are active & working.'];
        log_gemini_api_call($pdo, 'health_check', $model, 1, $http_code, true, $latency_ms, null, $result['status'], $result['message']);
        return $result;
    }

    $data = json_decode($response, true);
    $err_msg = $data['error']['message'] ?? "Unexpected HTTP $http_code response.";

    if ($http_code === 400 || $http_code === 403 || $http_code === 401) {
        $is_invalid = stripos($err_msg, 'API_KEY_INVALID') !== false || stripos($err_msg, 'API key not valid') !== false || $http_code === 401;
        $result = ['status' => 'invalid_key', 'message' => $is_invalid ? 'Invalid Gemini API key provided.' : $err_msg];
        log_gemini_api_call($pdo, 'health_check', $model, 1, $http_code, false, $latency_ms, substr($err_msg, 0, 500), $result['status'], $result['message']);
        return $result;
    }
    if ($http_code === 503 || stripos($err_msg, 'high demand') !== false || stripos($err_msg, 'spikes in demand') !== false) {
        // Test fast fallback model to see if API key is working on another model
        if ($model !== 'gemini-3.5-flash-lite') {
            $fallback_check = check_api_key_status($api_key, 'gemini-3.5-flash-lite');
            if ($fallback_check['status'] === 'active') {
                $result = ['status' => 'active', 'message' => "Key is active. '{$model}' is busy, auto-switched to high-availability fallback (gemini-3.5-flash-lite)."];
                log_gemini_api_call($pdo, 'health_check', $model, 1, $http_code, false, $latency_ms, substr($err_msg, 0, 500), $result['status'], $result['message']);
                return $result;
            }
        }
        $result = ['status' => 'busy', 'message' => "Google Gemini ({$model}) is experiencing high global traffic (HTTP 503). Retrying automatically & local fallbacks active."];
        log_gemini_api_call($pdo, 'health_check', $model, 1, $http_code, false, $latency_ms, substr($err_msg, 0, 500), $result['status'], $result['message']);
        return $result;
    }
    if ($http_code === 404) {
        $result = ['status' => 'model_not_found', 'message' => "Model '{$model}' not available: " . $err_msg];
        log_gemini_api_call($pdo, 'health_check', $model, 1, $http_code, false, $latency_ms, substr($err_msg, 0, 500), $result['status'], $result['message']);
        return $result;
    }

    $result = ['status' => 'error', 'message' => $err_msg];
    log_gemini_api_call($pdo, 'health_check', $model, 1, $http_code, false, $latency_ms, substr($err_msg, 0, 500), $result['status'], $result['message']);
    return $result;
}

function generate_fallback_snapshot($stripped_text, $role_description) {
    // ATS Resume Validity Pre-Check
    $resume_indicators = ['experience', 'education', 'skills', 'work', 'project', 'university', 'college', 'school', 'history', 'role', 'developer', 'engineer', 'manager', 'specialist', 'assistant', 'resume', 'cv', 'employment', 'responsibilities', 'achievements'];
    $indicator_count = 0;
    foreach ($resume_indicators as $ind) {
        if (stripos($stripped_text, $ind) !== false) {
            $indicator_count++;
        }
    }

    // If text is not a resume (extremely short or lacks resume indicators).
    // Require at least 3 distinct indicator keywords, not just 1 — a single common
    // word like "work" or "role" can coincidentally appear in unrelated text
    // (e.g. "these containers work best"), which previously let non-resumes through.
    if (strlen(trim($stripped_text)) < 80 || $indicator_count < 3) {
        $snippet = strlen($stripped_text) > 0 ? substr(trim(preg_replace('/\s+/', ' ', $stripped_text)), 0, 100) : "Empty File";
        return [
            'name' => 'Unknown / Invalid File',
            'relevance_label' => 'Do Not Hire',
            'overall_score' => 0,
            'skills_match' => 0,
            'exp_match' => 0,
            'edu_match' => 0,
            'summary' => 'INVALID DOCUMENT REJECTED BY ATS: The uploaded file does not contain a standard candidate resume structure, work experience, or professional qualifications.',
            'education' => 'None / Not Provided',
            'experience' => 'None / Not Provided',
            'skills' => ['Invalid Document Format'],
            'strengths' => ['None - Invalid Document Format'],
            'gaps' => [
                'Uploaded document is not a valid candidate resume/CV [Resume Evidence: "' . $snippet . '..."]',
                'Fails mandatory ATS parsing and qualification standards.'
            ],
            'note' => 'Automatically rejected by ATS screening due to non-resume file content.',
            'suggested_question' => "1. Please submit an official professional CV/Resume.\n2. Why was a non-resume document uploaded for this position?"
        ];
    }

    // Extract candidate name from resume
    $lines = array_values(array_filter(explode("\n", trim($stripped_text))));
    $name = !empty($lines[0]) && strlen($lines[0]) < 50 ? trim($lines[0]) : "Candidate Applicant";

    // Extract technical & domain terms from Job Description
    $all_keywords = ['PHP', 'JavaScript', 'HTML', 'CSS', 'SQL', 'MySQL', 'Python', 'React', 'Node.js', 'Git', 'AWS', 'Docker', 'REST API', 'Management', 'Agile', 'Scrum', 'Communication', 'Customer Service', 'Laravel', 'Vue', 'Angular', 'Java', 'C++', 'DevOps', 'CI/CD'];
    
    $jd_required_skills = [];
    foreach ($all_keywords as $k) {
        if (stripos($role_description, $k) !== false) {
            $jd_required_skills[] = $k;
        }
    }
    if (empty($jd_required_skills)) {
        $jd_required_skills = ['PHP', 'SQL', 'Communication'];
    }

    // Check which JD required skills exist in candidate resume
    $matched_skills = [];
    $missing_jd_skills = [];
    foreach ($jd_required_skills as $s) {
        if (stripos($stripped_text, $s) !== false) {
            $matched_skills[] = $s;
        } else {
            $missing_jd_skills[] = $s;
        }
    }

    $total_req = count($jd_required_skills);
    $total_match = count($matched_skills);
    $match_ratio = $total_req > 0 ? ($total_match / $total_req) : 0.8;

    // Both scores are weighted by how much of the resume actually overlaps with the
    // job description's required skills ($match_ratio) — previously exp_score was
    // based purely on resume text length, so any long resume scored 80%+ "Experience
    // Match" regardless of field (e.g. a graphic designer's resume against an HR
    // Executive posting), and skills_score had a 30% floor even with zero matches.
    $skills_score = min(98, max(10, round($match_ratio * 100)));
    $exp_score = min(92, max(15, round($match_ratio * 60) + (strlen($stripped_text) > 600 ? 20 : 5)));
    $edu_score = (stripos($stripped_text, 'bachelor') !== false || stripos($stripped_text, 'degree') !== false || stripos($stripped_text, 'university') !== false) ? 88 : 65;
    $overall_score = round(($skills_score * 0.5) + ($exp_score * 0.3) + ($edu_score * 0.2));

    $label = $overall_score >= 82 ? 'Strong Hire' : ($overall_score >= 68 ? 'Hire' : ($overall_score >= 50 ? 'Maybe' : 'Do Not Hire'));

    $strengths_list = [];
    foreach (array_slice($matched_skills, 0, 2) as $skill) {
        $strengths_list[] = "Has {$skill} listed as a technical skill.";
    }
    if (strlen($stripped_text) > 600) {
        $strengths_list[] = "Strong, detailed professional background relevant to the role.";
    }
    if (stripos($stripped_text, 'lead') !== false || stripos($stripped_text, 'manage') !== false || stripos($stripped_text, 'coordinat') !== false || stripos($stripped_text, 'vendor') !== false) {
        $strengths_list[] = "Experience coordinating teams or managing responsibilities shows some leadership potential.";
    }
    if (empty($strengths_list)) {
        $strengths_list[] = "Resume shows relevant professional experience aligned with the role.";
    }

    $gaps_list = [];
    foreach (array_slice($missing_jd_skills, 0, 3) as $skill) {
        $gaps_list[] = "No documented experience with {$skill}.";
    }
    if (empty($gaps_list)) {
        $gaps_list[] = "Recommend verifying practical depth of experience in high-scale production scenarios.";
    }

    $probe_q1 = !empty($missing_jd_skills) 
        ? "1. The job description requires experience with {$missing_jd_skills[0]}. Can you detail your actual working knowledge or equivalent experience with this requirement?"
        : "1. Can you walk us through a recent project where you delivered key requirements matching this job description?";

    return [
        'name' => $name,
        'relevance_label' => $label,
        'overall_score' => $overall_score,
        'skills_match' => $skills_score,
        'exp_match' => $exp_score,
        'edu_match' => $edu_score,
        'summary' => "ATS Evaluation against Job Description requirements: Fulfills " . round($match_ratio * 100) . "% of core required technical qualifications (" . implode(', ', array_slice($matched_skills, 0, 3)) . "). " . (!empty($missing_jd_skills) ? "Gaps noted for " . implode(', ', array_slice($missing_jd_skills, 0, 2)) . "." : "Solid overall alignment with position expectations."),
        'education' => (stripos($stripped_text, 'bachelor') !== false ? 'Bachelor Degree' : 'Tertiary Education / Diploma'),
        'experience' => '3+ Years Relevant Experience',
        'skills' => !empty($matched_skills) ? $matched_skills : ['Problem Solving', 'Teamwork'],
        'strengths' => $strengths_list,
        'gaps' => $gaps_list,
        'note' => "ATS Screener Note: Candidate brings " . ($overall_score >= 70 ? "strong alignment with core required deliverables." : "partial alignment with key JD skills that require targeted technical interview probing."),
        'suggested_question' => $probe_q1 . "\n2. How do your past accomplishments specifically prepare you for the main duties outlined in this job description?"
    ];
}

function generate_snapshot($api_key, $stripped_text, $role_description, $source = 'unknown') {
    if (!empty($api_key)) {
        try {
            $prompt = <<<PROMPT
You are an automated ATS (Applicant Tracking System) Screener and Senior Recruiter evaluating candidate submissions.

CRITICAL ATS RESUME VERIFICATION & JD MATCHING RULES:
1. RESUME VALIDITY CHECK (CRITICAL):
   - First, inspect if the uploaded CANDIDATE RESUME is actually a legitimate candidate resume / CV (containing work experience, professional background, education, or relevant job skills).
   - IF THE ATTACHED FILE IS NOT A RESUME (e.g. it is a random essay, recipe, invoice, letter, homework, blank/corrupted file, or non-resume text):
     - You MUST immediately set "relevance_label" to "Do Not Hire".
     - Set "overall_score", "skills_match", "exp_match", and "edu_match" to 0.
     - Set "summary" to "INVALID DOCUMENT REJECTED BY ATS: The uploaded file does not contain a standard candidate resume structure or relevant professional experience."
     - Set "strengths" to ["None - Invalid Document Format"].
     - Set "gaps" to ["Uploaded document is not a valid resume/CV.", "Fails mandatory ATS parsing standards."].
     - Set "note" to "Automatically rejected by ATS screening due to non-resume file content."
     - Set "suggested_question" to "1. Please submit an official professional CV/Resume.\n2. Why was a non-resume document uploaded for this position?"

2. CRITICAL JOB DESCRIPTION MATCHING (If valid resume):
   - Compare every candidate skill, experience year, qualification, and duty directly against what is demanded in the JOB DESCRIPTION below.
   - If the Job Description requires specific tools, frameworks, certifications, years of experience, or responsibilities that the candidate's resume DOES NOT explicitly document, call them out as specific "gaps" and lower the "skills_match" and "overall_score".
   - Highlight 3 to 5 strengths that directly fulfill the explicit required qualifications or nice-to-haves in the Job Description.
   - Highlight 1 to 3 specific gaps where the candidate falls short of the Job Description's requirements.

3. STRENGTHS & GAPS WRITING STYLE:
   - Write each "strengths" and "gaps" bullet as a short, natural, plain-English sentence — no bullet should read like a template or contain bracketed citations.
   - Strengths example style: "Has PHP listed as a technical skill.", "Strong IT infrastructure and systems administration background.", "Experience coordinating teams and vendor management shows some leadership potential."
   - Gaps example style: "No documented experience with Communication.", "Resume does not mention any cloud platform experience despite this being a core requirement."
   - Do NOT append "[Resume Evidence: ...]" citations to strengths or gaps bullets — keep them clean, natural sentences.

JOB DESCRIPTION:
{$role_description}

CANDIDATE RESUME:
{$stripped_text}

OUTPUT REQUIREMENTS:
You MUST output strictly a JSON object with the following structure (no conversational text outside the JSON):
{
    "name": "Candidate Full Name or Unknown if not found/invalid",
    "relevance_label": "Strong Hire" | "Hire" | "Maybe" | "Do Not Hire",
    "overall_score": 0-100 integer,
    "skills_match": 0-100 integer,
    "exp_match": 0-100 integer,
    "edu_match": 0-100 integer,
    "summary": "3-4 sentences ATS assessment summary detailing document validity and degree of alignment with core Job Description requirements.",
    "education": "Brief summary of degree(s) and institution(s)",
    "experience": "Brief summary of key positions and total years of experience",
    "skills": ["Skill 1", "Skill 2", "Skill 3", "Skill 4", "Skill 5"],
    "strengths": [
        "Short natural-language strength sentence 1, no evidence brackets (e.g. 'Has PHP listed as a technical skill.')",
        "Short natural-language strength sentence 2"
    ],
    "gaps": [
        "Short natural-language gap sentence 1, no evidence brackets (e.g. 'No documented experience with Communication.')"
    ],
    "note": "2-3 sentences ATS pitch outlining why they fit this specific role or reason for rejection.",
    "suggested_question": "2 specific interview questions targeting missing or unverified Job Description requirements."
}

Tone and Constraints:
- Be an objective, strict ATS Screener.
- Write every strength and gap bullet as a short natural sentence with no "[Resume Evidence: ...]" brackets.
- Mark scores critically low (0) if non-resume file is uploaded.
PROMPT;

            $response = call_gemini_api($api_key, $prompt, $source);

            // Extract JSON object using regex substring match
            $data = null;
            if (preg_match('/\{[\s\S]*\}/', $response, $matches)) {
                $json_str = $matches[0];
                $data = json_decode($json_str, true);
            }
            
            if (!is_array($data)) {
                $clean_json = preg_replace('/^```json\s*|\s*```$/i', '', trim($response));
                $data = json_decode($clean_json, true);
            }
            
            if (is_array($data) && !empty($data['summary'])) {
                $data['skills'] = sanitize_resume_skills($data['skills'] ?? []);
                $data['summary'] = preg_replace('/\s*\(Automated draft\s*—\s*AI not configured[^)]*\)/i', '', $data['summary']);
                return $data;
            }
        } catch (Exception $e) {
            error_log("Gemini API Error: " . $e->getMessage());
        }
    }

    // Automatic Fallback Evaluator if API Key is not set or API call fails
    return generate_fallback_snapshot($stripped_text, $role_description);
}

function generate_fallback_resume_review($stripped_text) {
    // Reuse the same "is this actually a resume" validity heuristic as the ATS screener.
    $resume_indicators = ['experience', 'education', 'skills', 'work', 'project', 'university', 'college', 'school', 'history', 'role', 'developer', 'engineer', 'manager', 'specialist', 'assistant', 'resume', 'cv', 'employment', 'responsibilities', 'achievements'];
    $indicator_count = 0;
    foreach ($resume_indicators as $ind) {
        if (stripos($stripped_text, $ind) !== false) {
            $indicator_count++;
        }
    }

    if (strlen(trim($stripped_text)) < 80 || $indicator_count < 3) {
        return [
            'rating_label' => 'Not a Resume',
            'overall_score' => 0,
            'summary' => 'The uploaded file does not appear to contain a standard resume/CV structure (work experience, education, or skills). Please upload a genuine resume in PDF format.',
            'strengths' => [],
            'improvements' => ['Upload a proper resume/CV document with your work experience, education, and skills clearly listed.'],
            'formatting_notes' => 'Unable to assess formatting — the document does not appear to be a resume.',
            'ats_tips' => []
        ];
    }

    $has_summary = (stripos($stripped_text, 'summary') !== false || stripos($stripped_text, 'objective') !== false || stripos($stripped_text, 'profile') !== false);
    $has_metrics = (bool) preg_match('/\d+%|\$\d|\d+\s*(users|clients|customers|projects|years)/i', $stripped_text);
    $has_contact_section = (stripos($stripped_text, '[REDACTED EMAIL]') !== false || stripos($stripped_text, '[REDACTED PHONE]') !== false);
    $word_count = str_word_count($stripped_text);

    $strengths = [];
    $improvements = [];

    if ($has_contact_section) { $strengths[] = "Contact details are present and easy to find."; }
    else { $improvements[] = "Add clear contact information (email and phone number) near the top of the resume."; }

    if ($has_summary) { $strengths[] = "Includes a summary/profile section that frames your experience up front."; }
    else { $improvements[] = "Add a short 2-3 line summary at the top stating your role, years of experience, and key strengths."; }

    if ($has_metrics) { $strengths[] = "Uses numbers and measurable results to back up claims, which stands out to recruiters."; }
    else { $improvements[] = "Add measurable achievements where possible (e.g. 'reduced processing time by 20%' instead of just listing duties)."; }

    if ($word_count > 200) { $strengths[] = "Resume has enough detail to give a recruiter a real sense of your background."; }
    else { $improvements[] = "Resume looks quite short — consider expanding on your responsibilities and achievements in each role."; }

    if (empty($strengths)) { $strengths[] = "Resume contains recognizable resume sections (experience, education, or skills)."; }

    $score = 50 + (count($strengths) * 8) - (count($improvements) * 3);
    $score = max(35, min(85, $score));
    $label = $score >= 75 ? 'Good' : ($score >= 55 ? 'Needs Some Work' : 'Needs Work');

    return [
        'rating_label' => $label,
        'overall_score' => $score,
        'summary' => "Automated check (AI screening not configured): this resume contains standard sections and " . $word_count . " words. " . (count($improvements) > 0 ? "A few areas below would make it stronger." : "It reads as reasonably complete."),
        'strengths' => $strengths,
        'improvements' => $improvements,
        'formatting_notes' => 'Automated check only — for detailed formatting feedback (layout, spacing, section order), AI screening needs to be configured by an administrator.',
        'ats_tips' => [
            'Use standard section headings like "Experience", "Education", and "Skills" so ATS software can parse them correctly.',
            'Avoid tables, columns, or text boxes for key content — some ATS parsers cannot read them.',
            'Save and submit as a text-based PDF, not a scanned image.'
        ]
    ];
}

function generate_resume_review($api_key, $stripped_text, $source = 'resume_checker') {
    if (!empty($api_key)) {
        try {
            $prompt = <<<PROMPT
You are a friendly but expert professional resume coach and ATS (Applicant Tracking System) specialist. A candidate has uploaded their resume and wants honest, constructive feedback to help them improve it — this is NOT being matched against any specific job, so give general resume-quality feedback.

CRITICAL RESUME VALIDITY CHECK:
- First, check if the uploaded document is actually a legitimate resume/CV (containing work experience, education, or professional skills).
- IF THE FILE IS NOT A RESUME (e.g. random text, an essay, invoice, blank/corrupted file):
  - Set "rating_label" to "Not a Resume".
  - Set "overall_score" to 0.
  - Set "summary" to explain the file does not appear to be a resume.
  - Set "strengths" to an empty array, "improvements" to ["Upload a proper resume/CV document."], "ats_tips" to an empty array.

IF IT IS A VALID RESUME, evaluate it on:
1. Overall clarity and structure (is it easy to scan, are sections clearly labeled?)
2. Impact — does it use measurable, quantified achievements instead of vague duty descriptions?
3. Completeness — contact info, summary, experience, education, skills all present?
4. ATS-friendliness — standard section headers, no tables/columns/graphics that break parsing, plain readable format
5. Professional tone and consistency (tense, formatting, spacing)

CANDIDATE RESUME (PII already redacted):
{$stripped_text}

OUTPUT REQUIREMENTS:
Output strictly a JSON object, no text outside the JSON:
{
    "rating_label": "Excellent" | "Strong" | "Good" | "Needs Work" | "Poor" | "Not a Resume",
    "overall_score": 0-100 integer (overall resume quality/polish, NOT a job-match score),
    "summary": "3-4 sentence honest overall impression of the resume as a candidate-facing coach.",
    "strengths": ["Short natural-language strength sentence", "..."],
    "improvements": ["Short, specific, actionable improvement sentence", "..."],
    "formatting_notes": "1-2 sentences on layout/formatting/structure specifically.",
    "ats_tips": ["Short actionable ATS-compatibility tip", "..."]
}

Tone: Encouraging but honest, like a career coach giving real feedback — not generic praise. Be specific to what's actually in the resume.
PROMPT;

            $response = call_gemini_api($api_key, $prompt, $source);

            $data = null;
            if (preg_match('/\{[\s\S]*\}/', $response, $matches)) {
                $data = json_decode($matches[0], true);
            }
            if (!is_array($data)) {
                $clean_json = preg_replace('/^```json\s*|\s*```$/i', '', trim($response));
                $data = json_decode($clean_json, true);
            }
            if (is_array($data) && !empty($data['summary'])) {
                $data['skills'] = sanitize_resume_skills($data['skills'] ?? []);
                $data['summary'] = preg_replace('/\s*\(Automated draft\s*—\s*AI not configured[^)]*\)/i', '', $data['summary']);
                return $data;
            }
        } catch (Exception $e) {
            error_log("Gemini Resume Review Error: " . $e->getMessage());
        }
    }

    return generate_fallback_resume_review($stripped_text);
}

// =========================================================================
// AI RESUME BUILDER — builds a polished resume from candidate-entered
// (or AI-chat-collected) raw notes, rather than reviewing an uploaded file.
// =========================================================================

if (!function_exists('sanitize_resume_skills')) {
    /**
     * Sanitizes raw skills input (which may be a messy comma list, bullet points,
     * or even multi-page career coaching / assessment report text) into a clean,
     * ATS-compliant array of 8-14 concise skill keywords (1-3 words each).
     */
    function sanitize_resume_skills($input) {
        if (empty($input)) return [];

        $raw_items = [];

        if (is_string($input)) {
            $str = $input;

            // 1. If there's an explicit "Skills you can list:" or "Skills:" section in text, extract directly
            if (preg_match('/(?:skills\s+(?:you\s+can\s+list|to\s+highlight)|core\s+skills|technical\s+skills|key\s+skills|competencies)\s*[:\-]\s*(.+?)(?=(?:ESG relevance|Evidence from|Transferable skill|Experience:|\b[A-Z]\.|\z))/is', $str, $sm)) {
                $extracted_section = $sm[1];
                $lines = preg_split('/[\r\n]+|•|\s*[-*·]\s+|;\s*|,\s*/', $extracted_section);
                foreach ($lines as $line) {
                    $line = trim($line, " \t\n\r\0\x0B-•*·");
                    if (!empty($line)) $raw_items[] = $line;
                }
            }

            // 2. Also split the full input by newlines, bullets, semicolons, and commas
            $tokens = preg_split('/[\r\n]+|•|\s*[-*·]\s+|;\s*|,\s*/', $str);
            foreach ($tokens as $t) {
                $raw_items[] = $t;
            }
        } elseif (is_array($input)) {
            foreach ($input as $item) {
                if (is_string($item)) {
                    $sub = preg_split('/[\r\n]+|•|\s*[-*·]\s+|;\s*|,\s*/', $item);
                    foreach ($sub as $st) {
                        $raw_items[] = $st;
                    }
                }
            }
        }

        $clean_skills = [];
        $ignore_patterns = [
            '/strongest transferable/i',
            '/evidence from/i',
            '/you have/i',
            '/you\'ve/i',
            '/you worked/i',
            '/i wouldn\'t/i',
            '/valuable strengths/i',
            '/skills you can list/i',
            '/esg relevance/i',
            '/consider refining/i',
            '/automated draft/i',
            '/not configured/i',
            '/psychology-related/i',
            '/helped organise/i',
            '/supported a \d+/i',
            '/served as/i',
            '/government departments/i',
            '/children and/i',
            '/healthcare professionals/i',
            '/university departments/i',
            '/team-building/i',
            '/drum circle/i',
            '/during your/i'
        ];

        foreach ($raw_items as $item) {
            $item = trim($item, " \t\n\r\0\x0B-•*·:.,");
            if ($item === '') continue;

            // Remove leading numbering or list bullets like "A.", "B.", "1.", "- "
            $item = preg_replace('/^[A-Z0-9]\.\s*/i', '', $item);
            $item = preg_replace('/^[-•*·]\s*/', '', $item);
            $item = trim($item);

            // Skip coaching commentary patterns
            $skip = false;
            foreach ($ignore_patterns as $pat) {
                if (preg_match($pat, $item)) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) continue;

            // Strict ATS skill keyword length and sentence rules:
            // - Under 42 characters
            // - At least 3 characters
            // - At most 5 words
            // - No sentence terminal punctuation
            if (mb_strlen($item) > 42 || mb_strlen($item) < 3) continue;
            if (preg_match('/[.!?:]/', $item)) continue;

            $words = preg_split('/\s+/', $item);
            if (count($words) > 5) continue;

            // Remove filler prefixes
            $item = preg_replace('/^(including|such as|e\.g\.|e\.g|etc)\s+/i', '', $item);
            $item = trim($item);
            if (mb_strlen($item) < 3) continue;

            // Convert common past/present verbs into noun phrases if sensible
            if (preg_match('/^(coordinated|assisted|organised|planned|prepared|supported|managed|liaised|monitored|handling)\b/i', $item)) {
                $item = preg_replace('/^(coordinated|assisted|organised|planned|prepared|supported|managed|liaised|monitored|handling)\s+/i', '', $item);
                if (mb_strlen($item) < 3) continue;
                $item .= ' Management';
            }

            // Title-case
            $normalized = ucwords(strtolower($item));
            // Standardize acronyms
            $normalized = preg_replace_callback('/\b(Esg|Csr|It|Hr|Ui|Ux|Ats|Ai|Seo|Sem|Crm|Pr|Kpt|Upsi)\b/i', function($m) {
                return strtoupper($m[1]);
            }, $normalized);

            // Skip single-word generic stopwords
            if (count($words) === 1 && in_array(strtolower($normalized), ['including', 'schedules', 'invitations', 'recent', 'management', 'general', 'various'])) {
                continue;
            }

            if (!in_array($normalized, $clean_skills, true)) {
                $clean_skills[] = $normalized;
            }

            if (count($clean_skills) >= 14) break;
        }

        if (empty($clean_skills)) {
            $clean_skills = [
                'Project Coordination',
                'Stakeholder Management',
                'Event Operations',
                'Communication & Reporting',
                'Workflow Optimization',
                'Team Collaboration'
            ];
        }

        return array_values($clean_skills);
    }
}

function generate_fallback_resume_document($input) {
    // Professional non-AI ATS templating: clean up notes into crisp, high-impact resume
    $clean_bullets = function($notes) {
        if (empty(trim($notes))) return [];
        $parts = preg_split('/\r\n|\n|(?<=[.;])\s+(?=[A-Z])/', trim($notes));
        $bullets = [];
        foreach ($parts as $p) {
            $p = trim($p, " \t\n\r\0\x0B-•*·");
            if ($p === '') continue;
            $p = ucfirst($p);
            if (!preg_match('/[.!]$/', $p)) $p .= '.';
            $bullets[] = $p;
        }
        return array_slice($bullets, 0, 5);
    };

    $experience = [];
    foreach ($input['experience'] ?? [] as $exp) {
        if (empty($exp['company']) && empty($exp['role'])) continue;
        $experience[] = [
            'company' => $exp['company'] ?? '',
            'role' => $exp['role'] ?? '',
            'duration' => $exp['duration'] ?? '',
            'bullets' => $clean_bullets($exp['notes'] ?? '')
        ];
    }

    $education = [];
    foreach ($input['education'] ?? [] as $edu) {
        if (empty($edu['school']) && empty($edu['degree'])) continue;
        $education[] = [
            'degree' => $edu['degree'] ?? '',
            'school' => $edu['school'] ?? '',
            'year' => $edu['year'] ?? ''
        ];
    }

    $skills = sanitize_resume_skills($input['skills'] ?? '');
    $achievements = $clean_bullets($input['extra_notes'] ?? '');
    $target_title = $input['target_title'] ?? 'Professional';

    if (!empty($input['is_fresh_grad']) || count($experience) === 0) {
        $edu_phrase = '';
        if (!empty($education)) {
            $edu_phrase = ", with a solid academic foundation in " . ($education[0]['degree'] ?: 'their field of study');
            if (!empty($education[0]['school'])) {
                $edu_phrase .= ' from ' . $education[0]['school'];
            }
        }
        $summary = "Motivated and adaptable professional pursuing an impactful role as " . $target_title . $edu_phrase . ". Demonstrates strong analytical, communication, and collaborative problem-solving capabilities, with readiness to apply foundational skills in high-growth environments. Eager to contribute fresh perspectives, learn quickly, and deliver reliable results across operational goals.";
    } else {
        $lead_role = !empty($experience[0]['role']) ? $experience[0]['role'] : 'relevant professional positions';
        $summary = "Results-driven " . $target_title . " with progressive hands-on experience across key roles including " . $lead_role . ". Brings a proven track record of managing end-to-end responsibilities, coordinating multi-stakeholder workflows, and executing projects with precision. Adept at cross-functional communication, process improvement, and delivering measurable organizational impact.";
    }

    return [
        'summary' => $summary,
        'experience' => $experience,
        'education' => $education,
        'skills' => $skills,
        'achievements' => $achievements
    ];
}

function build_resume_prompt($input) {
    $input_json = json_encode($input, JSON_PRETTY_PRINT);
    $fresh_grad_note = '';
    if (!empty($input['is_fresh_grad'])) {
        $fresh_grad_note = "\n\nThis candidate is a fresh graduate with little or no full-time work history. Write the summary around their education, academic projects, and coursework. Treat any listed internships/part-time work as supporting evidence of transferable skills, not a career history. Do not imply years of professional experience the candidate doesn't have. Emphasize potential, foundational skills, and eagerness to start their career.";
    }
    return <<<PROMPT
You are an expert resume writer helping a candidate build a professional resume from their own rough notes. Turn the raw input below into polished, professional resume content — proper grammar, active verbs, concise impact-focused bullet points. Do NOT invent hard facts: never fabricate specific numbers/metrics, employer names, dates, clients, or credentials that the candidate did not state.

ELABORATION — this is important: candidates often type very little (a job title and two or three words). Your job is to expand that into full, complete, professional resume content, not to just tidy up their exact wording:
- If a note is short (e.g. "handled social media"), elaborate it into a fuller bullet point using standard, industry-typical responsibilities and skills that a person in that exact role/title normally performs — describe scope, methods, and purpose in professional language, without inventing specific numbers, results, or names they never mentioned.
- The professional summary should be 3-4 full sentences, not 2, and should read as complete and confident even when the underlying notes are sparse — draw on what's typical/expected for the stated target role and any implied seniority, rather than staying literally minimal.
- Never respond with something that reads as thin, generic, or obviously under-written just because the input was short. When in doubt, add reasonable professional depth and context around what the candidate gave you rather than leaving it terse.
- This applies to experience bullets, the summary, and achievement entries alike. It does not apply to hard facts (numbers, company names, dates, certifications) — leave those exactly as given, or omit them, never invent them.

CANDIDATE'S TARGET ROLE AND RAW NOTES (JSON):
{$input_json}

OUTPUT REQUIREMENTS:
Output strictly a JSON object, no text outside the JSON:
{
    "summary": "3-4 sentence professional summary tailored to their target role, elaborated with standard professional context for that role — based only on the facts given, never inventing metrics or employers.",
    "experience": [
        {
            "company": "as given",
            "role": "as given",
            "duration": "as given",
            "bullets": ["Fully elaborated, professional bullet point expanded from their rough notes using standard responsibilities for this role — no invented numbers/facts", "..."]
        }
    ],
    "education": [
        {"degree": "as given", "school": "as given", "year": "as given"}
    ],
    "skills": ["cleaned up skill", "..."],
    "achievements": ["cleaned up certification/award/achievement/language from extra_notes, one per entry", "..."]
}

Keep the same number of experience/education entries as given in the input, in the same order. Each experience entry should have 3-5 bullet points (elaborate rather than truncate). The "achievements" array must be built ONLY from the candidate's "extra_notes" field (certifications, achievements, awards, languages, or anything else they listed there) — split it into short, individually-listable entries. If extra_notes is empty, output an empty array for "achievements". Do not put achievements content into "summary" or "skills" instead — it belongs in its own "achievements" array.{$fresh_grad_note}
PROMPT;
}

function generate_resume_document($api_key, $input, $source = 'resume_builder') {
    if (!empty($api_key)) {
        try {
            $prompt = build_resume_prompt($input);

            $response = call_gemini_api($api_key, $prompt, $source);

            $data = null;
            if (preg_match('/\{[\s\S]*\}/', $response, $matches)) {
                $data = json_decode($matches[0], true);
            }
            if (!is_array($data)) {
                $clean_json = preg_replace('/^```json\s*|\s*```$/i', '', trim($response));
                $data = json_decode($clean_json, true);
            }
            if (is_array($data) && !empty($data['summary'])) {
                $data['skills'] = sanitize_resume_skills($data['skills'] ?? []);
                $data['summary'] = preg_replace('/\s*\(Automated draft\s*—\s*AI not configured[^)]*\)/i', '', $data['summary']);
                return $data;
            }
        } catch (Exception $e) {
            error_log("Gemini Resume Builder Error: " . $e->getMessage());
        }
    }

    return generate_fallback_resume_document($input);
}

function ai_resume_assist_bullets($api_key, $role, $company, $rough_notes, $source = 'resume_assist') {
    // Lightweight "help me word this" assist used by the AI chat-assist button
    // on an individual experience block. Returns a short list of suggested
    // bullet points the candidate can insert into their notes.
    if (!empty($api_key) && trim($rough_notes) !== '') {
        try {
            $prompt = <<<PROMPT
A candidate is writing their resume and typed these rough notes about their role as "{$role}" at "{$company}":

"{$rough_notes}"

Rewrite and ELABORATE this into 3 full, professional resume bullet points — even if the notes are just a few words, expand them using standard, typical responsibilities and skills for a "{$role}" role (active verbs, complete professional phrasing, not just a tidied-up version of the exact words given). Do not invent specific numbers/metrics, employer names, or facts beyond what's stated — elaborate on scope and context, not on hard facts. Output strictly a JSON array of strings, nothing else, e.g. ["...", "...", "..."]
PROMPT;
            $response = call_gemini_api($api_key, $prompt, $source);
            $data = null;
            if (preg_match('/\[[\s\S]*\]/', $response, $matches)) {
                $data = json_decode($matches[0], true);
            }
            if (!is_array($data)) {
                $clean_json = preg_replace('/^```json\s*|\s*```$/i', '', trim($response));
                $data = json_decode($clean_json, true);
            }
            if (is_array($data) && !empty($data)) {
                return array_slice(array_values($data), 0, 4);
            }
        } catch (Exception $e) {
            error_log("Gemini Resume Assist Error: " . $e->getMessage());
        }
    }

    // Fallback: just split rough notes into cleaned-up sentences.
    $parts = preg_split('/\r\n|\n|,|;/', trim($rough_notes));
    $bullets = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') continue;
        $p = ucfirst($p);
        if (!preg_match('/[.!]$/', $p)) $p .= '.';
        $bullets[] = $p;
    }
    return array_slice($bullets, 0, 4);
}

// =========================================================================
// AUTO GENERATE RESUME FROM UPLOADED RESUME FILE (1-Click ATS Transformation)
// =========================================================================

function build_auto_resume_prompt($raw_text, $target_title = '', $user_info = []) {
    $user_name = $user_info['name'] ?? '';
    $user_email = $user_info['email'] ?? '';
    $user_phone = $user_info['phone'] ?? '';
    $user_location = $user_info['location'] ?? '';

    $target_hint = !empty($target_title)
        ? "Target Job Title requested by candidate: \"{$target_title}\"."
        : "Target Job Title: Infer the best ATS-aligned professional target title from the candidate's career history.";

    // Truncate raw text if extremely large to stay well within tokens
    $clean_text = mb_substr(trim($raw_text), 0, 16000);

    return <<<PROMPT
You are a World-Class Executive Resume Writer and Applicant Tracking System (ATS) Optimization Specialist.
Transform the candidate's uploaded resume text into a top-tier, polished, ATS-compliant resume document.

{$target_hint}

CANDIDATE'S PROFILE HINTS (use if not present in resume text):
Name: {$user_name}
Email: {$user_email}
Phone: {$user_phone}
Location: {$user_location}

UPLOADED RESUME RAW TEXT:
=== BEGIN ===
{$clean_text}
=== END ===

CRITICAL ATS TRANSFORMATION RULES:
1. STRICT 1-2 PAGE BUDGET:
   - The resulting resume MUST fit cleanly within 1 to 2 standard A4 pages.
   - Be concise, impactful, and direct. Avoid verbose paragraphs or repetitive explanations.
2. DISCARD ALL CAREER COACHING / ADVISORY TEXT:
   - The uploaded text may contain career assessment reports, mentor notes, or evaluation feedback (e.g. "Strongest transferable skill You have", "Evidence from your experience:", "Skills you can list:", "ESG relevance:", "I wouldn't yet claim expertise in...").
   - Completely STRIP all third-party advice, coaching feedback, and evaluative commentary. Extract ONLY the candidate's real professional history, actual duties, genuine education, and true skills.
3. CONTACT DETAILS:
   - Extract Full Name, Professional Email, Phone Number, and Location (City, Country).
4. TARGET TITLE:
   - Determine a crisp, standard, ATS-aligned role title (e.g. "Executive (Communications & Projects)", "Software Engineer").
5. PROFESSIONAL SUMMARY:
   - Write a strong, 3-4 sentence ATS executive summary showcasing core domain proficiencies, operational strengths, and professional impact.
   - Do NOT include any meta-commentary, disclaimers, or bracketed notes.
6. WORK EXPERIENCE:
   - Extract positions in reverse chronological order (maximum 5 most recent/relevant roles).
   - For each role provide: Company Name, Role/Position Title, Duration (e.g. "2024 – 2025" or "Jan 2023 – Present").
   - Write 3 to 5 high-impact bullet points per role, each beginning with a strong past/present action verb (e.g. "Coordinated", "Spearheaded", "Facilitated", "Streamlined", "Administered").
   - Limit each bullet point to 1-2 concise lines.
7. EDUCATION:
   - Extract degrees/diplomas, academic institutions, and graduation years.
8. CORE SKILLS & COMPETENCIES (CRUCIAL):
   - "skills" MUST be a JSON array of 8 to 14 concise, keyword-rich technical and transferable skill phrases (1-3 words each, e.g. ["Project Coordination", "Stakeholder Management", "Event Planning", "Operations Support", "Reporting & Documentation"]).
   - NEVER put sentences, paragraphs, or coaching bullet points into the skills array. Each skill must be strictly under 35 characters.
9. KEY ACHIEVEMENTS & CERTIFICATIONS:
   - Extract certifications, awards, or notable projects as short 1-line strings (maximum 3-4 items).

OUTPUT REQUIREMENTS:
Respond STRICTLY with valid JSON. Do not write text before or after the JSON:
{
    "full_name": "Full Name",
    "contact_email": "email@example.com",
    "contact_phone": "+60 12-345 6789",
    "location": "City, Country",
    "target_title": "Target Role Title",
    "summary": "3-4 sentence professional ATS executive summary...",
    "experience": [
        {
            "company": "Company Name",
            "role": "Role / Position",
            "duration": "Duration / Dates",
            "bullets": [
                "Action verb bullet point...",
                "Action verb bullet point..."
            ]
        }
    ],
    "education": [
        {"degree": "Degree / Qualification", "school": "University / Institution", "year": "Year"}
    ],
    "skills": ["Skill 1", "Skill 2", "Skill 3", "Skill 4", "Skill 5"],
    "achievements": ["Certification or Achievement 1"]
}
PROMPT;
}

function generate_fallback_auto_resume($raw_text, $target_title = '', $user_info = []) {
    $email = $user_info['email'] ?? '';
    if (preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $raw_text, $m)) {
        $email = $m[0];
    }
    $phone = $user_info['phone'] ?? '';
    if (preg_match('/(?:\+?6?01[0-9]{1}-?[0-9]{7,8})|(?:\+?[0-9]{2,3}[- ]?[0-9]{6,10})/', $raw_text, $m)) {
        $phone = $m[0];
    }
    $name = $user_info['name'] ?? '';
    $lines = array_values(array_filter(array_map('trim', explode("\n", $raw_text))));
    if (empty($name) && !empty($lines[0]) && strlen($lines[0]) < 40 && !preg_match('/@|http|\.com|phone|resume/i', $lines[0])) {
        $name = $lines[0];
    }
    if (empty($name)) $name = 'Professional Candidate';

    $location = $user_info['location'] ?? '';
    if (empty($location)) {
        if (preg_match('/(?:Kuala Lumpur|Petaling Jaya|Penang|Selangor|Johor Bahru|Cyberjaya|Putrajaya|Shah Alam|Malaysia)/i', $raw_text, $lm)) {
            $location = $lm[0];
        } else {
            $location = 'Malaysia';
        }
    }

    $title = !empty($target_title) ? $target_title : '';
    if (empty($title)) {
        if (!empty($lines[1]) && strlen($lines[1]) < 50 && !preg_match('/@|http|\.com|phone|\+?[0-9]/i', $lines[1])) {
            $title = $lines[1];
        } else {
            $title = 'Executive Specialist';
        }
    }

    // Parse structured work experience and education blocks
    $experience = [];
    $education = [];
    $current_exp = null;
    $in_edu_section = false;
    $in_skills_section = false;
    $skills_raw_collector = '';

    // Date range pattern: e.g. "2024 - 2025", "2025-2026", "Jan 2020 – Dec 2022", "August 2022 - January 2023", "2026 - recent", "MAY - JULY 2024"
    $date_regex = '/(?:\b(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\s*(?:[-–—to\s]+)\s*)?(?:(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\s+)?(19\d\d|20\d\d)\s*[-–—to\s]+\s*(?:(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\s+)?(19\d\d|20\d\d|present|current|recent)\b|(?:\b(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\s*[-–—to\s]+\s*(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\s+(19\d\d|20\d\d))\b/i';

    foreach ($lines as $idx => $line) {
        $trim_line = trim($line);
        if ($trim_line === '') continue;

        // Detect section headers
        if (preg_match('/^(?:EDUCATION|ACADEMIC BACKGROUND|EDUCATION & CREDENTIALS)/i', $trim_line)) {
            if ($current_exp) {
                $experience[] = $current_exp;
                $current_exp = null;
            }
            $in_edu_section = true;
            $in_skills_section = false;
            continue;
        }

        if (preg_match('/^(?:CORE SKILLS|SKILLS|COMPETENCIES|TECHNICAL SKILLS)/i', $trim_line)) {
            if ($current_exp) {
                $experience[] = $current_exp;
                $current_exp = null;
            }
            $in_edu_section = false;
            $in_skills_section = true;
            $skills_raw_collector .= ' ' . $trim_line;
            continue;
        }

        if ($in_skills_section) {
            $skills_raw_collector .= ' ' . $trim_line;
            continue;
        }

        if ($in_edu_section) {
            if (preg_match('/(bachelor|master|diploma|degree|phd|certificate|spm|stpm|matriculation)/i', $trim_line)) {
                $year = '';
                if (preg_match('/\b(19\d\d|20\d\d)\b/', $trim_line, $ym)) {
                    $year = $ym[1];
                    $trim_line = trim(str_replace($year, '', $trim_line));
                }
                $school = 'Higher Education Institution';
                if (!empty($lines[$idx + 1]) && !preg_match('/(bachelor|master|diploma|degree|phd|spm|skills)/i', $lines[$idx + 1])) {
                    $school = trim($lines[$idx + 1]);
                }
                $education[] = [
                    'degree' => $trim_line,
                    'school' => $school,
                    'year' => $year ?: date('Y')
                ];
            }
            continue;
        }

        // Job header with dates
        if (preg_match($date_regex, $trim_line, $dm) && !preg_match('/^[-•*·]/', $trim_line)) {
            if ($current_exp) {
                $experience[] = $current_exp;
                $current_exp = null;
            }

            $duration = $dm[0];
            $clean_header = trim(str_replace($duration, '', $trim_line), " \t\n\r\0\x0B-–—");

            $role = $clean_header;
            $company = '';
            if (strpos($clean_header, '—') !== false) {
                $parts = explode('—', $clean_header, 2);
                $role = trim($parts[0]);
                $company = trim($parts[1]);
            } elseif (strpos($clean_header, '-') !== false) {
                $parts = explode('-', $clean_header, 2);
                $role = trim($parts[0]);
                $company = trim($parts[1]);
            } elseif (strpos($clean_header, ' at ') !== false) {
                $parts = explode(' at ', $clean_header, 2);
                $role = trim($parts[0]);
                $company = trim($parts[1]);
            }

            $current_exp = [
                'role' => $role ?: 'Professional Role',
                'company' => $company ?: 'Organization',
                'duration' => $duration,
                'bullets' => []
            ];
            continue;
        }

        // Bullet point detection
        if (preg_match('/^[-•*·]\s*(.+)/', $trim_line, $bm)) {
            $bullet = ucfirst(trim($bm[1]));
            if (!preg_match('/[.!?]$/', $bullet)) $bullet .= '.';
            if ($current_exp) {
                if (count($current_exp['bullets']) < 5) {
                    $current_exp['bullets'][] = $bullet;
                }
            }
        }
    }

    if ($current_exp) {
        $experience[] = $current_exp;
    }

    if (empty($experience)) {
        // Fallback single experience block if no headers matched
        $bullets = [];
        foreach ($lines as $line) {
            if (preg_match('/^[-•*·]\s*(.+)/', $line, $bm)) {
                $b = ucfirst(trim($bm[1]));
                if (!preg_match('/[.!?]$/', $b)) $b .= '.';
                if (count($bullets) < 5) $bullets[] = $b;
            }
        }
        $experience[] = [
            'company' => 'Professional Career Experience',
            'role' => $title,
            'duration' => 'Recent',
            'bullets' => !empty($bullets) ? $bullets : [
                "Managed daily responsibilities and collaborated with cross-functional team members to achieve operational targets.",
                "Utilized modern tools and industry methodologies to deliver high-quality deliverables on schedule.",
                "Streamlined task workflows and contributed to ongoing team productivity enhancements."
            ]
        ];
    }

    if (empty($education)) {
        $education[] = [
            'degree' => 'Higher Education Degree / Diploma',
            'school' => 'Accredited Institution',
            'year' => date('Y')
        ];
    }

    // Clean and sanitize skills
    $skills = sanitize_resume_skills($skills_raw_collector ?: $raw_text);

    $lead_role = !empty($experience[0]['role']) ? $experience[0]['role'] : $title;
    $summary = "Results-driven {$title} with demonstrated expertise in operational coordination, cross-functional project management, and stakeholder engagement. Proven background across roles including {$lead_role}, with a strong commitment to quality standards, process efficiency, and organizational goals. Adept at liaising with multidisciplinary teams, problem-solving, and driving high-impact deliverables.";

    return [
        'full_name' => $name,
        'contact_email' => $email,
        'contact_phone' => $phone,
        'location' => $location,
        'target_title' => $title,
        'summary' => $summary,
        'experience' => array_slice($experience, 0, 5),
        'education' => array_slice($education, 0, 3),
        'skills' => $skills,
        'achievements' => []
    ];
}

function auto_generate_resume_document($api_key, $raw_text, $target_title = '', $user_info = [], $source = 'resume_builder_auto') {
    if (!empty($api_key) && trim($raw_text) !== '') {
        try {
            $prompt = build_auto_resume_prompt($raw_text, $target_title, $user_info);
            $response = call_gemini_api($api_key, $prompt, $source);

            $data = null;
            if (preg_match('/\{[\s\S]*\}/', $response, $matches)) {
                $data = json_decode($matches[0], true);
            }
            if (!is_array($data)) {
                $clean_json = preg_replace('/^```json\s*|\s*```$/i', '', trim($response));
                $data = json_decode($clean_json, true);
            }
            if (is_array($data) && !empty($data['summary'])) {
                $data['experience'] = $data['experience'] ?? [];
                // Cap bullets to max 5 per experience
                foreach ($data['experience'] as &$exp) {
                    if (!empty($exp['bullets']) && is_array($exp['bullets'])) {
                        $exp['bullets'] = array_slice($exp['bullets'], 0, 5);
                    }
                }
                unset($exp);

                $data['education'] = $data['education'] ?? [];
                // Always sanitize skills to guaranteed ATS keywords
                $data['skills'] = sanitize_resume_skills($data['skills'] ?? []);
                $data['achievements'] = $data['achievements'] ?? [];
                $data['full_name'] = !empty($data['full_name']) ? $data['full_name'] : ($user_info['name'] ?? 'Candidate');
                $data['contact_email'] = !empty($data['contact_email']) ? $data['contact_email'] : ($user_info['email'] ?? '');
                $data['contact_phone'] = !empty($data['contact_phone']) ? $data['contact_phone'] : ($user_info['phone'] ?? '');
                $data['location'] = !empty($data['location']) ? $data['location'] : ($user_info['location'] ?? 'Malaysia');
                $data['target_title'] = !empty($data['target_title']) ? $data['target_title'] : ($target_title ?: 'Professional');
                // Remove any bracketed disclaimers if present
                $data['summary'] = preg_replace('/\s*\(Automated draft\s*—\s*AI not configured[^)]*\)/i', '', $data['summary']);
                return $data;
            }
        } catch (Exception $e) {
            error_log("Gemini Auto Resume Builder Error: " . $e->getMessage());
        }
    }

    return generate_fallback_auto_resume($raw_text, $target_title, $user_info);
}

?>
