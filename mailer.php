<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/questionnaire_helpers.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

/**
 * Creates and configures a PHPMailer instance with anti-spam & inbox-deliverability best practices:
 * 
 * Anti-Spam Measures Applied:
 * 1. Authenticated SMTP with TLS/SSL encryption (prevents SPF/DKIM spoofing flags).
 * 2. Proper envelope sender (Return-Path) matching the From address (required for SPF pass).
 * 3. Valid FQDN Hostname set so Message-ID contains a legitimate domain instead of @localhost / PC name.
 * 4. Suppressed default X-Mailer signature to avoid automated spam score triggers.
 * 5. Added RFC 3834 transactional headers (Auto-Submitted, X-Auto-Response-Suppress).
 * 6. UTF-8 Base64 encoding to prevent character corruption.
 * 7. From & SMTP-User alignment to satisfy DMARC and avoid phishing warnings.
 *
 * @param string $default_from_name
 * @param string $reply_to_email
 * @param string $reply_to_name
 * @return array ['mail' => PHPMailer, 'config' => array, 'is_smtp' => bool, 'from_email' => string, 'from_name' => string]
 */
function create_base_mailer($default_from_name = 'KERIA Recruitment Team', $reply_to_email = '', $reply_to_name = '') {
    $mail = new PHPMailer(true);

    $config_file = __DIR__ . '/config.json';
    $config = [];
    if (file_exists($config_file)) {
        $config = json_decode(file_get_contents($config_file), true) ?: [];
    }

    $is_smtp = !empty($config['smtp_host']);
    if ($is_smtp) {
        $mail->isSMTP();
        $mail->Host       = trim($config['smtp_host']);
        $mail->SMTPAuth   = true;
        $mail->Username   = trim($config['smtp_user'] ?? '');
        $mail->Password   = trim($config['smtp_pass'] ?? '');

        $port = !empty($config['smtp_port']) ? (int)$config['smtp_port'] : 587;
        $mail->Port = $port;

        $secure = !empty($config['smtp_secure']) ? strtolower(trim($config['smtp_secure'])) : '';
        if ($secure === 'ssl' || ($secure === '' && $port === 465)) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($secure === 'none') {
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }
    } else {
        // Fallback to native mail() - note: without an authenticated SMTP server,
        // consumer email providers (Gmail, Yahoo, Outlook) will often categorize this as spam.
        $mail->isMail();
    }

    // Local environment SSL handling
    $mail->SMTPOptions = array(
        'ssl' => array(
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        )
    );

    // Resolve From Address:
    // IMPORTANT FOR SPAM PREVENTION:
    // With external SMTP (Gmail, Outlook, Yahoo, SendGrid), the From address must
    // align with the authenticated SMTP account or the domain, otherwise DMARC/SPF
    // checks will fail and route the message to Spam/Junk.
    $from_email = '';
    if (!empty($config['smtp_from']) && filter_var($config['smtp_from'], FILTER_VALIDATE_EMAIL)) {
        $from_email = trim($config['smtp_from']);
    } elseif (!empty($config['smtp_user']) && filter_var($config['smtp_user'], FILTER_VALIDATE_EMAIL)) {
        $from_email = trim($config['smtp_user']);
    } else {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $host = preg_replace('/:\d+$/', '', $host);
        $from_email = ($host !== 'localhost' && strpos($host, '.') !== false) ? "no-reply@{$host}" : 'no-reply@keria.com';
    }

    $from_name = !empty($config['smtp_from_name']) ? trim($config['smtp_from_name']) : $default_from_name;
    $mail->setFrom($from_email, $from_name);

    // Anti-Spam Measure 1: Set Envelope Sender (Return-Path) matching From email
    $mail->Sender = $from_email;

    // Anti-Spam Measure 2: Set valid domain Hostname for Message-ID generation
    // (avoids <...unique...@localhost> or Windows computer name in Message-ID)
    $domain = '';
    if (strpos($from_email, '@') !== false) {
        $domain = substr(strrchr($from_email, '@'), 1);
    }
    if (!empty($domain) && strpos($domain, '.') !== false) {
        $mail->Hostname = $domain;
    }

    // Anti-Spam Measure 3: Suppress default PHPMailer header which triggers spam heuristics
    $mail->XMailer = ' ';

    // Anti-Spam Measure 4: RFC 3834 / transactional headers
    $mail->addCustomHeader('Auto-Submitted', 'auto-generated');
    $mail->addCustomHeader('X-Auto-Response-Suppress', 'All');

    // Add optional Reply-To
    if (!empty($reply_to_email) && filter_var($reply_to_email, FILTER_VALIDATE_EMAIL)) {
        $mail->addReplyTo($reply_to_email, $reply_to_name ?: 'Hiring Team');
    }

    $mail->CharSet = 'UTF-8';
    $mail->Encoding = 'base64';

    return [
        'mail' => $mail,
        'config' => $config,
        'is_smtp' => $is_smtp,
        'from_email' => $from_email,
        'from_name' => $from_name
    ];
}

