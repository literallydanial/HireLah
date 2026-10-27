<?php
// employer_verification.php -- helpers for the employer approval workflow.
//
// A self-registered employer's company starts as 'pending'. Until an admin
// approves it, the company can't publish jobs. Companies created by admins,
// and every company that existed before this feature, are 'approved'.

require_once __DIR__ . '/config_manager.php';

// Throwaway-mailbox domains that are never accepted for employer signup.
function ev_disposable_domains() {
    return [
        'mailinator.com','guerrillamail.com','guerrillamail.net','guerrillamail.org','guerrillamail.biz','sharklasers.com',
        '10minutemail.com','10minutemail.net','tempmail.com','temp-mail.org','temp-mail.io','tempmail.net','tempmailo.com',
        'yopmail.com','yopmail.net','trashmail.com','trashmail.net','throwawaymail.com','getnada.com','nada.email',
        'maildrop.cc','dispostable.com','fakeinbox.com','mailnesia.com','mintemail.com','mohmal.com','emailondeck.com',
        'burnermail.io','spamgourmet.com','mytemp.email','tmpmail.org','tmpmail.net','discard.email','moakt.com',
        'mail.tm','1secmail.com','1secmail.net','1secmail.org','dropmail.me','inboxkitten.com','getairmail.com',
        'anonbox.net','harakirimail.com','tempail.com','tempinbox.com','fexpost.com','spambox.us','mailcatch.com',
        'luxusmail.org','cuvox.de','gustr.com','armyspy.com','dayrep.com','einrot.com','fleckens.hu','rhyta.com',
        'superrito.com','teleworm.us','jourrapide.com',
    ];
}

// Free providers: not blocked (small genuine businesses use them) but flagged for closer review.
function ev_free_email_domains() {
    return ['gmail.com','googlemail.com','yahoo.com','yahoo.com.my','ymail.com','hotmail.com','outlook.com','live.com',
            'msn.com','icloud.com','me.com','aol.com','proton.me','protonmail.com','gmx.com','zoho.com','mail.com',
            'qq.com','163.com','126.com'];
}

function ev_email_domain($email) {
    $at = strrpos((string)$email, '@');
    return $at === false ? '' : strtolower(trim(substr($email, $at + 1)));
}

function ev_is_disposable_email($email) {
    $d = ev_email_domain($email);
    return $d !== '' && in_array($d, ev_disposable_domains(), true);
}

function ev_is_free_email($email) {
    return in_array(ev_email_domain($email), ev_free_email_domains(), true);
}

// Normalises an SSM number: trims, uppercases, removes spaces.
function ev_normalize_ssm($ssm) {
    return strtoupper(preg_replace('/\s+/', '', trim((string)$ssm)));
}

// Accepts the formats SSM issues: the new 12-digit number (e.g. 202301012345)
// or the old format of 5-7 digits plus 1-2 letters, with an optional dash
// (e.g. 1234567-X). Format check only -- the real check is the admin's lookup
// on SSM e-Info.
function ev_valid_ssm_format($ssm) {
    return (bool)preg_match('/^(\d{12}|\d{5,7}-?[A-Z]{1,2})$/', ev_normalize_ssm($ssm));
}

// Comparison key (dashes removed) so "202301012345" and "2023-01-012345" collide.
function ev_ssm_key($ssm) {
    return preg_replace('/[^0-9A-Z]/', '', ev_normalize_ssm($ssm));
}

