<?php
require_once 'auth.php';
require_role('employer');
require_once 'company_helpers.php';
require 'parser.php';
require 'ai.php';

// Scope selectable jobs to this employer's own company team, same rule used
// on employer_dashboard.php / compare_candidates.php, so HR can't attach an
// externally-sourced resume to a job posting they don't own.
$active_company_id = get_active_company_id($pdo);
if ($active_company_id) {
    $stmt = $pdo->prepare("SELECT * FROM jobs WHERE (company_id = ? OR (company_id IS NULL AND employer_id = ?)) AND status = 'Active'");
    $stmt->execute([$active_company_id, $_SESSION['user_id']]);
} else {
    $stmt = $pdo->prepare("SELECT * FROM jobs WHERE (employer_id = ? OR employer_id IS NULL) AND status = 'Active'");
    $stmt->execute([$_SESSION['user_id']]);
}
$jobs = $stmt->fetchAll();
$job_ids_allowed = array_column($jobs, 'id');

$preselect_job_id = $_GET['job_id'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload External Resumes - Keria</title>
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__.'/style.css'); ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png?v=<?php echo @filemtime(__DIR__.'/favicon-32x32.png'); ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png?v=<?php echo @filemtime(__DIR__.'/favicon-16x16.png'); ?>">
    <link rel="shortcut icon" href="favicon.ico?v=<?php echo @filemtime(__DIR__.'/favicon.ico'); ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png?v=<?php echo @filemtime(__DIR__.'/apple-touch-icon.png'); ?>">
