<?php
// quota_helper.php — AI Resume Screening & Resume Builder Weekly Quota and Referral Management
if (!defined('KERIA_INTERNAL')) {
    define('KERIA_INTERNAL', true);
}

/**
 * Calculates current calendar week window (Monday 00:00:00 to Sunday 23:59:59)
 * and next week reset time (Monday 00:00:00).
 */
function get_week_window() {
    $now = new DateTime();
    $dayOfWeek = (int)$now->format('N'); // 1 = Monday, 7 = Sunday
    $startOfWeek = (clone $now)->modify('-' . ($dayOfWeek - 1) . ' days')->setTime(0, 0, 0);
    $endOfWeek = (clone $startOfWeek)->modify('+6 days')->setTime(23, 59, 59);
    $nextReset = (clone $startOfWeek)->modify('+7 days');

    return [
        'start' => $startOfWeek->format('Y-m-d H:i:s'),
        'end' => $endOfWeek->format('Y-m-d H:i:s'),
        'reset' => $nextReset->format('Y-m-d H:i:s'),
        'reset_formatted' => $nextReset->format('D, j M Y \a\t H:i'),
        'reset_day' => $nextReset->format('l, j M'),
    ];
}

/**
 * Ensures user has a unique, clean referral code (e.g. KL-7A8B9C).
 */
function ensure_user_referral_code($pdo, $user_id) {
    if (!$user_id) return null;

    $stmt = $pdo->prepare("SELECT referral_code FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $code = $stmt->fetchColumn();

    if (!empty($code)) {
        return $code;
    }

    // Generate readable alphanumeric code (no confusing characters like 0/O, 1/I)
    $chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $randStr = '';
        for ($i = 0; $i < 6; $i++) {
            $randStr .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $candidateCode = 'KL-' . $randStr;

        // Check uniqueness
        $check = $pdo->prepare("SELECT id FROM users WHERE referral_code = ?");
        $check->execute([$candidateCode]);
        if (!$check->fetchColumn()) {
            $up = $pdo->prepare("UPDATE users SET referral_code = ? WHERE id = ? AND (referral_code IS NULL OR referral_code = '')");
            $up->execute([$candidateCode, $user_id]);
            return $candidateCode;
        }
    }

    // Fallback deterministic code
    $fallbackCode = 'KL-' . strtoupper(substr(md5($user_id . '_keria_ref'), 0, 6));
    $up = $pdo->prepare("UPDATE users SET referral_code = ? WHERE id = ?");
    $up->execute([$fallbackCode, $user_id]);
    return $fallbackCode;
}

/**
 * Fetches user quota metrics for screening or builder.
 * 
 * @param PDO $pdo
 * @param int $user_id
 * @param string $type 'screening' | 'builder'
 * @return array
 */
function get_user_quota($pdo, $user_id, $type = 'screening') {
    $week = get_week_window();

    if (!$user_id) {
        return [
            'is_logged_in' => false,
            'is_admin' => false,
            'can_submit' => false,
            'limit_per_week' => 3,
            'used_this_week' => 0,
            'free_remaining' => 0,
            'bonus_credits' => 0,
            'total_remaining' => 0,
            'week_start' => $week['start'],
            'reset_day' => $week['reset_day'],
            'reset_formatted' => $week['reset_formatted'],
            'referral_code' => ''
        ];
    }

    $stmt = $pdo->prepare("SELECT id, role, referral_code, bonus_screening_credits, bonus_builder_credits, referred_by_user_id FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        return [
            'is_logged_in' => false,
            'is_admin' => false,
            'can_submit' => false,
            'limit_per_week' => 3,
            'used_this_week' => 0,
            'free_remaining' => 0,
            'bonus_credits' => 0,
            'total_remaining' => 0,
            'week_start' => $week['start'],
            'reset_day' => $week['reset_day'],
            'reset_formatted' => $week['reset_formatted'],
            'referral_code' => ''
        ];
    }

    $is_admin = ($user['role'] === 'admin');
    $referral_code = $user['referral_code'] ?: ensure_user_referral_code($pdo, $user_id);

    // Count submissions within current week window
    $used_this_week = 0;
    if ($type === 'screening') {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM resume_reviews WHERE user_id = ? AND created_at >= ?");
        $countStmt->execute([$user_id, $week['start']]);
        $used_this_week = (int)$countStmt->fetchColumn();
    } else { // builder
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM resume_builds WHERE user_id = ? AND created_at >= ?");
        $countStmt->execute([$user_id, $week['start']]);
        $used_this_week = (int)$countStmt->fetchColumn();
    }

    $weekly_limit = 3;
    $free_remaining = max(0, $weekly_limit - $used_this_week);
    $bonus_credits = ($type === 'screening') 
        ? (int)($user['bonus_screening_credits'] ?? 0) 
        : (int)($user['bonus_builder_credits'] ?? 0);

    $total_remaining = $free_remaining + $bonus_credits;
    $can_submit = $is_admin || ($total_remaining > 0);

    return [
        'is_logged_in' => true,
        'is_admin' => $is_admin,
        'user_id' => $user_id,
        'type' => $type,
        'limit_per_week' => $weekly_limit,
        'used_this_week' => $used_this_week,
        'free_remaining' => $free_remaining,
        'bonus_credits' => $bonus_credits,
        'total_remaining' => $total_remaining,
        'can_submit' => $can_submit,
        'week_start' => $week['start'],
        'reset_day' => $week['reset_day'],
        'reset_formatted' => $week['reset_formatted'],
        'referral_code' => $referral_code,
        'referred_by_user_id' => $user['referred_by_user_id'] ?? null
    ];
}