// True if another company/user already registered this SSM number.
function ev_ssm_in_use($pdo, $ssm, $exclude_company_id = null) {
    $key = ev_ssm_key($ssm);
    if ($key === '') return false;
    $sql = "SELECT id FROM companies WHERE ssm_number IS NOT NULL AND ssm_number <> ''
            AND UPPER(REPLACE(REPLACE(ssm_number,' ',''),'-','')) = ?";
    $params = [$key];
    if ($exclude_company_id) { $sql .= " AND id <> ?"; $params[] = (int)$exclude_company_id; }
    $st = $pdo->prepare($sql . " LIMIT 1");
    $st->execute($params);
    if ($st->fetch()) return true;
    // A registration that hasn't completed OTP yet has no company row -- check users too.
    $st = $pdo->prepare("SELECT id FROM users WHERE role = 'employer' AND ssm_number IS NOT NULL AND ssm_number <> ''
                         AND UPPER(REPLACE(REPLACE(ssm_number,' ',''),'-','')) = ? LIMIT 1");
    $st->execute([$key]);
    return (bool)$st->fetch();
}

// Flags shown to the reviewer so they know where to look. Returns a short string.
function ev_compute_flags($email, $website = '') {
    $flags = [];
    if (ev_is_free_email($email)) $flags[] = 'free-email';
    if ($website) {
        $host = strtolower(preg_replace('/^www\./', '', (string)parse_url(strpos($website, '://') === false ? 'http://' . $website : $website, PHP_URL_HOST)));
        if ($host !== '' && ev_email_domain($email) !== '' && !ev_is_free_email($email)) {
            $flags[] = (substr($host, -strlen(ev_email_domain($email))) === ev_email_domain($email) || substr(ev_email_domain($email), -strlen($host)) === $host)
                ? 'domain-matches-website' : 'domain-differs-from-website';
        }
    } elseif (!ev_is_free_email($email)) {
        $flags[] = 'business-email';
    }
    return implode(',', $flags);
}

// ---------- company status ----------

function ev_company_status($pdo, $company_id) {
    if (!$company_id) return 'approved'; // legacy employers with no company row keep working
    try {
        $st = $pdo->prepare("SELECT verification_status FROM companies WHERE id = ?");
        $st->execute([(int)$company_id]);
        $v = $st->fetchColumn();
        return $v === false || $v === null ? 'approved' : $v;
    } catch (\Throwable $e) {
        return 'approved'; // column missing -> never block on a schema problem
    }
}

function ev_company_is_approved($pdo, $company_id) {
    return ev_company_status($pdo, $company_id) === 'approved';
}

// Message explaining why posting is blocked, or null if the company can post.
function ev_posting_block_message($pdo, $company_id) {
    $status = ev_company_status($pdo, $company_id);
    if ($status === 'approved') return null;
    if ($status === 'rejected') {
        return "Your company registration was not approved. Please check the email we sent you, correct the details and contact verification@thekeria.com so we can review it again.";
    }
    return "Your company is still under review. Our team usually completes verification within one working day. You can't publish jobs until it is approved.";
}

// ---------- anti-abuse ----------

function ev_client_ip() {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// More than $max signup attempts from one IP in $window_minutes => blocked.
function ev_signup_rate_limited($pdo, $max = 5, $window_minutes = 60) {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM signup_attempts WHERE ip_address = ? AND created_at > (NOW() - INTERVAL ? MINUTE)");
        $st->execute([ev_client_ip(), (int)$window_minutes]);
        return (int)$st->fetchColumn() >= $max;
    } catch (\Throwable $e) {
        return false;
    }
}

function ev_record_signup_attempt($pdo) {
    try {
        $pdo->prepare("INSERT INTO signup_attempts (ip_address) VALUES (?)")->execute([ev_client_ip()]);
        $pdo->exec("DELETE FROM signup_attempts WHERE created_at < (NOW() - INTERVAL 2 DAY)");
    } catch (\Throwable $e) {}
}

// Cloudflare Turnstile is optional: it activates only when both keys exist in
// config.php ('turnstile_site_key' and 'turnstile_secret_key').
function ev_turnstile_keys() {
    $cfg = load_app_config();
    $site = trim($cfg['turnstile_site_key'] ?? '');
    $secret = trim($cfg['turnstile_secret_key'] ?? '');
    return ($site !== '' && $secret !== '') ? [$site, $secret] : null;
}

function ev_turnstile_widget() {
    $k = ev_turnstile_keys();
    if (!$k) return '';
    return '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>'
         . '<div class="cf-turnstile" data-sitekey="' . htmlspecialchars($k[0]) . '" style="margin:6px 0 14px;"></div>';
}

// Returns true when Turnstile is off, or when the token verifies.
function ev_turnstile_verify() {
    $k = ev_turnstile_keys();
    if (!$k) return true;
    $token = $_POST['cf-turnstile-response'] ?? '';
    if ($token === '') return false;
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query(['secret' => $k[1], 'response' => $token, 'remoteip' => ev_client_ip()]),
        'timeout' => 6,
    ]]);
    $raw = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $ctx);
    $json = $raw ? json_decode($raw, true) : null;
    return !empty($json['success']);
}