/**
 * Formats a clear, actionable error message if mail sending fails.
 */
function format_mailer_error($e, $mail, $config) {
    $err_msg = $mail->ErrorInfo ?: $e->getMessage();
    if (stripos($err_msg, 'Could not authenticate') !== false && stripos($config['smtp_host'] ?? '', 'gmail') !== false) {
        $err_msg = "Gmail SMTP Auth Failed: Gmail requires a 16-character App Password (not your personal password). Enable 2-Step Verification and generate one at: myaccount.google.com/apppasswords";
    } elseif (stripos($err_msg, 'Could not authenticate') !== false) {
        $err_msg = "SMTP Authentication Failed: Please check your SMTP Username and Password.";
    } elseif (stripos($err_msg, 'connect() failed') !== false) {
        $err_msg = "SMTP Connection Failed: Could not connect to {$config['smtp_host']}:" . ($config['smtp_port'] ?? '587') . ". Check host, port, and security settings.";
    }
    return $err_msg;
}

/**
 * Helper to build the dynamic base URL of the site
 */
function get_mailer_base_url() {
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script_dir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    $base_path = ($script_dir && $script_dir !== '/') ? $script_dir : '';
    return "$protocol://$host" . $base_path;
}

/**
 * Sends a questionnaire email notification to candidate via PHPMailer
 * @return array ['success' => bool, 'error' => string|null]
 */
