<?php
// ai.php

function get_api_key() {
    if (!empty($_SESSION['api_key'])) {
        return $_SESSION['api_key'];
    }
    $config_file = __DIR__ . '/config.json';
    if (file_exists($config_file)) {
        $json = json_decode(file_get_contents($config_file), true);
        if (!empty($json['api_key'])) {
            $_SESSION['api_key'] = $json['api_key'];
            if (!empty($json['ai_model'])) {
                $_SESSION['ai_model'] = $json['ai_model'];
            }
            return $json['api_key'];
        }
    }
    return null;
}

function save_api_config($key, $model = 'claude-sonnet-5') {
    $_SESSION['api_key'] = $key;
    $_SESSION['ai_model'] = $model;
    $config_file = __DIR__ . '/config.json';
    file_put_contents($config_file, json_encode([
        'api_key' => $key,
        'ai_model' => $model
    ], JSON_PRETTY_PRINT));
}

function call_anthropic_claude($api_key, $prompt) {
    $url = 'https://api.anthropic.com/v1/messages';
    
    $selected_model = $_SESSION['ai_model'] ?? 'claude-sonnet-5';
    $models_to_try = array_unique([
        $selected_model,
        'claude-sonnet-5',
        'claude-haiku-4-5-20251001'
    ]);
    
    $headers = [
        'Content-Type: application/json',
        'x-api-key: ' . trim($api_key),
        'anthropic-version: 2023-06-01'
    ];
    
    $last_error = null;
    foreach ($models_to_try as $model) {
        $payload = [
            'model' => $model,
            'max_tokens' => 2500,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ]
        ];
        
        try {
            $ch = @curl_init($url);
            if ($ch === false) continue;

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_err = curl_error($ch);
            curl_close($ch);
            
            if ($http_code === 200 && !empty($response)) {
                $data = json_decode($response, true);
                if (!empty($data['content'][0]['text'])) {
                    return $data['content'][0]['text'];
                }
            } else {
                $msg = !empty($curl_err) ? $curl_err : "HTTP $http_code Response";
                $last_error = "Anthropic API Call Failed ($model): " . $msg;
                error_log($last_error);
            }
        } catch (Throwable $t) {
            error_log("Anthropic cURL Throwable: " . $t->getMessage());
        }
    }

    return null;
}

/**
 * Actually tests the configured Anthropic API key/model against the live API
 * (unlike a bare network ping, this catches an invalid key, a disabled
 * organization, or a dead/inaccessible model name — all of which return a
 * normal HTTP response, not a connection failure).
 * @return array ['status' => 'active'|'not_configured'|'invalid_key'|'org_disabled'|'model_not_found'|'unreachable'|'error', 'message' => string]
 */
function check_api_key_status($api_key, $model = 'claude-sonnet-5') {
    if (empty($api_key)) {
        return ['status' => 'not_configured', 'message' => 'No Anthropic API key has been configured yet.'];
    }

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'model' => $model,
        'max_tokens' => 1,
        'messages' => [['role' => 'user', 'content' => 'ping']]
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'x-api-key: ' . trim($api_key),
        'anthropic-version: 2023-06-01'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($http_code === 0) {
        return ['status' => 'unreachable', 'message' => $curl_err ?: 'Could not reach the Anthropic API.'];
    }
    if ($http_code === 200) {
        return ['status' => 'active', 'message' => 'API key and model are working.'];
    }

    $data = json_decode($response, true);
    $err_msg = $data['error']['message'] ?? "Unexpected HTTP $http_code response.";

    if ($http_code === 401) {
        return ['status' => 'invalid_key', 'message' => $err_msg];
    }
    if ($http_code === 404) {
        return ['status' => 'model_not_found', 'message' => $err_msg];
    }
    if ($http_code === 400 && stripos($err_msg, 'disabled') !== false) {
        return ['status' => 'org_disabled', 'message' => $err_msg];
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

            $response = call_anthropic_claude($api_key, $prompt);
            
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
            error_log("Claude API Error: " . $e->getMessage());
        }
    }

    // Automatic Fallback Evaluator if API Key is not set or API call fails
    return generate_fallback_snapshot($stripped_text, $role_description);
}

