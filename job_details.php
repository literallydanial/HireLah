<?php
$job_id = !empty($_GET['id']) ? (int)$_GET['id'] : '';
if (!empty($job_id)) {
    header("Location: jobs.php?id=" . $job_id);
} else {
    header("Location: jobs.php");
}
exit;
