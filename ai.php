<?php
// ai.php

function get_api_key() {
    // Always re-read config.json rather than trusting a cached copy in
    // $_SESSION. The previous version returned the cached value forever
    // once a session had it, so a long-lived login (an HR tab left open for
    // days, an admin session nobody closed) kept using whatever key/model
    // was cached at first load — even after the key was rotated or the
    // config re-saved by someone else. That looked exactly like "the API
    // key stops working after the site sits idle for a while, and only
    // re-saving the config in that browser tab fixes it" (re-saving calls
    // save_api_config(), which force-refreshes that session's cache). A
    // local disk read is cheap, so just do it every time and keep every
    // session in sync with whatever is actually saved.
    $config_file = __DIR__ . '/config.json';
    if (file_exists($config_file)) {
        $json = json_decode(file_get_contents($config_file), true);
        if (!empty($json['api_key'])) {
            $_SESSION['api_key'] = $json['api_key'];
            $_SESSION['ai_model'] = $json['ai_model'] ?? ($_SESSION['ai_model'] ?? 'gemini-3.7-flash');
            return $json['api_key'];
        }
    }
    // No key on disk — fall back to a session-only value if one was set
    // this request (e.g. immediately after save_api_config(), before a
    // redirect reloads the page) rather than always returning null.
    return $_SESSION['api_key'] ?? null;
}

function save_api_config($key, $model = 'gemini-3.6-flash') {
    $_SESSION['api_key'] = $key;
    $_SESSION['ai_model'] = $model;
    $config_file = __DIR__ . '/config.json';

    // Read-modify-write, not overwrite: config.json also holds the SMTP
    // settings (smtp_host/user/pass/port/from) saved from the admin's
    // Settings page. A previous version of this function replaced the whole
    // file with just {api_key, ai_model}, which silently deleted the SMTP
    // config every time someone saved the Gemini API key here — breaking
    // OTP/verification emails until an admin happened to re-save SMTP
    // settings separately. Preserve every other existing key.
    $curr_config = [];
    if (file_exists($config_file)) {
        $decoded = json_decode(file_get_contents($config_file), true);
        if (is_array($decoded)) {
            $curr_config = $decoded;
        }
    }
    $curr_config['api_key'] = $key;
    $curr_config['ai_model'] = $model;

    file_put_contents($config_file, json_encode($curr_config, JSON_PRETTY_PRINT));
}

function call_gemini_api($api_key, $prompt) {
    $selected_model = $_SESSION['ai_model'] ?? 'gemini-3.6-flash';
    $models_to_try = array_unique([
        $selected_model,
        'gemini-3.6-flash',
        'gemini-3.5-flash',
        'gemini-flash-latest',
        'gemini-3.7-flash'
    ]);

    $last_error = null;
    foreach ($models_to_try as $model) {
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
                    return $text_output;
                }
            } else {
                $msg = !empty($curl_err) ? $curl_err : "HTTP $http_code Response";
                $last_error = "Gemini API Call Failed ($model): " . $msg;
                error_log($last_error);
            }
        } catch (Throwable $t) {
            error_log("Gemini cURL Throwable: " . $t->getMessage());
        }
    }

    return null;
}

/**
 * Actually tests the configured Gemini API key/model against the live API
 * @return array ['status' => 'active'|'not_configured'|'invalid_key'|'model_not_found'|'unreachable'|'error', 'message' => string]
 */