/**
 * Checks if user is eligible to submit, and decrements bonus credits if free weekly limit was exhausted.
 *
 * @param PDO $pdo
 * @param int $user_id
 * @param string $type 'screening' | 'builder'
 * @return array ['allowed' => bool, 'error' => string|null, 'used_bonus' => bool]
 */
function consume_quota_on_submit($pdo, $user_id, $type = 'screening') {
    $quota = get_user_quota($pdo, $user_id, $type);

    if ($quota['is_admin']) {
        return ['allowed' => true, 'error' => null, 'used_bonus' => false];
    }

    if (!$quota['can_submit']) {
        $feature_name = ($type === 'screening') ? 'AI Resume Screenings' : 'AI Resume Builder submissions';
        $ref_code = $quota['referral_code'];
        $error_msg = "You have used all 3 free {$feature_name} for this week. Your weekly quota will refresh on {$quota['reset_day']}. Want more right now? Share your referral code [{$ref_code}] with a friend in your profile to instantly get 3 extra submissions!";
        return ['allowed' => false, 'error' => $error_msg, 'used_bonus' => false];
    }

    // If weekly free uses are available (used < 3), no bonus deduction needed
    if ($quota['used_this_week'] < $quota['limit_per_week']) {
        return ['allowed' => true, 'error' => null, 'used_bonus' => false];
    }

    // Otherwise, consume 1 bonus credit
    if ($quota['bonus_credits'] > 0) {
        $col = ($type === 'screening') ? 'bonus_screening_credits' : 'bonus_builder_credits';
        $up = $pdo->prepare("UPDATE users SET $col = CASE WHEN $col > 0 THEN $col - 1 ELSE 0 END WHERE id = ?");
        $up->execute([$user_id]);
        return ['allowed' => true, 'error' => null, 'used_bonus' => true];
    }

    return ['allowed' => false, 'error' => "You have no remaining submissions left for this week.", 'used_bonus' => false];
}

/**
 * Redeems a friend's referral code.
 * Awards +3 Resume Screenings and +3 Resume Builder submissions to BOTH referrer and referee.
 *
 * @param PDO $pdo
 * @param int $user_id The candidate redeeming the code
 * @param string $input_code
 * @return array ['success' => bool, 'message' => string]
 */