function send_questionnaire_email($candidate_name, $candidate_email, $job_title, $q_title, $request_token, $questions_list = [], $employer_name = '', $reply_to_email = '') {
    if (empty($candidate_email)) {
        return ['success' => false, 'error' => 'Candidate email address is missing.'];
    }

    try {
        $base = create_base_mailer('Keria Screening', $reply_to_email, $employer_name);
        $mail = $base['mail'];
        $config = $base['config'];

        $mail->addAddress($candidate_email, $candidate_name ?: 'Candidate');

        $base_url = get_mailer_base_url();
        $answer_url = $base_url . "/answer_questionnaire.php?token=" . urlencode($request_token);

        $mail->isHTML(true);
        $mail->Subject = "Screening Questions: " . ($job_title ? $job_title : "your Job Application") . " - Keria";

        // Build Questions HTML list
        $q_html = '';
        $q_plain = '';
        if (!empty($questions_list)) {
            foreach ($questions_list as $idx => $q_item) {
                $q_num = $idx + 1;
                $q_text = normalize_question($q_item)['text'];
                $q_html .= "<li style='margin-bottom:8px;'><strong>Q{$q_num}:</strong> " . htmlspecialchars($q_text) . "</li>";
                $q_plain .= "Q{$q_num}: {$q_text}\n";
            }
        }

        $mail->Body = "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Screening Questionnaire - Keria</title>
</head>
<body style='margin:0; padding:20px; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; background-color:#F8FAFC; color:#0F172A;'>
    <div style='max-width:600px; margin:0 auto; background:#ffffff; border:1px solid #E2E8F0; border-radius:12px; overflow:hidden;'>
        <div style='background:linear-gradient(135deg, #1E293B, #0F172A); padding:24px; text-align:center;'>
            <h2 style='color:#38BDF8; margin:0; font-size:20px; font-weight:700;'>Keria AI Screening</h2>
            <p style='color:#94A3B8; font-size:13px; margin:4px 0 0 0;'>Screening Questionnaire Assessment</p>
        </div>
        
        <div style='padding:28px 24px;'>
            <p style='font-size:15px; margin:0 0 12px 0;'>Hi <strong>" . htmlspecialchars($candidate_name) . "</strong>,</p>
            <p style='font-size:14px; color:#334155; line-height:1.6; margin:0 0 20px 0;'>
                Thank you for your application for <strong>" . htmlspecialchars($job_title ?: 'our open position') . "</strong>. 
                " . (!empty($employer_name) ? "<strong>" . htmlspecialchars($employer_name) . "</strong>" : "The hiring team") . " has requested you to answer a brief screening questionnaire:
            </p>

            <div style='background:#F8FAFC; border-left:4px solid #38BDF8; padding:14px 18px; border-radius:6px; margin:20px 0;'>
                <div style='font-size:14px; font-weight:bold; color:#0F172A; margin-bottom:8px;'>📋 " . htmlspecialchars($q_title) . "</div>
                " . (!empty($q_html) ? "<ol style='margin:0; padding-left:18px; color:#475569; font-size:13px;'>$q_html</ol>" : "") . "
            </div>

            <div style='text-align:center; margin:30px 0;'>
                <a href='{$answer_url}' style='background:linear-gradient(135deg, #2563EB, #1D4ED8); color:#ffffff; text-decoration:none; padding:14px 28px; border-radius:10px; font-weight:bold; font-size:15px; display:inline-block; box-shadow:0 4px 12px rgba(37, 99, 235, 0.3);'>
                    Answer Screening Questions &rarr;
                </a>
            </div>

            <p style='font-size:12px; color:#94A3B8; text-align:center; margin:20px 0 0 0; line-height:1.5;'>
                If the button above does not work, copy and paste this URL into your browser:<br>
                <a href='{$answer_url}' style='color:#2563EB; word-break:break-all;'>{$answer_url}</a>
            </p>
        </div>

        <div style='border-top:1px solid #E2E8F0; padding:16px 24px; font-size:12px; color:#94A3B8; text-align:center; background:#F8FAFC;'>
            You received this transactional notification regarding your job application.<br>
            © " . date('Y') . " Keria ATS. All rights reserved.
        </div>
    </div>
</body>
</html>";

        $mail->AltBody = "Hi $candidate_name,\n\nPlease answer the screening questionnaire for $job_title at:\n$answer_url\n\n" . (!empty($q_plain) ? "Questions:\n$q_plain\n" : "") . "Thank you,\nKeria Team";

        $mail->send();
        return ['success' => true, 'error' => null];
    } catch (Exception $e) {
        $err_msg = format_mailer_error($e, $mail ?? new PHPMailer(), $config ?? []);
        error_log("PHPMailer Questionnaire Error: " . $err_msg);
        return ['success' => false, 'error' => $err_msg];
    }
}

/**
 * Sends a 6-digit OTP verification email notification to a new user via PHPMailer
 * @return array ['success' => bool, 'error' => string|null]
 */