</head>
<body>
    <div class="bg-watermark-logo"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="Keria Watermark Logo"></div>
    <header>
        <div class="header-inner">
            <div style="display:flex; align-items:center; gap:10px;">
                <div class="logo-box"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="Keria Logo" style="width:36px; height:36px; max-width:36px; max-height:36px; object-fit:contain;"></div>
                <div>
                    <div style="font-size:15px; font-weight:800; line-height:1" class="header-brand-title">Keria Job Portal</div>
                    <div style="font-size:9px; color:var(--mut); letter-spacing:0.8px">RECRUITMENT PLATFORM</div>
                </div>
            </div>
            <nav style="display:flex; gap:4px; margin-left:24px">
                <a href="employer_dashboard.php">👥 Applications & Stats</a>
                <a href="job_dashboard.php">💼 My Jobs</a>
                <a href="upload.php" class="active">📤 Upload Resume</a>
                <a href="questionnaire.php">📋 Questionnaires</a>
                <a href="profile.php">⚙️ Settings</a>
            </nav>
            <?php include 'company_switcher.php'; ?>

            <div class="header-right-actions">
                <?php if(get_api_key()): ?>
                    <span style="color:var(--grn); font-size:11px; font-weight:700">🔑 Key Active</span>
                <?php else: ?>
                    <span style="background:rgba(255, 140, 66, 0.1); border:1px solid rgba(255, 140, 66, 0.3); border-radius:7px; color:var(--org); font-size:11px; padding:6px 12px; font-weight:600;">🔑 Not Configured &mdash; Contact Admin</span>
                <?php endif; ?>
                <a href="logout.php" class="btn-secondary" style="padding:6px 14px; font-size:12px; margin-left:8px;">Logout</a>
            </div>
        </div>
    </header>

    <main>
        <?php if(isset($_SESSION['error'])): ?>
            <div style="background:rgba(255, 77, 106, 0.1); border:1px solid rgba(255, 77, 106, 0.35); border-radius:12px; padding:11px 18px; margin-bottom:20px; color:var(--red); font-size:13px;">
                ⚠️ <?= htmlspecialchars($_SESSION['error']) ?>
                <?php unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <div style="margin-bottom:18px;">
            <h1 style="font-size:20px; font-weight:800; color:var(--txt); margin:0 0 4px;">📤 Upload External Candidate Resumes</h1>
            <p style="font-size:13px; color:var(--mut); margin:0;">For candidates who applied outside Keria (e.g. email, walk-in, referral). Their resume gets the same AI screening and will appear in this job's applicant list and comparison matrix, marked as <strong>🧑‍💼 HR Added</strong>.</p>
        </div>

        <div class="grid-2">
            <div class="panel">
                <div class="panel-title">📤 Upload Resumes</div>

                <?php if(!get_api_key()): ?>
                    <div style="background:rgba(255, 140, 66, 0.1); border:1px solid rgba(255, 140, 66, 0.3); border-radius:10px; padding:12px 16px; color:var(--org); font-size:12px; margin-bottom:16px;">
                        <strong>Warning:</strong> AI screening is not yet configured. Resumes will be uploaded and text extracted, but AI screening will be skipped. Please contact your administrator to set up the AI screening engine.
                    </div>
                <?php endif; ?>

                <?php if(empty($jobs)): ?>
                    <div style="background:rgba(255, 140, 66, 0.1); border:1px solid rgba(255, 140, 66, 0.3); border-radius:10px; padding:12px 16px; color:var(--org); font-size:12px;">
                        You don't have any active job postings yet. <a href="job_dashboard.php" style="color:var(--acc);">Post a job</a> first.
                    </div>
                <?php else: ?>
                    <form method="POST" enctype="multipart/form-data" id="uploadForm">
                        <div style="margin-bottom:16px;">
                            <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:600;">Select Job Posting</label>
                            <select name="job_id" required>
                                <option value="">-- Select a Job --</option>
                                <?php foreach($jobs as $j): ?>
                                    <option value="<?= $j['id'] ?>" <?= ((string)$preselect_job_id === (string)$j['id']) ? 'selected' : '' ?>><?= htmlspecialchars($j['job_title']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="dropzone" id="dropzone" onclick="document.getElementById('fileInput').click()">
                            <div style="font-size:46px; margin-bottom:12px;">📁</div>
                            <div style="font-size:15px; font-weight:700; color:var(--txt); margin-bottom:8px;">Drag & drop resumes here</div>
                            <div style="font-size:12px; color:var(--mut); margin-bottom:20px;">PDF supported • Multiple files at once</div>
                            <div class="btn-secondary">📂 Browse Files</div>
                            <input type="file" name="resumes[]" id="fileInput" multiple accept=".pdf" style="display:none" onchange="renderSelectedFiles()">
                        </div>

                        <div id="selectedFilesList" style="margin-top:12px; display:none;"></div>

                        <button type="button" id="uploadSubmitBtn" class="btn-primary" style="margin-top:16px;" onclick="startBulkUpload()" disabled>Upload & Screen Resumes</button>
                    </form>

                    <div id="uploadProgressPanel" style="display:none; margin-top:18px;">
                        <div style="font-size:12.5px; font-weight:700; color:var(--mut); margin-bottom:8px;">Screening progress</div>
                        <div id="uploadProgressList"></div>
                        <div id="uploadProgressSummary" style="margin-top:14px; display:none;"></div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="panel" style="background:rgba(0, 212, 255, 0.04); border-color:rgba(0, 212, 255, 0.15);">
                <div class="panel-title">💡 How It Works</div>
                <div style="display:flex; gap:9px; margin-bottom:7px; font-size:12px; color:var(--txt); align-items:flex-start;">
                    <span style="color:var(--acc); font-weight:700; flex-shrink:0; min-width:14px;">1.</span> Upload PDF resumes of candidates who applied outside Keria
                </div>
                <div style="display:flex; gap:9px; margin-bottom:7px; font-size:12px; color:var(--txt); align-items:flex-start;">
                    <span style="color:var(--acc); font-weight:700; flex-shrink:0; min-width:14px;">2.</span> Text is extracted securely
                </div>
                <div style="display:flex; gap:9px; margin-bottom:7px; font-size:12px; color:var(--txt); align-items:flex-start;">
                    <span style="color:var(--acc); font-weight:700; flex-shrink:0; min-width:14px;">3.</span> PII (Phone/Email/IC) is stripped out locally
                </div>
                <div style="display:flex; gap:9px; margin-bottom:7px; font-size:12px; color:var(--txt); align-items:flex-start;">
                    <span style="color:var(--acc); font-weight:700; flex-shrink:0; min-width:14px;">4.</span> Cleaned resume is evaluated by AI against this job's description
                </div>
                <div style="display:flex; gap:9px; margin-bottom:7px; font-size:12px; color:var(--txt); align-items:flex-start;">
                    <span style="color:var(--acc); font-weight:700; flex-shrink:0; min-width:14px;">5.</span> Candidate is added to this job's ranked applicant list & comparison matrix, tagged 🧑‍💼 HR Added
                </div>
            </div>
        </div>
    </main>
<script>
// Resumes are screened one at a time via separate small requests (one fetch
// per file) instead of one big form submit carrying the whole batch. A
// single request trying to screen 10-18 resumes sequentially could run long
// enough for a gateway/proxy in front of PHP to give up and show a 504, even
// though the backend kept working and finished successfully regardless --
// this way no single request is ever that slow, and HR sees live progress
// instead of a blank page with no idea whether anything is happening.
async function startBulkUpload() {
    var form = document.getElementById('uploadForm');
    var jobSelect = form.querySelector('select[name="job_id"]');
    var fileInput = document.getElementById('fileInput');
    var files = Array.prototype.slice.call(fileInput.files || []);

    if (!jobSelect.value) {
        alert('Please select a job posting first.');
        return;
    }
    if (!files.length) {
        alert('Please select or drop at least one resume.');
        return;
    }

    var submitBtn = document.getElementById('uploadSubmitBtn');
    var dz = document.getElementById('dropzone');
    submitBtn.disabled = true;
    submitBtn.textContent = 'Screening in progress...';
    jobSelect.disabled = true;
    if (dz) dz.style.pointerEvents = 'none';

    var progressPanel = document.getElementById('uploadProgressPanel');
    var progressList = document.getElementById('uploadProgressList');
    var summaryEl = document.getElementById('uploadProgressSummary');
    progressPanel.style.display = 'block';
    summaryEl.style.display = 'none';
    progressList.innerHTML = '';

    var rowEls = files.map(function(f) {
        var row = document.createElement('div');
        row.style.cssText = 'display:flex; align-items:center; gap:10px; padding:8px 12px; border:1px solid var(--bdr); border-radius:8px; margin-bottom:6px; background:var(--card-solid, #fff); font-size:12.5px;';
        row.innerHTML = '<span class="upload-row-icon">⬜</span>' +
            '<span style="flex:1; font-weight:600; color:var(--txt); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">' + f.name.replace(/</g, '&lt;') + '</span>' +
            '<span class="upload-row-status" style="color:var(--mut);">Waiting...</span>';
        progressList.appendChild(row);
        return row;
    });

    var successCount = 0, duplicateCount = 0, errorCount = 0;

    for (var i = 0; i < files.length; i++) {
        var row = rowEls[i];
        var icon = row.querySelector('.upload-row-icon');
        var status = row.querySelector('.upload-row-status');
        icon.textContent = '⏳';
        status.textContent = 'Processing...';
        status.style.color = 'var(--acc)';

        try {
            var fd = new FormData();
            fd.append('job_id', jobSelect.value);
            fd.append('resume', files[i]);

            var res = await fetch('upload_process_one.php', { method: 'POST', body: fd });
            var data = await res.json();

            if (data.success && data.duplicate) {
                icon.textContent = '⚠️';
                status.textContent = 'Skipped (duplicate)';
                status.style.color = 'var(--org)';
                duplicateCount++;
            } else if (data.success) {
                icon.textContent = '✅';
                status.textContent = (data.score !== null && data.score !== undefined) ? ('Screened (Score: ' + data.score + ')') : 'Processed';
                status.style.color = 'var(--grn)';
                successCount++;
            } else {
                icon.textContent = '❌';
                status.textContent = data.error || 'Failed';
                status.style.color = 'var(--red)';
                errorCount++;
            }
        } catch (err) {
            icon.textContent = '❌';
            status.textContent = 'Network error';
            status.style.color = 'var(--red)';
            errorCount++;
        }
    }

    submitBtn.textContent = 'All Done';
    var jobId = jobSelect.value;
    summaryEl.style.display = 'block';
    summaryEl.innerHTML = '<div style="font-size:12.5px; color:var(--txt); margin-bottom:10px;">' +
        '✅ ' + successCount + ' screened' +
        (duplicateCount ? ', ⚠️ ' + duplicateCount + ' duplicate(s) skipped' : '') +
        (errorCount ? ', ❌ ' + errorCount + ' failed' : '') +
        '</div>' +
        '<a href="employer_dashboard.php?job_id=' + encodeURIComponent(jobId) + '" class="btn-primary" style="text-decoration:none; display:inline-block;">View Applicants</a>';
}

function formatFileSize(bytes) {
    if (bytes >= 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    return Math.max(1, Math.round(bytes / 1024)) + ' KB';
}

// Removes one file from the pending selection (by index) without submitting
// -- lets HR back out of an accidental drop/pick before any AI screening (and
// the API cost that comes with it) is spent on a file they didn't mean to add.
function removeSelectedFile(index) {
    var fileInput = document.getElementById('fileInput');
    var remaining = Array.prototype.slice.call(fileInput.files).filter(function(_, i) { return i !== index; });
    var dt = new DataTransfer();
    remaining.forEach(function(f) { dt.items.add(f); });
    fileInput.files = dt.files;
    renderSelectedFiles();
}

// Selecting or dropping files only ever stages them here -- nothing is
// uploaded or sent for AI screening until HR reviews the list and explicitly
// clicks "Upload & Screen Resumes". Previously the file input auto-submitted
// the instant files were picked, so an accidental selection (wrong files,
// or dropping onto the box by mistake) went straight to paid AI screening
// with no chance to back out.
function renderSelectedFiles() {
    var fileInput = document.getElementById('fileInput');
    var listEl = document.getElementById('selectedFilesList');
    var submitBtn = document.getElementById('uploadSubmitBtn');
    var files = Array.prototype.slice.call(fileInput.files || []);

    if (!files.length) {
        listEl.style.display = 'none';
        listEl.innerHTML = '';
        submitBtn.disabled = true;
        submitBtn.textContent = 'Upload & Screen Resumes';
        return;
    }

    var rows = files.map(function(f, i) {
        return '<div style="display:flex; align-items:center; gap:10px; padding:8px 12px; border:1px solid var(--bdr); border-radius:8px; margin-bottom:6px; background:var(--card-solid, #fff);">' +
            '<span style="font-size:16px;">📄</span>' +
            '<span style="flex:1; font-size:12.5px; font-weight:600; color:var(--txt); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">' + f.name.replace(/</g, '&lt;') + '</span>' +
            '<span style="font-size:11px; color:var(--mut);">' + formatFileSize(f.size) + '</span>' +
            '<button type="button" onclick="removeSelectedFile(' + i + ')" title="Remove this file" style="background:none; border:none; color:var(--red); font-size:15px; cursor:pointer; line-height:1; padding:2px 4px;">✕</button>' +
            '</div>';
    });

    listEl.innerHTML = '<div style="font-size:11.5px; font-weight:700; color:var(--mut); margin-bottom:6px;">' + files.length + ' file' + (files.length > 1 ? 's' : '') + ' ready for review:</div>' + rows.join('');
    listEl.style.display = 'block';
    submitBtn.disabled = false;
    submitBtn.textContent = 'Upload & Screen ' + files.length + ' Resume' + (files.length > 1 ? 's' : '');
}

// Actual drag & drop wiring -- the box was only ever styled and labelled like
// a dropzone; clicking it to browse worked, but nothing handled a real
// dragover/drop, so dropping a file just did the browser's default thing
// (usually opening the PDF in a new tab) instead of uploading it.
(function() {
    var dropzone = document.getElementById('dropzone');
    var fileInput = document.getElementById('fileInput');
    if (!dropzone || !fileInput) return;

    ['dragenter', 'dragover'].forEach(function(evt) {
        dropzone.addEventListener(evt, function(e) {
            e.preventDefault();
            e.stopPropagation();
            dropzone.classList.add('drag-active');
        });
    });

    ['dragleave', 'dragend'].forEach(function(evt) {
        dropzone.addEventListener(evt, function(e) {
            e.preventDefault();
            e.stopPropagation();
            // A dragleave also fires when the pointer passes over a child
            // element (the icon/text/button inside the box) -- only clear
            // the highlight once the pointer has actually left the dropzone.
            if (e.target === dropzone && !dropzone.contains(e.relatedTarget)) {
                dropzone.classList.remove('drag-active');
            }
        });
    });

    dropzone.addEventListener('drop', function(e) {
        e.preventDefault();
        e.stopPropagation();
        dropzone.classList.remove('drag-active');

        var dropped = Array.prototype.slice.call(e.dataTransfer.files || []);
        if (!dropped.length) return;

        var pdfs = dropped.filter(function(f) { return /\.pdf$/i.test(f.name); });
        if (pdfs.length < dropped.length) {
            alert('Only PDF resumes are supported -- ' + (dropped.length - pdfs.length) + ' non-PDF file(s) were ignored.');
        }
        if (!pdfs.length) return;

        // Drag-and-drop file lists are read-only; a DataTransfer is the
        // documented way to build a FileList the input will actually accept.
        var dt = new DataTransfer();
        pdfs.forEach(function(f) { dt.items.add(f); });
        fileInput.files = dt.files;

        renderSelectedFiles();
    });
})();
</script>
<script src="theme.js"></script></body>
</html>