function redeem_referral_code($pdo, $user_id, $input_code) {
    if (!$user_id) {
        return ['success' => false, 'message' => 'Please log in to redeem a referral code.'];
    }

    $clean_code = strtoupper(trim((string)$input_code));
    if (empty($clean_code)) {
        return ['success' => false, 'message' => 'Please enter a valid referral code.'];
    }

    // Check if user has already redeemed any referral code
    $check_already = $pdo->prepare("SELECT referred_by_user_id FROM users WHERE id = ?");
    $check_already->execute([$user_id]);
    $referred_by = $check_already->fetchColumn();

    if (!empty($referred_by)) {
        return ['success' => false, 'message' => 'You have already redeemed a referral code previously.'];
    }

    // Also check user_referrals table directly
    $check_ref_table = $pdo->prepare("SELECT id FROM user_referrals WHERE referred_id = ?");
    $check_ref_table->execute([$user_id]);
    if ($check_ref_table->fetchColumn()) {
        return ['success' => false, 'message' => 'You have already redeemed a referral code previously.'];
    }

    // Find referrer by referral_code
    $find_ref = $pdo->prepare("SELECT id, name, email, referral_code FROM users WHERE UPPER(referral_code) = ?");
    $find_ref->execute([$clean_code]);
    $referrer = $find_ref->fetch(PDO::FETCH_ASSOC);

    if (!$referrer) {
        return ['success' => false, 'message' => 'Invalid referral code. Please check the code and try again.'];
    }

    if ((int)$referrer['id'] === (int)$user_id) {
        return ['success' => false, 'message' => 'You cannot redeem your own referral code.'];
    }

    try {
        $pdo->beginTransaction();

        // 1. Award Referrer +3 Screenings & +3 Builder
        $up_referrer = $pdo->prepare("UPDATE users SET bonus_screening_credits = bonus_screening_credits + 3, bonus_builder_credits = bonus_builder_credits + 3 WHERE id = ?");
        $up_referrer->execute([(int)$referrer['id']]);

        // 2. Award Referee +3 Screenings & +3 Builder and link referrer
        $up_referee = $pdo->prepare("UPDATE users SET bonus_screening_credits = bonus_screening_credits + 3, bonus_builder_credits = bonus_builder_credits + 3, referred_by_user_id = ? WHERE id = ?");
        $up_referee->execute([(int)$referrer['id'], (int)$user_id]);

        // 3. Log referral in user_referrals
        $log_ref = $pdo->prepare("INSERT INTO user_referrals (referrer_id, referred_id, referral_code, credits_awarded, created_at) VALUES (?, ?, ?, 3, CURRENT_TIMESTAMP)");
        $log_ref->execute([(int)$referrer['id'], (int)$user_id, $clean_code]);

        $pdo->commit();

        // 4. Send notification to Referrer if notification helper exists
        if (function_exists('createCandidateNotification')) {
            try {
                $referee_name = $_SESSION['user_name'] ?? 'A friend';
                createCandidateNotification(
                    $pdo,
                    (int)$referrer['id'],
                    'referral_bonus',
                    '🎁 +3 Bonus Credits Earned!',
                    "{$referee_name} used your referral code {$clean_code}! You just received 3 extra AI Resume Screenings and 3 extra AI Resume Builder uses."
                );
            } catch (\Throwable $t) {
                error_log("Referral notification notice: " . $t->getMessage());
            }
        }

        return [
            'success' => true,
            'message' => "🎉 Referral code redeemed successfully! You and your friend both received +3 extra Resume Screenings and +3 extra Resume Builder uses."
        ];
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Redeem referral error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to redeem referral code: ' . $e->getMessage()];
    }
}

/**
 * Returns referral statistics for a user (total friends referred and credits earned).
 */
function get_user_referral_stats($pdo, $user_id) {
    if (!$user_id) return ['total_referred' => 0, 'credits_earned' => 0, 'referred_by_code' => null];

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_referrals WHERE referrer_id = ?");
    $stmt->execute([$user_id]);
    $total_referred = (int)$stmt->fetchColumn();

    // Check who referred this user
    $ref_stmt = $pdo->prepare("
        SELECT r.referral_code, u.name as referrer_name 
        FROM user_referrals r 
        LEFT JOIN users u ON r.referrer_id = u.id 
        WHERE r.referred_id = ?
    ");
    $ref_stmt->execute([$user_id]);
    $referred_info = $ref_stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'total_referred' => $total_referred,
        'credits_earned' => $total_referred * 3,
        'referred_by_code' => $referred_info['referral_code'] ?? null,
        'referrer_name' => $referred_info['referrer_name'] ?? null
    ];
}

/**
 * Refunds a bonus credit in case an API call or upload fails after deduction.
 */
function refund_bonus_credit($pdo, $user_id, $type = 'screening') {
    if (!$user_id) return;
    $col = ($type === 'screening') ? 'bonus_screening_credits' : 'bonus_builder_credits';
    $up = $pdo->prepare("UPDATE users SET $col = $col + 1 WHERE id = ?");
    $up->execute([$user_id]);
}