function send_otp_email($user_name, $user_email, $otp_code) {
    if (empty($user_email)) {
        return ['success' => false, 'error' => 'User email address is missing.'];
    }

    try {
        $base = create_base_mailer('Keria Account Verification');
        $mail = $base['mail'];
        $config = $base['config'];

        $mail->addAddress($user_email, $user_name ?: 'New User');

        $mail->isHTML(true);
        $mail->Subject = "Your Keria Verification Code: {$otp_code}";

        // Digit styling for OTP
        $digits = str_split((string)$otp_code);
        $digits_html = '';
        foreach ($digits as $d) {
            $digits_html .= "<span style='display:inline-block; padding:10px 14px; margin:0 4px; background:#F1F5F9; border:1px solid #CBD5E1; border-radius:8px; font-size:24px; font-weight:800; font-family:monospace; color:#0F172A;'>{$d}</span>";
        }

        $mail->Body = "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Account Verification Code</title>
</head>
<body style='margin:0; padding:20px; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; background-color:#F8FAFC; color:#0F172A;'>
    <div style='max-width:550px; margin:0 auto; background:#ffffff; border:1px solid #E2E8F0; border-radius:12px; overflow:hidden;'>
        <div style='background:linear-gradient(135deg, #1E293B, #0F172A); padding:24px; text-align:center;'>
            <h2 style='color:#38BDF8; margin:0; font-size:20px; font-weight:700;'>Keria Account Verification</h2>
            <p style='color:#94A3B8; font-size:13px; margin:4px 0 0 0;'>One-Time Password (OTP)</p>
        </div>
        
        <div style='padding:28px 24px; text-align:center;'>
            <p style='font-size:15px; text-align:left; margin:0 0 12px 0;'>Hi <strong>" . htmlspecialchars($user_name) . "</strong>,</p>
            <p style='font-size:14px; color:#334155; line-height:1.6; text-align:left; margin:0 0 24px 0;'>
                Thank you for joining <strong>Keria</strong>! Please use the 6-digit verification code below to verify your email address and activate your account:
            </p>

            <div style='margin:28px 0; text-align:center;'>
                {$digits_html}
            </div>

            <p style='font-size:13px; color:#64748B; margin:24px 0 0 0; line-height:1.5;'>
                This verification code will expire in <strong>15 minutes</strong>.<br>
                If you did not request an account, please disregard this email.
            </p>
        </div>

        <div style='border-top:1px solid #E2E8F0; padding:16px 24px; font-size:12px; color:#94A3B8; text-align:center; background:#F8FAFC;'>
            This is an automated security verification from Keria ATS.<br>
            © " . date('Y') . " Keria ATS. All rights reserved.
        </div>
    </div>
</body>
</html>";

        $mail->AltBody = "Hi $user_name,\n\nYour Keria account verification OTP code is: $otp_code\n\nThis code expires in 15 minutes.\n\nThank you,\nKeria Team";

        $mail->send();
        return ['success' => true, 'error' => null];
    } catch (Exception $e) {
        $err_msg = format_mailer_error($e, $mail ?? new PHPMailer(), $config ?? []);
        error_log("PHPMailer OTP Error: " . $err_msg);
        return ['success' => false, 'error' => $err_msg];
    }
}

/**
 * Sends a password reset email notification with a 1-hour expiring token link via PHPMailer
 * @return array ['success' => bool, 'error' => string|null]
 */
