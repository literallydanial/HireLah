<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function getCandidateNotifications($pdo, $user_id) {
    if (!$user_id) {
        return ['items' => [], 'unread_count' => 0];
    }

    $notifications = [];

    // 1. Fetch unread notification keys
    $stmtReads = $pdo->prepare("SELECT notification_key FROM notification_reads WHERE user_id = ?");
    $stmtReads->execute([$user_id]);
    $readKeys = $stmtReads->fetchAll(PDO::FETCH_COLUMN);
    $readSet = array_flip($readKeys);

    // 2. Fetch pending questionnaires sent to this candidate
    $stmtQuest = $pdo->prepare("
        SELECT qr.id as request_id, qr.sent_at, j.job_title, j.department
        FROM questionnaire_requests qr
        JOIN candidates c ON qr.candidate_id = c.id
        JOIN jobs j ON c.job_id = j.id
        WHERE c.user_id = ? AND qr.status = 'pending'
        ORDER BY qr.sent_at DESC
    ");
    $stmtQuest->execute([$user_id]);
    $pendingQuests = $stmtQuest->fetchAll(PDO::FETCH_ASSOC);

    foreach ($pendingQuests as $pq) {
        $key = "quest_req_" . $pq['request_id'];
        $isRead = isset($readSet[$key]);
        $notifications[] = [
            'key' => $key,
            'title' => '📝 Action Required: Pre-Screening Questionnaire',
            'message' => 'Please complete the questionnaire for ' . htmlspecialchars($pq['job_title']),
            'link' => 'answer_questionnaire.php?token=' . urlencode($pq['request_id']),
            'type' => 'questionnaire',
            'time' => $pq['sent_at'],
            'is_read' => $isRead
        ];
    }

    // 3. Fetch active job postings from the last 7 days
    $stmtJobs = $pdo->prepare("
        SELECT id, job_title, department, employment_type, work_mode, created_at
        FROM jobs
        WHERE status = 'Active' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $stmtJobs->execute();
    $recentJobs = $stmtJobs->fetchAll(PDO::FETCH_ASSOC);

    foreach ($recentJobs as $rj) {
        $key = "job_new_" . $rj['id'];
        $isRead = isset($readSet[$key]);
        $notifications[] = [
            'key' => $key,
            'title' => '✨ New Job Posted: ' . htmlspecialchars($rj['job_title']),
            'message' => htmlspecialchars(($rj['department'] ? $rj['department'] . ' • ' : '') . $rj['employment_type'] . ' (' . $rj['work_mode'] . ')'),
            'link' => 'jobs.php?selected=' . urlencode($rj['id']),
            'type' => 'job',
            'time' => $rj['created_at'],
            'is_read' => $isRead
        ];
    }

    // 4. Fetch candidate application status updates (Shortlisted, Under Review, Rejected)
    $stmtStatus = $pdo->prepare("
        SELECT c.id as candidate_id, c.status, COALESCE(c.updated_at, c.created_at) as status_time, j.job_title, j.department
        FROM candidates c
        JOIN jobs j ON c.job_id = j.id
        WHERE c.user_id = ? AND c.status IN ('Shortlisted', 'Shortlist', 'Under Review', 'Review', 'Reviewed', 'Rejected')
        ORDER BY status_time DESC
    ");
    $stmtStatus->execute([$user_id]);
    $statusApps = $stmtStatus->fetchAll(PDO::FETCH_ASSOC);

    foreach ($statusApps as $sa) {
        $st = strtolower($sa['status']);
        if (strpos($st, 'shortlist') !== false) {
            $key = "app_shortlisted_" . $sa['candidate_id'];
            $title = "🌟 Application Shortlisted!";
            $msg = "Congratulations! Your application for " . htmlspecialchars($sa['job_title']) . " has been Shortlisted.";
            $type = "shortlist";
        } elseif (strpos($st, 'review') !== false) {
            $key = "app_review_" . $sa['candidate_id'];
            $title = "🔍 Application Under Review";
            $msg = "Your application for " . htmlspecialchars($sa['job_title']) . " is currently Under Review by the hiring team.";
            $type = "review";
        } else {
            $key = "app_rejected_" . $sa['candidate_id'];
            $title = "❌ Application Status Update";
            $msg = "Your application for " . htmlspecialchars($sa['job_title']) . " was updated to Rejected.";
            $type = "rejection";
        }

        $isRead = isset($readSet[$key]);
        $notifications[] = [
            'key' => $key,
            'title' => $title,
            'message' => $msg,
            'link' => 'candidate_dashboard.php',
            'type' => $type,
            'time' => $sa['status_time'],
            'is_read' => $isRead
        ];
    }

    // 5. Fetch interview proposals & confirmations for candidate
    $stmtInterviews = $pdo->prepare("
        SELECT c.id as candidate_id, c.interview_status, c.interview_datetime, c.interview_notes, COALESCE(c.updated_at, c.created_at) as int_time, j.job_title, COALESCE(NULLIF(u.company_name, ''), u.name) as employer_name
        FROM candidates c
        JOIN jobs j ON c.job_id = j.id
        LEFT JOIN users u ON j.employer_id = u.id
        WHERE c.user_id = ? AND c.interview_status IN ('Proposed', 'Confirmed', 'Declined')
        ORDER BY int_time DESC
    ");
    $stmtInterviews->execute([$user_id]);
    $interviewRows = $stmtInterviews->fetchAll(PDO::FETCH_ASSOC);

    foreach ($interviewRows as $ir) {
        $st = $ir['interview_status'];
        $formattedDate = !empty($ir['interview_datetime']) ? date('M j, Y \a\t g:i A', strtotime($ir['interview_datetime'])) : '';
        $empName = !empty($ir['employer_name']) ? $ir['employer_name'] : 'The Hiring Manager';

        if ($st === 'Proposed') {
            $key = "cand_int_proposed_" . $ir['candidate_id'];
            $title = "📅 Interview Proposed: " . htmlspecialchars($ir['job_title']);
            $msg = htmlspecialchars($empName) . " proposed an interview slot: " . $formattedDate;
            $type = "interview_proposal";
        } elseif ($st === 'Confirmed') {
            $key = "cand_int_confirmed_" . $ir['candidate_id'];
            $title = "✓ Interview Confirmed: " . htmlspecialchars($ir['job_title']);
            $msg = "Your interview slot for " . $formattedDate . " is confirmed.";
            $type = "interview_confirmed";
        } else {
            $key = "cand_int_declined_" . $ir['candidate_id'];
            $title = "✕ Interview Declined: " . htmlspecialchars($ir['job_title']);
            $msg = "You declined the proposed interview slot.";
            $type = "interview_declined";
        }

        $isRead = isset($readSet[$key]);
        $notifications[] = [
            'key' => $key,
            'title' => $title,
            'message' => $msg,
            'link' => 'candidate_dashboard.php',
            'type' => $type,
            'time' => $ir['int_time'],
            'is_read' => $isRead
        ];
    }

    // 6. Fetch unread messages from employers
    $stmtMsgs = $pdo->prepare("
        SELECT m.id, m.candidate_id, m.body, m.created_at, j.job_title, COALESCE(NULLIF(u.company_name, ''), u.name) as employer_name
        FROM messages m
        JOIN candidates c ON m.candidate_id = c.id
        JOIN jobs j ON c.job_id = j.id
        LEFT JOIN users u ON m.sender_id = u.id
        WHERE c.user_id = ? AND m.sender_role = 'employer' AND m.read_at IS NULL
        ORDER BY m.created_at DESC
    ");
    $stmtMsgs->execute([$user_id]);
    $unreadMsgs = $stmtMsgs->fetchAll(PDO::FETCH_ASSOC);

    foreach ($unreadMsgs as $um) {
        $key = "cand_msg_unread_" . $um['id'];
        $notifications[] = [
            'key' => $key,
            'title' => '💬 New Message from Employer',
            'message' => htmlspecialchars($um['employer_name'] ?: 'Employer') . ': "' . htmlspecialchars(substr($um['body'], 0, 60)) . '..."',
            'link' => 'candidate.php?id=' . urlencode($um['candidate_id']),
            'type' => 'message',
            'time' => $um['created_at'],
            'is_read' => false
        ];
    }

    // Sort notifications by time descending
    usort($notifications, function($a, $b) {
        return strtotime($b['time']) - strtotime($a['time']);
    });

    $unreadCount = 0;
    foreach ($notifications as $n) {
        if (!$n['is_read']) {
            $unreadCount++;
        }
    }

    return [
        'items' => $notifications,
        'unread_count' => $unreadCount
    ];
}

function getEmployerNotifications($pdo, $employer_id) {
    if (!$employer_id) {
        return ['items' => [], 'unread_count' => 0];
    }

    $notifications = [];

    // 1. Fetch unread notification keys for this employer
    $stmtReads = $pdo->prepare("SELECT notification_key FROM notification_reads WHERE user_id = ?");
    $stmtReads->execute([$employer_id]);
    $readKeys = $stmtReads->fetchAll(PDO::FETCH_COLUMN);
    $readSet = array_flip($readKeys);

    // 2. Fetch new candidate applications for this employer's jobs
    $stmtApps = $pdo->prepare("
        SELECT c.id as candidate_id, c.name, c.email, c.created_at, c.overall_score, j.job_title
        FROM candidates c
        JOIN jobs j ON c.job_id = j.id
        WHERE (j.employer_id = ? OR j.employer_id IS NULL)
        ORDER BY c.created_at DESC
        LIMIT 25
    ");
    $stmtApps->execute([$employer_id]);
    $applications = $stmtApps->fetchAll(PDO::FETCH_ASSOC);

    foreach ($applications as $app) {
        $key = "emp_app_new_" . $app['candidate_id'];
        $isRead = isset($readSet[$key]);
        $candName = !empty($app['name']) ? $app['name'] : (!empty($app['email']) ? $app['email'] : "Candidate #" . $app['candidate_id']);
        
        $notifications[] = [
            'key' => $key,
            'title' => '📥 New Application Received!',
            'message' => htmlspecialchars($candName) . ' applied for ' . htmlspecialchars($app['job_title']) . ' (Match: ' . $app['overall_score'] . '%)',
            'link' => 'candidate.php?id=' . $app['candidate_id'],
            'type' => 'application',
            'time' => $app['created_at'],
            'is_read' => $isRead
        ];
    }

    // 3. Fetch interview slot confirmations & responses for employer's jobs
    $stmtEmpInt = $pdo->prepare("
        SELECT c.id as candidate_id, c.name, c.email, c.interview_status, c.interview_datetime, COALESCE(c.updated_at, c.created_at) as int_time, j.job_title
        FROM candidates c
        JOIN jobs j ON c.job_id = j.id
        WHERE (j.employer_id = ? OR j.employer_id IS NULL) AND c.interview_status IN ('Confirmed', 'Declined')
        ORDER BY int_time DESC
        LIMIT 25
    ");
    $stmtEmpInt->execute([$employer_id]);
    $empIntRows = $stmtEmpInt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($empIntRows as $ei) {
        $st = $ei['interview_status'];
        $candName = !empty($ei['name']) ? $ei['name'] : (!empty($ei['email']) ? $ei['email'] : "Candidate #" . $ei['candidate_id']);
        $formattedDate = !empty($ei['interview_datetime']) ? date('M j, Y \a\t g:i A', strtotime($ei['interview_datetime'])) : '';

        if ($st === 'Confirmed') {
            $key = "emp_int_confirmed_" . $ei['candidate_id'];
            $title = "✓ Interview Slot Confirmed!";
            $msg = htmlspecialchars($candName) . " confirmed the interview slot for " . htmlspecialchars($ei['job_title']) . " on " . $formattedDate;
            $type = "interview_confirmed";
        } else {
            $key = "emp_int_declined_" . $ei['candidate_id'];
            $title = "✕ Interview Slot Declined";
            $msg = htmlspecialchars($candName) . " declined the proposed interview slot for " . htmlspecialchars($ei['job_title']);
            $type = "interview_declined";
        }

        $isRead = isset($readSet[$key]);
        $notifications[] = [
            'key' => $key,
            'title' => $title,
            'message' => $msg,
            'link' => 'candidate.php?id=' . $ei['candidate_id'],
            'type' => $type,
            'time' => $ei['int_time'],
            'is_read' => $isRead
        ];
    }

    // 4. Fetch unread messages from candidates
    $stmtEmpMsgs = $pdo->prepare("
        SELECT m.id, m.candidate_id, m.body, m.created_at, c.name as candidate_name, j.job_title
        FROM messages m
        JOIN candidates c ON m.candidate_id = c.id
        JOIN jobs j ON c.job_id = j.id
        WHERE (j.employer_id = ? OR j.employer_id IS NULL) AND m.sender_role = 'candidate' AND m.read_at IS NULL
        ORDER BY m.created_at DESC
    ");
    $stmtEmpMsgs->execute([$employer_id]);
    $unreadEmpMsgs = $stmtEmpMsgs->fetchAll(PDO::FETCH_ASSOC);

    foreach ($unreadEmpMsgs as $uem) {
        $key = "emp_msg_unread_" . $uem['id'];
        $candName = !empty($uem['candidate_name']) ? $uem['candidate_name'] : 'Candidate';
        
        $notifications[] = [
            'key' => $key,
            'title' => '💬 New Message from Candidate',
            'message' => htmlspecialchars($candName) . ' (' . htmlspecialchars($uem['job_title']) . '): "' . htmlspecialchars(substr($uem['body'], 0, 60)) . '..."',
            'link' => 'candidate.php?id=' . urlencode($uem['candidate_id']),
            'type' => 'message',
            'time' => $uem['created_at'],
            'is_read' => false
        ];
    }

    // Sort by time descending
    usort($notifications, function($a, $b) {
        return strtotime($b['time']) - strtotime($a['time']);
    });

    $unreadCount = 0;
    foreach ($notifications as $n) {
        if (!$n['is_read']) {
            $unreadCount++;
        }
    }

    return [
        'items' => $notifications,
        'unread_count' => $unreadCount
    ];
}