function check_api_key_status($api_key, $model = 'gemini-3.7-flash') {
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

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($http_code === 0) {
        return ['status' => 'unreachable', 'message' => $curl_err ?: 'Could not reach the Google Gemini API.'];
    }
    if ($http_code === 200) {
        return ['status' => 'active', 'message' => 'Gemini API key and model are active & working.'];
    }

    $data = json_decode($response, true);
    $err_msg = $data['error']['message'] ?? "Unexpected HTTP $http_code response.";

    if ($http_code === 400 || $http_code === 403 || $http_code === 401) {
        if (stripos($err_msg, 'API_KEY_INVALID') !== false || stripos($err_msg, 'API key not valid') !== false || $http_code === 401) {
            return ['status' => 'invalid_key', 'message' => 'Invalid Gemini API key provided.'];
        }
        return ['status' => 'invalid_key', 'message' => $err_msg];
    }
    if ($http_code === 404) {
        return ['status' => 'model_not_found', 'message' => "Model '{$model}' not available: " . $err_msg];
    }

    return ['status' => 'error', 'message' => $err_msg];
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

function generate_snapshot($api_key, $stripped_text, $role_description) {
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

            $response = call_gemini_api($api_key, $prompt);
            
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

function generate_resume_review($api_key, $stripped_text) {
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

            $response = call_gemini_api($api_key, $prompt);

            $data = null;
            if (preg_match('/\{[\s\S]*\}/', $response, $matches)) {
                $data = json_decode($matches[0], true);
            }
            if (!is_array($data)) {
                $clean_json = preg_replace('/^```json\s*|\s*```$/i', '', trim($response));
                $data = json_decode($clean_json, true);
            }
            if (is_array($data) && !empty($data['summary'])) {
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

function generate_fallback_resume_document($input) {
    // Basic non-AI templating: lightly clean up whatever the candidate typed
    // so the feature still works end-to-end without an API key configured.
    $clean_bullets = function($notes) {
        if (empty(trim($notes))) return [];
        $parts = preg_split('/\r\n|\n|(?<=[.;])\s+(?=[A-Z])/', trim($notes));
        $bullets = [];
        foreach ($parts as $p) {
            $p = trim($p, " \t\n\r\0\x0B-•");
            if ($p === '') continue;
            $p = ucfirst($p);
            if (!preg_match('/[.!]$/', $p)) $p .= '.';
            $bullets[] = $p;
        }
        return array_slice($bullets, 0, 6);
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

    $skills = array_values(array_filter(array_map('trim', explode(',', $input['skills'] ?? ''))));
    $target_title = $input['target_title'] ?? 'Professional';

    return [
        'summary' => "Motivated " . $target_title . " with hands-on experience across " . (count($experience) > 0 ? "roles including " . ($experience[0]['role'] ?: 'recent positions') : "prior positions") . ". Automated draft (AI not configured) — consider refining this summary further.",
        'experience' => $experience,
        'education' => $education,
        'skills' => $skills
    ];
}

function build_resume_prompt($input) {
    $input_json = json_encode($input, JSON_PRETTY_PRINT);
    $fresh_grad_note = '';
    if (!empty($input['is_fresh_grad'])) {
        $fresh_grad_note = "\n\nThis candidate is a fresh graduate with little or no full-time work history. Write the summary around their education, academic projects, and coursework. Treat any listed internships/part-time work as supporting evidence of transferable skills, not a career history. Do not imply years of professional experience the candidate doesn't have. Emphasize potential, foundational skills, and eagerness to start their career.";
    }
    return <<<PROMPT
You are an expert resume writer helping a candidate build a professional resume from their own rough notes. Turn the raw input below into polished, professional resume content — proper grammar, active verbs, concise impact-focused bullet points. Do NOT invent facts, companies, numbers, or achievements that are not implied by the candidate's notes — only rephrase and structure what they gave you.

CANDIDATE'S TARGET ROLE AND RAW NOTES (JSON):
{$input_json}

OUTPUT REQUIREMENTS:
Output strictly a JSON object, no text outside the JSON:
{
    "summary": "2-3 sentence professional summary tailored to their target role, based only on the info given.",
    "experience": [
        {
            "company": "as given",
            "role": "as given",
            "duration": "as given",
            "bullets": ["Polished, professional bullet point rewritten from their rough notes", "..."]
        }
    ],
    "education": [
        {"degree": "as given", "school": "as given", "year": "as given"}
    ],
    "skills": ["cleaned up skill", "..."]
}

Keep the same number of experience/education entries as given in the input, in the same order. Each experience entry should have 2-4 bullet points.{$fresh_grad_note}
PROMPT;
}

function generate_resume_document($api_key, $input) {
    if (!empty($api_key)) {
        try {
            $prompt = build_resume_prompt($input);

            $response = call_gemini_api($api_key, $prompt);

            $data = null;
            if (preg_match('/\{[\s\S]*\}/', $response, $matches)) {
                $data = json_decode($matches[0], true);
            }
            if (!is_array($data)) {
                $clean_json = preg_replace('/^```json\s*|\s*```$/i', '', trim($response));
                $data = json_decode($clean_json, true);
            }
            if (is_array($data) && !empty($data['summary'])) {
                return $data;
            }
        } catch (Exception $e) {
            error_log("Gemini Resume Builder Error: " . $e->getMessage());
        }
    }

    return generate_fallback_resume_document($input);
}

function ai_resume_assist_bullets($api_key, $role, $company, $rough_notes) {
    // Lightweight "help me word this" assist used by the AI chat-assist button
    // on an individual experience block. Returns a short list of suggested
    // bullet points the candidate can insert into their notes.
    if (!empty($api_key) && trim($rough_notes) !== '') {
        try {
            $prompt = <<<PROMPT
A candidate is writing their resume and typed these rough notes about their role as "{$role}" at "{$company}":

"{$rough_notes}"

Rewrite this into 3 short, professional resume bullet points (active verbs, concise, no invented facts/numbers beyond what's stated). Output strictly a JSON array of strings, nothing else, e.g. ["...", "...", "..."]
PROMPT;
            $response = call_gemini_api($api_key, $prompt);
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
?>