function send_password_reset_email($user_name, $user_email, $reset_token) {
    if (empty($user_email)) {
        return ['success' => false, 'error' => 'User email address is missing.'];
    }

    try {
        $base = create_base_mailer('Keria Account Support');
        $mail = $base['mail'];
        $config = $base['config'];

        $mail->addAddress($user_email, $user_name ?: 'User');

        $base_url = get_mailer_base_url();
        $reset_url = $base_url . "/reset_password.php?token=" . urlencode($reset_token);

        $mail->isHTML(true);
        $mail->Subject = "Reset Your Keria Account Password";

        $mail->Body = "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Password Reset Request</title>
</head>
<body style='margin:0; padding:20px; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; background-color:#F8FAFC; color:#0F172A;'>
    <div style='max-width:550px; margin:0 auto; background:#ffffff; border:1px solid #E2E8F0; border-radius:12px; overflow:hidden;'>
        <div style='background:linear-gradient(135deg, #1E293B, #0F172A); padding:24px; text-align:center;'>
            <h2 style='color:#38BDF8; margin:0; font-size:20px; font-weight:700;'>Keria Account Support</h2>
            <p style='color:#94A3B8; font-size:13px; margin:4px 0 0 0;'>Password Reset Request</p>
        </div>
        
        <div style='padding:28px 24px;'>
            <p style='font-size:15px; margin:0 0 12px 0;'>Hi <strong>" . htmlspecialchars($user_name) . "</strong>,</p>
            <p style='font-size:14px; color:#334155; line-height:1.6; margin:0 0 20px 0;'>
                We received a request to reset the password for your <strong>Keria</strong> account. Click the button below to set a new password:
            </p>

            <div style='text-align:center; margin:30px 0;'>
                <a href='{$reset_url}' style='background:linear-gradient(135deg, #2563EB, #1D4ED8); color:#ffffff; text-decoration:none; padding:14px 28px; border-radius:10px; font-weight:bold; font-size:15px; display:inline-block; box-shadow:0 4px 14px rgba(37, 99, 235, 0.3);'>
                    Reset My Password &rarr;
                </a>
            </div>

            <p style='font-size:13px; color:#64748B; margin:0 0 16px 0; line-height:1.5;'>
                This password reset link is valid for <strong>1 hour</strong>.<br>
                If you did not request a password reset, you can safely ignore this email and your password will remain unchanged.
            </p>

            <p style='font-size:12px; color:#94A3B8; text-align:center; margin:20px 0 0 0;'>
                If the button above does not work, copy and paste this link:<br>
                <a href='{$reset_url}' style='color:#2563EB; word-break:break-all;'>{$reset_url}</a>
            </p>
        </div>

        <div style='border-top:1px solid #E2E8F0; padding:16px 24px; font-size:12px; color:#94A3B8; text-align:center; background:#F8FAFC;'>
            This is an automated transactional security notice from Keria ATS.<br>
            © " . date('Y') . " Keria ATS. All rights reserved.
        </div>
    </div>
</body>
</html>";

        $mail->AltBody = "Hi $user_name,\n\nYou requested a password reset for your Keria account. Use the link below to set a new password:\n$reset_url\n\nThis link expires in 1 hour.\n\nThank you,\nKeria Team";

        $mail->send();
        return ['success' => true, 'error' => null];
    } catch (Exception $e) {
        $err_msg = format_mailer_error($e, $mail ?? new PHPMailer(), $config ?? []);
        error_log("PHPMailer Password Reset Error: " . $err_msg);
        return ['success' => false, 'error' => $err_msg];
    }
}

/**
 * Sends an interview proposal invitation email with tokenized accept/decline links to candidate
 * @return array ['success' => bool, 'error' => string|null]
 */