// ---------- emails ----------

function ev_send_mail($to_email, $to_name, $subject, $html, $alt) {
    try {
        require_once __DIR__ . '/mailer.php';
        $base = create_base_mailer('Keria Verification Team');
        $mail = $base['mail'];
        $mail->addAddress($to_email, $to_name ?: $to_email);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = $alt;
        $mail->send();
        return true;
    } catch (\Throwable $e) {
        error_log("Employer verification email failed: " . $e->getMessage());
        return false;
    }
}

function ev_email_shell($title, $body_html) {
    return "<div style='font-family:Arial,sans-serif;max-width:540px;margin:0 auto;border:1px solid #E5E7EB;border-radius:12px;overflow:hidden;'>"
         . "<div style='background:#0A0A0A;padding:18px 22px;'><span style='color:#D2FF3A;font-size:18px;font-weight:700;'>Keria</span></div>"
         . "<div style='padding:22px;color:#0A0A0A;font-size:14px;line-height:1.6;'><h2 style='margin:0 0 12px;font-size:17px;'>" . htmlspecialchars($title) . "</h2>" . $body_html . "</div>"
         . "<div style='padding:12px 22px;background:#F7F7F7;color:#656565;font-size:12px;'>Automated message from Keria.</div></div>";
}

function ev_notify_admins_new_employer($pdo, $company_name, $ssm, $flags) {
    try {
        $admins = $pdo->query("SELECT name, email FROM users WHERE role = 'admin' AND email <> ''")->fetchAll();
    } catch (\Throwable $e) { return; }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $link = $scheme . ($_SERVER['HTTP_HOST'] ?? 'thekeria.com') . '/admin_dashboard.php';
    $body = "<p>A new employer is waiting for approval.</p><p><strong>" . htmlspecialchars($company_name) . "</strong><br>SSM: " . htmlspecialchars($ssm)
          . ($flags ? "<br>Flags: " . htmlspecialchars($flags) : '') . "</p><p><a href='" . htmlspecialchars($link) . "'>Open the admin dashboard</a> and go to the Pending Employers tab.</p>";
    foreach ($admins as $a) {
        ev_send_mail($a['email'], $a['name'], "New employer awaiting approval: $company_name",
            ev_email_shell('New employer awaiting approval', $body), "New employer awaiting approval: $company_name (SSM $ssm). Open the admin dashboard > Pending Employers.");
    }
}

function ev_send_decision_email($to_email, $to_name, $company_name, $approved, $note) {
    if ($approved) {
        $body = "<p>Hi " . htmlspecialchars($to_name) . ",</p><p>Good news: <strong>" . htmlspecialchars($company_name) . "</strong> has been verified. You can now publish jobs on Keria.</p>";
        return ev_send_mail($to_email, $to_name, "Your company is verified on Keria", ev_email_shell('Your company is verified', $body), "Your company $company_name is verified. You can now publish jobs on Keria.");
    }
    $body = "<p>Hi " . htmlspecialchars($to_name) . ",</p><p>We couldn't verify <strong>" . htmlspecialchars($company_name) . "</strong> yet.</p>"
          . "<p><strong>Reason:</strong> " . nl2br(htmlspecialchars($note)) . "</p>"
          . "<p>Please reply to this email or write to verification@thekeria.com with the corrected details and we will review it again.</p>";
    return ev_send_mail($to_email, $to_name, "Update on your Keria company verification", ev_email_shell('We need a bit more information', $body), "We couldn't verify $company_name yet. Reason: $note. Contact verification@thekeria.com with corrected details.");
}
