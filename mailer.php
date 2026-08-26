<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/questionnaire_helpers.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

/**
 * Sends a questionnaire email notification to candidate via PHPMailer
 * @return array ['success' => bool, 'error' => string|null]
 */
function send_questionnaire_email($candidate_name, $candidate_email, $job_title, $q_title, $request_token, $questions_list = [], $employer_name = '', $reply_to_email = '') {
    if (empty($candidate_email)) {
        return ['success' => false, 'error' => 'Candidate email address is missing.'];
    }

    $mail = new PHPMailer(true);

    try {
        // Load config if custom SMTP settings exist
        $config_file = __DIR__ . '/config.json';
        $config = [];
        if (file_exists($config_file)) {
            $config = json_decode(file_get_contents($config_file), true) ?: [];
        }

        // Configure Mailer settings
        if (!empty($config['smtp_host'])) {
            $mail->isSMTP();
            $mail->Host       = $config['smtp_host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $config['smtp_user'] ?? '';
            $mail->Password   = $config['smtp_pass'] ?? '';
            $mail->SMTPSecure = !empty($config['smtp_secure']) ? $config['smtp_secure'] : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = !empty($config['smtp_port']) ? (int)$config['smtp_port'] : 587;
        } else {
            // Default to native mail() transport if no external SMTP server configured
            $mail->isMail();
        }

        // Disable SSL verification issues on local XAMPP environments
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );

        // Recipients
        $from_email = !empty($config['smtp_from']) ? $config['smtp_from'] : (!empty($config['smtp_user']) ? $config['smtp_user'] : 'no-reply@hirelah.com');
        $from_name  = $config['smtp_from_name'] ?? 'HireLah Recruitment Team';
        
        $mail->setFrom($from_email, $from_name);
        if (!empty($reply_to_email)) {
            $mail->addReplyTo($reply_to_email, $employer_name ?: 'Hiring Team');
        }
        $mail->addAddress($candidate_email, $candidate_name ?: 'Candidate');

        // Dynamic Base URL Resolution (Adapts to root htdocs/ or subfolder on InfinityFree)
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $script_dir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
        $base_path = ($script_dir && $script_dir !== '/') ? $script_dir : '';
        $answer_url = "$protocol://$host" . $base_path . "/answer_questionnaire.php?token=" . urlencode($request_token);

        // Content
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->isHTML(true);
        $mail->Subject = "Action Required: Screening Questions for " . ($job_title ? $job_title : "your Job Application") . " - HireLah";

        // Build Questions HTML list
        $q_html = '';
        if (!empty($questions_list)) {
            foreach ($questions_list as $idx => $q_item) {
                $q_num = $idx + 1;
                $q_text = normalize_question($q_item)['text'];
                $q_html .= "<li style='margin-bottom:8px;'><strong>Q{$q_num}:</strong> " . htmlspecialchars($q_text) . "</li>";
            }
        }

        $mail->Body = "
        <div style='font-family: Arial, sans-serif; max-width:600px; margin:0 auto; padding:24px; border:1px solid #E2E8F0; border-radius:12px; background:#ffffff;'>
            <div style='text-align:center; padding-bottom:16px; border-bottom:1px solid #E2E8F0;'>
                <h2 style='color:#3B82F6; margin:0;'>HireLah AI Screening</h2>
                <p style='color:#64748B; font-size:13px; margin-top:4px;'>Screening Questionnaire Request</p>
            </div>
            
            <div style='padding:20px 0;'>
                <p style='font-size:15px; color:#0F172A;'>Hi <strong>" . htmlspecialchars($candidate_name) . "</strong>,</p>
                <p style='font-size:14px; color:#334155; line-height:1.6;'>
                    Thank you for your application for <strong>" . htmlspecialchars($job_title ?: 'our open position') . "</strong>. 
                    The hiring team has requested you to answer a brief screening questionnaire:
                </p>

                <div style='background:#F8FAFC; border-left:4px solid #3B82F6; padding:14px 18px; border-radius:6px; margin:20px 0;'>
                    <div style='font-size:14px; font-weight:bold; color:#0F172A; margin-bottom:8px;'>📋 " . htmlspecialchars($q_title) . "</div>
                    " . (!empty($q_html) ? "<ol style='margin:0; padding-left:18px; color:#475569; font-size:13px;'>$q_html</ol>" : "") . "
                </div>

                <div style='text-align:center; margin:30px 0;'>
                    <a href='{$answer_url}' style='background:linear-gradient(135deg, #6B8A00, #3B82F6); color:#ffffff; text-decoration:none; padding:14px 28px; border-radius:10px; font-weight:bold; font-size:15px; display:inline-block; box-shadow:0 4px 12px rgba(59, 130, 246, 0.3);'>
                        Answer Screening Questions &rarr;
                    </a>
                </div>

                <p style='font-size:12px; color:#94A3B8; text-align:center;'>
                    If the button above does not work, copy and paste this URL into your browser:<br>
                    <a href='{$answer_url}' style='color:#3B82F6;'>{$answer_url}</a>
                </p>
            </div>

            <div style='border-top:1px solid #E2E8F0; padding-top:16px; font-size:12px; color:#94A3B8; text-align:center;'>
                © " . date('Y') . " HireLah ATS. All rights reserved.
            </div>
        </div>";

        $mail->AltBody = "Hi $candidate_name,\n\nPlease answer the screening questionnaire for $job_title at:\n$answer_url\n\nThank you,\nHireLah Team";

        $mail->send();
        return ['success' => true, 'error' => null];
    } catch (Exception $e) {
        $err_msg = $mail->ErrorInfo ?: $e->getMessage();
        if (stripos($err_msg, 'Could not authenticate') !== false && stripos($config['smtp_host'] ?? '', 'gmail') !== false) {
            $err_msg = "Gmail SMTP Auth Failed: Gmail requires a 16-character App Password (not your personal password). Generate one at: myaccount.google.com/apppasswords";
        }
        error_log("PHPMailer Error: " . $err_msg);
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

    $mail = new PHPMailer(true);

    try {
        // Load config if custom SMTP settings exist
        $config_file = __DIR__ . '/config.json';
        $config = [];
        if (file_exists($config_file)) {
            $config = json_decode(file_get_contents($config_file), true) ?: [];
        }

        // Configure Mailer settings
        if (!empty($config['smtp_host'])) {
            $mail->isSMTP();
            $mail->Host       = $config['smtp_host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $config['smtp_user'] ?? '';
            $mail->Password   = $config['smtp_pass'] ?? '';
            $mail->SMTPSecure = !empty($config['smtp_secure']) ? $config['smtp_secure'] : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = !empty($config['smtp_port']) ? (int)$config['smtp_port'] : 587;
        } else {
            $mail->isMail();
        }

        // Disable SSL verification issues on local XAMPP environments
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );

        // Recipients
        $from_email = !empty($config['smtp_from']) ? $config['smtp_from'] : (!empty($config['smtp_user']) ? $config['smtp_user'] : 'no-reply@hirelah.com');
        $from_name  = $config['smtp_from_name'] ?? 'HireLah Account Verification';
        
        $mail->setFrom($from_email, $from_name);
        $mail->addAddress($user_email, $user_name ?: 'New User');

        // Content
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->isHTML(true);
        $mail->Subject = "Your HireLah Account Verification Code: {$otp_code}";

        // Digit styling for OTP
        $digits = str_split((string)$otp_code);
        $digits_html = '';
        foreach ($digits as $d) {
            $digits_html .= "<span style='display:inline-block; padding:10px 14px; margin:0 4px; background:#F1F5F9; border:1px solid #CBD5E1; border-radius:8px; font-size:24px; font-weight:800; font-family:monospace; color:#0F172A;'>{$d}</span>";
        }

        $mail->Body = "
        <div style='font-family: Arial, sans-serif; max-width:550px; margin:0 auto; padding:24px; border:1px solid #E2E8F0; border-radius:12px; background:#ffffff;'>
            <div style='text-align:center; padding-bottom:16px; border-bottom:1px solid #E2E8F0;'>
                <h2 style='color:#3B82F6; margin:0;'>HireLah Account Verification</h2>
                <p style='color:#64748B; font-size:13px; margin-top:4px;'>Email Security & Verification</p>
            </div>
            
            <div style='padding:24px 0; text-align:center;'>
                <p style='font-size:15px; color:#0F172A; text-align:left;'>Hi <strong>" . htmlspecialchars($user_name) . "</strong>,</p>
                <p style='font-size:14px; color:#334155; line-height:1.6; text-align:left;'>
                    Thank you for signing up with <strong>HireLah</strong>! Please use the 6-digit verification code below to confirm your email address and activate your account:
                </p>

                <div style='margin:28px 0; text-align:center;'>
                    {$digits_html}
                </div>

                <p style='font-size:12px; color:#64748B;'>
                    This verification code will expire in <strong>15 minutes</strong>.<br>
                    If you did not request this account, please ignore this email.
                </p>
            </div>

            <div style='border-top:1px solid #E2E8F0; padding-top:16px; font-size:12px; color:#94A3B8; text-align:center;'>
                © " . date('Y') . " HireLah ATS. All rights reserved.
            </div>
        </div>";

        $mail->AltBody = "Hi $user_name,\n\nYour HireLah account verification OTP code is: $otp_code\nIt expires in 15 minutes.\n\nThank you,\nHireLah Team";

        $mail->send();
        return ['success' => true, 'error' => null];
    } catch (Exception $e) {
        $err_msg = $mail->ErrorInfo ?: $e->getMessage();
        if (stripos($err_msg, 'Could not authenticate') !== false && stripos($config['smtp_host'] ?? '', 'gmail') !== false) {
            $err_msg = "Gmail SMTP Auth Failed: Gmail requires a 16-character App Password (not your personal password). Generate one at: myaccount.google.com/apppasswords";
        }
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

    $mail = new PHPMailer(true);

    try {
        // Load config if custom SMTP settings exist
        $config_file = __DIR__ . '/config.json';
        $config = [];
        if (file_exists($config_file)) {
            $config = json_decode(file_get_contents($config_file), true) ?: [];
        }

        // Configure Mailer settings
        if (!empty($config['smtp_host'])) {
            $mail->isSMTP();
            $mail->Host       = $config['smtp_host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $config['smtp_user'] ?? '';
            $mail->Password   = $config['smtp_pass'] ?? '';
            $mail->SMTPSecure = !empty($config['smtp_secure']) ? $config['smtp_secure'] : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = !empty($config['smtp_port']) ? (int)$config['smtp_port'] : 587;
        } else {
            $mail->isMail();
        }

        // Disable SSL verification issues on local XAMPP environments
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );

        // Recipients
        $from_email = !empty($config['smtp_from']) ? $config['smtp_from'] : (!empty($config['smtp_user']) ? $config['smtp_user'] : 'no-reply@hirelah.com');
        $from_name  = $config['smtp_from_name'] ?? 'HireLah Account Support';
        
        $mail->setFrom($from_email, $from_name);
        $mail->addAddress($user_email, $user_name ?: 'User');

        // Dynamic Base URL Resolution
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $script_dir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
        $base_path = ($script_dir && $script_dir !== '/') ? $script_dir : '';
        $reset_url = "$protocol://$host" . $base_path . "/reset_password.php?token=" . urlencode($reset_token);

        // Content
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->isHTML(true);
        $mail->Subject = "Reset Your HireLah Account Password";

        $mail->Body = "
        <div style='font-family: Arial, sans-serif; max-width:550px; margin:0 auto; padding:24px; border:1px solid #E2E8F0; border-radius:12px; background:#ffffff;'>
            <div style='text-align:center; padding-bottom:16px; border-bottom:1px solid #E2E8F0;'>
                <h2 style='color:#6B8A00; margin:0;'>HireLah Account Support</h2>
                <p style='color:#64748B; font-size:13px; margin-top:4px;'>Password Reset Request</p>
            </div>
            
            <div style='padding:24px 0;'>
                <p style='font-size:15px; color:#0F172A;'>Hi <strong>" . htmlspecialchars($user_name) . "</strong>,</p>
                <p style='font-size:14px; color:#334155; line-height:1.6;'>
                    We received a request to reset the password for your <strong>HireLah</strong> account. Click the button below to set a new password:
                </p>

                <div style='text-align:center; margin:30px 0;'>
                    <a href='{$reset_url}' style='background:linear-gradient(135deg, #B9D600, #0A0A0A); color:#ffffff; text-decoration:none; padding:14px 28px; border-radius:10px; font-weight:bold; font-size:15px; display:inline-block; box-shadow:0 4px 14px rgba(217, 255, 79, 0.35);'>
                        Reset My Password &rarr;
                    </a>
                </div>

                <p style='font-size:12px; color:#64748B;'>
                    This password reset link is valid for <strong>1 hour</strong>.<br>
                    If you did not request a password reset, you can safely ignore this email and your password will remain unchanged.
                </p>

                <p style='font-size:12px; color:#94A3B8; text-align:center; margin-top:20px;'>
                    If the button does not work, copy and paste this URL into your browser:<br>
                    <a href='{$reset_url}' style='color:#6B8A00;'>{$reset_url}</a>
                </p>
            </div>

            <div style='border-top:1px solid #E2E8F0; padding-top:16px; font-size:12px; color:#94A3B8; text-align:center;'>
                © " . date('Y') . " HireLah ATS. All rights reserved.
            </div>
        </div>";

        $mail->AltBody = "Hi $user_name,\n\nYou requested a password reset for your HireLah account. Click or copy the link below to set a new password:\n$reset_url\n\nThis link expires in 1 hour.\n\nThank you,\nHireLah Team";

        $mail->send();
        return ['success' => true, 'error' => null];
    } catch (Exception $e) {
        $err_msg = $mail->ErrorInfo ?: $e->getMessage();
        if (stripos($err_msg, 'Could not authenticate') !== false && stripos($config['smtp_host'] ?? '', 'gmail') !== false) {
            $err_msg = "Gmail SMTP Auth Failed: Gmail requires a 16-character App Password (not your personal password). Generate one at: myaccount.google.com/apppasswords";
        }
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

    $mail = new PHPMailer(true);

    try {
        $config_file = __DIR__ . '/config.json';
        $config = [];
        if (file_exists($config_file)) {
            $config = json_decode(file_get_contents($config_file), true) ?: [];
        }

        if (!empty($config['smtp_host'])) {
            $mail->isSMTP();
            $mail->Host       = $config['smtp_host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $config['smtp_user'] ?? '';
            $mail->Password   = $config['smtp_pass'] ?? '';
            $mail->SMTPSecure = !empty($config['smtp_secure']) ? $config['smtp_secure'] : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = !empty($config['smtp_port']) ? (int)$config['smtp_port'] : 587;
        } else {
            $mail->isMail();
        }

        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );

        $from_email = !empty($config['smtp_from']) ? $config['smtp_from'] : (!empty($config['smtp_user']) ? $config['smtp_user'] : 'no-reply@hirelah.com');
        $from_name  = $config['smtp_from_name'] ?? 'HireLah Recruitment Team';

        $mail->setFrom($from_email, $from_name);
        if (!empty($reply_to_email)) {
            $mail->addReplyTo($reply_to_email, $employer_name ?: 'Hiring Team');
        }
        $mail->addAddress($candidate_email, $candidate_name ?: 'Candidate');

        // Dynamic Base URL Resolution
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $script_dir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
        $base_path = ($script_dir && $script_dir !== '/') ? $script_dir : '';
        
        $confirm_url = "$protocol://$host" . $base_path . "/confirm_interview.php?token=" . urlencode($interview_token) . "&action=confirm";
        $decline_url = "$protocol://$host" . $base_path . "/confirm_interview.php?token=" . urlencode($interview_token) . "&action=decline";

        $formatted_date = date('F j, Y \a\t g:i A', strtotime($interview_datetime));

        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->isHTML(true);
        $mail->Subject = "Interview Proposal: " . ($job_title ? $job_title : "your Job Application") . " - HireLah";

        $mail->Body = "
        <div style='font-family: Arial, sans-serif; max-width:600px; margin:0 auto; padding:24px; border:1px solid #E2E8F0; border-radius:12px; background:#ffffff;'>
            <div style='text-align:center; padding-bottom:16px; border-bottom:1px solid #E2E8F0;'>
                <h2 style='color:#6B8A00; margin:0;'>HireLah Interview Invitation</h2>
                <p style='color:#64748B; font-size:13px; margin-top:4px;'>Proposed Interview Schedule</p>
            </div>
            
            <div style='padding:20px 0;'>
                <p style='font-size:15px; color:#0F172A;'>Hi <strong>" . htmlspecialchars($candidate_name) . "</strong>,</p>
                <p style='font-size:14px; color:#334155; line-height:1.6;'>
                    Great news! <strong>" . htmlspecialchars($employer_name ?: 'The Hiring Manager') . "</strong> has shortlisted your application for <strong>" . htmlspecialchars($job_title ?: 'our open position') . "</strong> and proposed an interview slot:
                </p>

                <div style='background:#F4F1FD; border-left:4px solid #6B8A00; padding:16px 20px; border-radius:8px; margin:20px 0;'>
                    <div style='font-size:14px; font-weight:bold; color:#0F172A; margin-bottom:6px;'>📅 Proposed Date & Time:</div>
                    <div style='font-size:18px; font-weight:800; color:#0A0A0A; margin-bottom:10px;'>{$formatted_date}</div>
                    " . (!empty($interview_notes) ? "<div style='font-size:13px; color:#475569; border-top:1px dashed #CBD5E1; padding-top:8px;'><strong>Meeting Notes:</strong> " . nl2br(htmlspecialchars($interview_notes)) . "</div>" : "") . "
                </div>

                <div style='text-align:center; margin:30px 0; display:flex; gap:12px; justify-content:center;'>
                    <a href='{$confirm_url}' style='background:linear-gradient(135deg, #00E87A, #10B981); color:#ffffff; text-decoration:none; padding:14px 28px; border-radius:10px; font-weight:bold; font-size:14px; display:inline-block; box-shadow:0 4px 12px rgba(16, 185, 129, 0.3);'>
                        ✓ Confirm Interview Slot
                    </a>
                    &nbsp;&nbsp;
                    <a href='{$decline_url}' style='background:#F1F5F9; color:#EF4444; border:1px solid #CBD5E1; text-decoration:none; padding:14px 22px; border-radius:10px; font-weight:bold; font-size:14px; display:inline-block;'>
                        ✕ Decline
                    </a>
                </div>

                <p style='font-size:12px; color:#94A3B8; text-align:center;'>
                    You can also respond to this interview proposal directly on your <a href='$protocol://$host$base_path/candidate_dashboard.php' style='color:#6B8A00;'>Candidate Dashboard</a>.
                </p>
            </div>

            <div style='border-top:1px solid #E2E8F0; padding-top:16px; font-size:12px; color:#94A3B8; text-align:center;'>
                © " . date('Y') . " HireLah ATS. All rights reserved.
            </div>
        </div>";

        $mail->AltBody = "Hi $candidate_name,\n\nYou have been invited to an interview for $job_title on $formatted_date.\nConfirm slot: $confirm_url\nDecline: $decline_url\n\nThank you,\nHireLah Team";

        $mail->send();
        return ['success' => true, 'error' => null];
    } catch (Exception $e) {
        $err_msg = $mail->ErrorInfo ?: $e->getMessage();
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

    $mail = new PHPMailer(true);

    try {
        $config_file = __DIR__ . '/config.json';
        $config = [];
        if (file_exists($config_file)) {
            $config = json_decode(file_get_contents($config_file), true) ?: [];
        }

        if (!empty($config['smtp_host'])) {
            $mail->isSMTP();
            $mail->Host       = $config['smtp_host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $config['smtp_user'] ?? '';
            $mail->Password   = $config['smtp_pass'] ?? '';
            $mail->SMTPSecure = !empty($config['smtp_secure']) ? $config['smtp_secure'] : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = !empty($config['smtp_port']) ? (int)$config['smtp_port'] : 587;
        } else {
            $mail->isMail();
        }

        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );

        $from_email = !empty($config['smtp_from']) ? $config['smtp_from'] : (!empty($config['smtp_user']) ? $config['smtp_user'] : 'no-reply@hirelah.com');
        $from_name  = $config['smtp_from_name'] ?? 'HireLah Interview System';

        $mail->setFrom($from_email, $from_name);
        $mail->addAddress($employer_email, $employer_name ?: 'Employer');

        $formatted_date = date('F j, Y \a\t g:i A', strtotime($interview_datetime));

        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->isHTML(true);
        $mail->Subject = "Interview Confirmed: " . htmlspecialchars($candidate_name) . " for " . ($job_title ? $job_title : "Job Position");

        $mail->Body = "
        <div style='font-family: Arial, sans-serif; max-width:600px; margin:0 auto; padding:24px; border:1px solid #E2E8F0; border-radius:12px; background:#ffffff;'>
            <div style='text-align:center; padding-bottom:16px; border-bottom:1px solid #E2E8F0;'>
                <h2 style='color:#10B981; margin:0;'>✓ Interview Confirmed</h2>
                <p style='color:#64748B; font-size:13px; margin-top:4px;'>Candidate Accepted Proposed Time Slot</p>
            </div>
            
            <div style='padding:20px 0;'>
                <p style='font-size:15px; color:#0F172A;'>Hi <strong>" . htmlspecialchars($employer_name) . "</strong>,</p>
                <p style='font-size:14px; color:#334155; line-height:1.6;'>
                    Candidate <strong>" . htmlspecialchars($candidate_name) . "</strong> has confirmed their interview slot for position <strong>" . htmlspecialchars($job_title ?: 'your Job Position') . "</strong>.
                </p>

                <div style='background:#ECFDF5; border-left:4px solid #10B981; padding:16px 20px; border-radius:8px; margin:20px 0;'>
                    <div style='font-size:14px; font-weight:bold; color:#0F172A; margin-bottom:6px;'>📅 Confirmed Date & Time:</div>
                    <div style='font-size:18px; font-weight:800; color:#059669; margin-bottom:10px;'>{$formatted_date}</div>
                    " . (!empty($interview_notes) ? "<div style='font-size:13px; color:#374151; border-top:1px dashed #A7F3D0; padding-top:8px;'><strong>Notes / Video Link:</strong> " . nl2br(htmlspecialchars($interview_notes)) . "</div>" : "") . "
                </div>
            </div>

            <div style='border-top:1px solid #E2E8F0; padding-top:16px; font-size:12px; color:#94A3B8; text-align:center;'>
                © " . date('Y') . " HireLah ATS. All rights reserved.
            </div>
        </div>";

        $mail->AltBody = "Hi $employer_name,\n\nCandidate $candidate_name has confirmed their interview for $job_title on $formatted_date.\n\nThank you,\nHireLah Team";

        $mail->send();
        return ['success' => true, 'error' => null];
    } catch (Exception $e) {
        $err_msg = $mail->ErrorInfo ?: $e->getMessage();
        error_log("PHPMailer Interview Confirmed Error: " . $err_msg);
        return ['success' => false, 'error' => $err_msg];
    }
}