function send_interview_proposal_email($candidate_name, $candidate_email, $job_title, $employer_name, $interview_datetime, $interview_notes, $interview_token, $reply_to_email = '') {
    if (empty($candidate_email)) {
        return ['success' => false, 'error' => 'Candidate email address is missing.'];
    }

    try {
        $base = create_base_mailer('Keria Interview Invitation', $reply_to_email, $employer_name);
        $mail = $base['mail'];
        $config = $base['config'];

        $mail->addAddress($candidate_email, $candidate_name ?: 'Candidate');

        $base_url = get_mailer_base_url();
        $confirm_url = $base_url . "/confirm_interview.php?token=" . urlencode($interview_token) . "&action=confirm";
        $decline_url = $base_url . "/confirm_interview.php?token=" . urlencode($interview_token) . "&action=decline";

        $formatted_date = date('F j, Y \a\t g:i A', strtotime($interview_datetime));

        $mail->isHTML(true);
        $mail->Subject = "Interview Proposal: " . ($job_title ? $job_title : "your Job Application") . " - Keria";

        $mail->Body = "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Interview Proposal - Keria</title>
</head>
<body style='margin:0; padding:20px; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; background-color:#F8FAFC; color:#0F172A;'>
    <div style='max-width:600px; margin:0 auto; background:#ffffff; border:1px solid #E2E8F0; border-radius:12px; overflow:hidden;'>
        <div style='background:linear-gradient(135deg, #1E293B, #0F172A); padding:24px; text-align:center;'>
            <h2 style='color:#38BDF8; margin:0; font-size:20px; font-weight:700;'>Keria Interview Invitation</h2>
            <p style='color:#94A3B8; font-size:13px; margin:4px 0 0 0;'>Proposed Interview Schedule</p>
        </div>
        
        <div style='padding:28px 24px;'>
            <p style='font-size:15px; margin:0 0 12px 0;'>Hi <strong>" . htmlspecialchars($candidate_name) . "</strong>,</p>
            <p style='font-size:14px; color:#334155; line-height:1.6; margin:0 0 20px 0;'>
                Great news! <strong>" . htmlspecialchars($employer_name ?: 'The Hiring Manager') . "</strong> has shortlisted your application for <strong>" . htmlspecialchars($job_title ?: 'our open position') . "</strong> and proposed an interview slot:
            </p>

            <div style='background:#F0FDF4; border-left:4px solid #10B981; padding:16px 20px; border-radius:8px; margin:20px 0;'>
                <div style='font-size:13px; font-weight:bold; color:#047857; margin-bottom:4px;'>📅 Proposed Date & Time:</div>
                <div style='font-size:18px; font-weight:800; color:#064E3B; margin-bottom:10px;'>{$formatted_date}</div>
                " . (!empty($interview_notes) ? "<div style='font-size:13px; color:#334155; border-top:1px dashed #A7F3D0; padding-top:8px;'><strong>Meeting Notes:</strong> " . nl2br(htmlspecialchars($interview_notes)) . "</div>" : "") . "
            </div>

            <div style='text-align:center; margin:30px 0;'>
                <a href='{$confirm_url}' style='background:linear-gradient(135deg, #10B981, #059669); color:#ffffff; text-decoration:none; padding:14px 28px; border-radius:10px; font-weight:bold; font-size:14px; display:inline-block; box-shadow:0 4px 12px rgba(16, 185, 129, 0.3);'>
                    ✓ Confirm Interview Slot
                </a>
                &nbsp;&nbsp;
                <a href='{$decline_url}' style='background:#F1F5F9; color:#DC2626; border:1px solid #CBD5E1; text-decoration:none; padding:14px 22px; border-radius:10px; font-weight:bold; font-size:14px; display:inline-block;'>
                    ✕ Decline
                </a>
            </div>

            <p style='font-size:12px; color:#94A3B8; text-align:center; margin:20px 0 0 0;'>
                You can also respond to this interview proposal directly on your <a href='{$base_url}/candidate_dashboard.php' style='color:#2563EB;'>Candidate Dashboard</a>.
            </p>
        </div>

        <div style='border-top:1px solid #E2E8F0; padding:16px 24px; font-size:12px; color:#94A3B8; text-align:center; background:#F8FAFC;'>
            You received this interview notification regarding your job application.<br>
            © " . date('Y') . " Keria ATS. All rights reserved.
        </div>
    </div>
</body>
</html>";

        $mail->AltBody = "Hi $candidate_name,\n\nYou have been invited to an interview for $job_title on $formatted_date.\n\nConfirm slot: $confirm_url\nDecline: $decline_url\n\nThank you,\nKeria Team";

        $mail->send();
        return ['success' => true, 'error' => null];
    } catch (Exception $e) {
        $err_msg = format_mailer_error($e, $mail ?? new PHPMailer(), $config ?? []);
        error_log("PHPMailer Interview Proposal Error: " . $err_msg);
        return ['success' => false, 'error' => $err_msg];
    }
}

/**
 * Sends interview acceptance confirmation email to employer
 * @return array ['success' => bool, 'error' => string|null]
 */
function send_interview_confirmed_email($employer_name, $employer_email, $candidate_name, $job_title, $interview_datetime, $interview_notes) {
    if (empty($employer_email)) {
        return ['success' => false, 'error' => 'Employer email address is missing.'];
    }

    try {
        $base = create_base_mailer('Keria Interview System');
        $mail = $base['mail'];
        $config = $base['config'];

        $mail->addAddress($employer_email, $employer_name ?: 'Employer');

        $formatted_date = date('F j, Y \a\t g:i A', strtotime($interview_datetime));

        $mail->isHTML(true);
        $mail->Subject = "Interview Confirmed: " . htmlspecialchars($candidate_name) . " for " . ($job_title ? $job_title : "Job Position");

        $mail->Body = "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Interview Confirmed - Keria</title>
</head>
<body style='margin:0; padding:20px; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; background-color:#F8FAFC; color:#0F172A;'>
    <div style='max-width:600px; margin:0 auto; background:#ffffff; border:1px solid #E2E8F0; border-radius:12px; overflow:hidden;'>
        <div style='background:linear-gradient(135deg, #10B981, #059669); padding:24px; text-align:center;'>
            <h2 style='color:#ffffff; margin:0; font-size:20px; font-weight:700;'>✓ Interview Confirmed</h2>
            <p style='color:#ECFDF5; font-size:13px; margin:4px 0 0 0;'>Candidate Accepted Proposed Time Slot</p>
        </div>
        
        <div style='padding:28px 24px;'>
            <p style='font-size:15px; margin:0 0 12px 0;'>Hi <strong>" . htmlspecialchars($employer_name) . "</strong>,</p>
            <p style='font-size:14px; color:#334155; line-height:1.6; margin:0 0 20px 0;'>
                Candidate <strong>" . htmlspecialchars($candidate_name) . "</strong> has confirmed their interview slot for <strong>" . htmlspecialchars($job_title ?: 'your Job Position') . "</strong>.
            </p>

            <div style='background:#ECFDF5; border-left:4px solid #10B981; padding:16px 20px; border-radius:8px; margin:20px 0;'>
                <div style='font-size:13px; font-weight:bold; color:#065F46; margin-bottom:4px;'>📅 Confirmed Date & Time:</div>
                <div style='font-size:18px; font-weight:800; color:#047857; margin-bottom:10px;'>{$formatted_date}</div>
                " . (!empty($interview_notes) ? "<div style='font-size:13px; color:#374151; border-top:1px dashed #A7F3D0; padding-top:8px;'><strong>Notes / Video Link:</strong> " . nl2br(htmlspecialchars($interview_notes)) . "</div>" : "") . "
            </div>
        </div>

        <div style='border-top:1px solid #E2E8F0; padding:16px 24px; font-size:12px; color:#94A3B8; text-align:center; background:#F8FAFC;'>
            This is an automated hiring notification from Keria ATS.<br>
            © " . date('Y') . " Keria ATS. All rights reserved.
        </div>
    </div>
</body>
</html>";

        $mail->AltBody = "Hi $employer_name,\n\nCandidate $candidate_name has confirmed their interview for $job_title on $formatted_date.\n\nThank you,\nKeria Team";

        $mail->send();
        return ['success' => true, 'error' => null];
    } catch (Exception $e) {
        $err_msg = format_mailer_error($e, $mail ?? new PHPMailer(), $config ?? []);
        error_log("PHPMailer Interview Confirmed Error: " . $err_msg);
        return ['success' => false, 'error' => $err_msg];
    }
}

/**
 * Sends a deliverability test email to verify SMTP and anti-spam placement
 * @return array ['success' => bool, 'error' => string|null, 'is_smtp' => bool, 'from_email' => string]
 */
function send_test_email($target_email) {
    if (empty($target_email) || !filter_var($target_email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Please provide a valid recipient email address.'];
    }

    try {
        $base = create_base_mailer('Keria Deliverability Diagnostics');
        $mail = $base['mail'];
        $config = $base['config'];
        $from_email = $base['from_email'];

        $mail->addAddress($target_email);
        $mail->isHTML(true);
        $mail->Subject = "Keria Email Deliverability Test (" . date('M j, Y H:i:s') . ")";

        $transport_info = $base['is_smtp']
            ? "Authenticated SMTP (" . htmlspecialchars($config['smtp_host']) . ":" . htmlspecialchars($config['smtp_port'] ?? '587') . ")"
            : "⚠️ Native PHP mail() [High Spam Risk: No SPF/DKIM authentication]";

        $mail->Body = "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Keria Deliverability Test</title>
</head>
<body style='margin:0; padding:20px; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; background-color:#F8FAFC; color:#0F172A;'>
    <div style='max-width:600px; margin:0 auto; background:#ffffff; border:1px solid #E2E8F0; border-radius:12px; overflow:hidden;'>
        <div style='background:linear-gradient(135deg, #10B981, #059669); padding:24px; text-align:center; color:#ffffff;'>
            <h2 style='margin:0; font-size:20px; font-weight:700;'>Keria Inbox Deliverability Test</h2>
            <p style='margin:6px 0 0 0; font-size:13px; opacity:0.95;'>Testing inbox placement and anti-spam header compliance</p>
        </div>
        <div style='padding:28px 24px;'>
            <p style='font-size:15px; margin:0 0 16px 0;'>Hello,</p>
            <p style='font-size:14px; line-height:1.6; color:#334155; margin:0 0 20px 0;'>
                If you are reading this email in your <strong>Primary Inbox</strong> rather than Spam or Junk, your Keria email dispatcher is functioning with high inbox deliverability!
            </p>
            <div style='background:#F1F5F9; border-radius:8px; padding:16px; font-size:13px; margin:0 0 20px 0;'>
                <div style='font-weight:700; margin-bottom:8px; color:#0F172A;'>🔍 Dispatch Diagnostics:</div>
                <div style='margin-bottom:6px;'><strong>Transport:</strong> {$transport_info}</div>
                <div style='margin-bottom:6px;'><strong>Sender (From):</strong> " . htmlspecialchars($from_email) . "</div>
                <div style='margin-bottom:6px;'><strong>Recipient:</strong> " . htmlspecialchars($target_email) . "</div>
                <div style='margin-bottom:6px;'><strong>Timestamp:</strong> " . date('Y-m-d H:i:s T') . "</div>
                <div><strong>Anti-Spam Measures:</strong> Return-Path envelope alignment, Hostname Message-ID, RFC 3834 auto-submitted, suppressed X-Mailer</div>
            </div>
            <p style='font-size:13px; line-height:1.5; color:#64748B; margin:0;'>
                💡 <em>Best Practice:</em> Ensure your <strong>Sender Email</strong> matches your authenticated SMTP username (e.g. Gmail address) to pass DMARC and prevent spam classification.
            </p>
        </div>
        <div style='background:#F8FAFC; border-top:1px solid #E2E8F0; padding:16px 24px; text-align:center; font-size:12px; color:#94A3B8;'>
            This is an automated test notification sent by Keria ATS.<br>
            © " . date('Y') . " Keria ATS. All rights reserved.
        </div>
    </div>
</body>
</html>";

        $mail->AltBody = "Keria Email Deliverability Test\n\nIf you see this in your inbox, your email dispatcher is working correctly!\n\nTransport: " . ($base['is_smtp'] ? "SMTP ({$config['smtp_host']})" : "Native PHP mail()") . "\nSender: $from_email\nRecipient: $target_email\nTimestamp: " . date('Y-m-d H:i:s T') . "\n\nThank you,\nKeria Team";

        $mail->send();
        return ['success' => true, 'error' => null, 'is_smtp' => $base['is_smtp'], 'from_email' => $from_email];
    } catch (Exception $e) {
        $err_msg = format_mailer_error($e, $mail ?? new PHPMailer(), $config ?? []);
        error_log("PHPMailer Test Error: " . $err_msg);
        return ['success' => false, 'error' => $err_msg];
    }
}
