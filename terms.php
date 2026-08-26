<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Terms & Conditions & PDPA Compliance - HireLah</title>
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__.'/style.css'); ?>">
    <style>
        .lang-switcher {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
            background: var(--surf);
            padding: 6px;
            border-radius: 12px;
            border: 1px solid var(--bdr);
            width: fit-content;
        }
        .lang-tab {
            padding: 8px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            background: transparent;
            color: var(--mut);
            transition: all 0.2s ease;
        }
        .lang-tab.active {
            background: var(--acc);
            color: #FFFFFF;
            box-shadow: var(--shadow-sm);
        }
        .doc-section {
            background: var(--surf);
            border: 1px solid var(--bdr);
            border-radius: 16px;
            padding: 28px 32px;
            margin-bottom: 24px;
            box-shadow: var(--shadow-sm);
            line-height: 1.65;
        }
        .doc-section h2 {
            font-size: 20px;
            font-weight: 800;
            color: var(--txt);
            margin-top: 0;
            border-bottom: 2px solid var(--bdr);
            padding-bottom: 10px;
            margin-bottom: 16px;
        }
        .doc-section h3 {
            font-size: 15px;
            font-weight: 700;
            color: var(--acc);
            margin-top: 20px;
            margin-bottom: 8px;
        }
        .doc-section p, .doc-section ul {
            font-size: 13px;
            color: var(--txt);
            margin-bottom: 14px;
        }
        .doc-section ul {
            padding-left: 20px;
        }
        .doc-section li {
            margin-bottom: 6px;
        }
        .lang-content {
            display: none;
        }
        .lang-content.active {
            display: block;
        }
    </style>
    <link rel="icon" type="image/png" href="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>">
    <link rel="shortcut icon" type="image/png" href="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>">
</head>
<body>
    <div class="bg-watermark-logo"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="HireLah Watermark Logo"></div>
    <header>
        <div class="header-inner">
            <div style="display:flex; align-items:center; gap:10px;">
                <div class="logo-box"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="HireLah Logo" style="width:36px; height:36px; max-width:36px; max-height:36px; object-fit:contain;"></div>
            </div>
            
            <nav style="display:flex; gap:4px; margin-left:24px">
                <a href="jobs.php">📋 Job Board</a>
                <a href="login.php">🔑 Login</a>
                <a href="register.php">✨ Register</a>
            </nav>

            <div class="header-right-actions">
                <a href="register.php" class="btn-primary" style="padding:6px 14px; font-size:12px; text-decoration:none;">Back to Registration &rarr;</a>
            </div>
        </div>
    </header>

    <main style="max-width:1000px; padding:32px 20px;">
        <div style="margin-bottom:24px;">
            <h1 style="font-size:26px; font-weight:800; color:var(--txt); margin:0 0 8px 0;">Terms & Conditions & PDPA Compliance Notice</h1>
            <div style="font-size:13px; color:var(--mut);">Terma & Syarat dan Notis Perlindungan Data Peribadi (PDPA 2010)</div>
        </div>

        <!-- Language Switcher Tabs -->
        <div class="lang-switcher">
            <button type="button" class="lang-tab active" onclick="switchLang('en')">🇬🇧 English</button>
            <button type="button" class="lang-tab" onclick="switchLang('ms')">🇲🇾 Bahasa Malaysia</button>
        </div>

        <!-- ================= ENGLISH CONTENT ================= -->
        <div id="lang-en" class="lang-content active">
            <!-- Terms & Conditions Section -->
            <div class="doc-section">
                <h2>1. Terms & Conditions of Service</h2>
                <p>Welcome to <strong>HireLah Recruitment Platform</strong>. By creating an account or accessing our platform, you agree to comply with and be bound by the following Terms & Conditions.</p>

                <h3>1.1 Account Registration & One-Time Password (OTP)</h3>
                <p>To register an account on HireLah, you must provide accurate, complete, and current information. Account verification requires a valid email address through an automated One-Time Password (OTP) dispatch process.</p>
                <ul>
                    <li>You are responsible for maintaining the confidentiality of your account credentials.</li>
                    <li>Each user is allowed one primary candidate or employer account.</li>
                    <li>Unverified accounts may be restricted from submitting job applications or posting vacancies.</li>
                </ul>

                <h3>1.2 AI Resume Screening & Match Scoring Evaluation</h3>
                <p>HireLah utilizes advanced artificial intelligence (Anthropic Claude AI models) to extract resume content, analyze skills, evaluate career experience, and compute non-binding job match scores.</p>
                <ul>
                    <li>AI Match Scores are generated solely as supplementary screening assistance for prospective employers.</li>
                    <li>Scores and automated assessments do not constitute a guarantee of employment, interview invitation, or final hiring decision.</li>
                    <li>Candidates retain full control over uploaded resumes and default profile attachments.</li>
                </ul>

                <h3>1.3 Candidate & Employer Conduct</h3>
                <p>Users agree not to submit misleading resume documents, fraudulent job postings, or engage in unauthorized access attempts. HireLah reserves the right to suspend accounts violating these standards.</p>
            </div>

            <!-- PDPA Act Section -->
            <div class="doc-section" id="pdpa">
                <h2>2. Personal Data Protection Act 2010 (PDPA) Compliance Policy</h2>
                <p>In accordance with the <strong>Personal Data Protection Act 2010 ("PDPA") of Malaysia</strong> (Act 709), HireLah is committed to safeguarding your personal data and respecting your privacy rights.</p>

                <h3>2.1 Collection of Personal Data</h3>
                <p>When you register as a Candidate or Employer, HireLah collects personal information including but not limited to:</p>
                <ul>
                    <li>Full Name, Email Address, and Account Credentials</li>
                    <li>PDF Resume documents, employment history, skills, education, and portfolio details</li>
                    <li>Employer company names, department categories, and recruiter contact details</li>
                </ul>

                <h3>2.2 Purpose of Data Collection & Processing</h3>
                <p>Your personal data is processed strictly for the following purposes:</p>
                <ul>
                    <li>Facilitating recruitment matching between candidates and active job openings.</li>
                    <li>Generating AI resume summaries, skills extraction, and job match evaluations.</li>
                    <li>Delivering automated OTP security verification emails and questionnaire screening notifications.</li>
                    <li>Managing your candidate profile and active job application history.</li>
                </ul>

                <h3>2.3 Disclosure to Third Parties</h3>
                <p>Your personal data and uploaded resumes will only be disclosed to registered employers whose job postings you explicitly apply for. We do not sell, rent, or trade personal data to third-party marketers.</p>

                <h3>2.4 Data Subject Rights under PDPA 2010</h3>
                <p>Under the Malaysian PDPA 2010, you hold the following rights regarding your personal data:</p>
                <ul>
                    <li><strong>Right of Access & Correction:</strong> You may view and update your profile details and saved default resume anytime via <a href="profile.php" style="color:var(--acc); font-weight:700;">Profile Settings</a>.</li>
                    <li><strong>Right to Deletion:</strong> You may permanently erase your account and all associated application data using the Account Deletion feature in your profile.</li>
                    <li><strong>Right to Withdraw Consent:</strong> You may withdraw your consent for data processing by closing your candidate account.</li>
                </ul>
            </div>
        </div>

        <!-- ================= BAHASA MALAYSIA CONTENT ================= -->
        <div id="lang-ms" class="lang-content">
            <!-- Terma & Syarat Section -->
            <div class="doc-section">
                <h2>1. Terma & Syarat Perkhidmatan</h2>
                <p>Selamat datang ke <strong>Platform Pengambilan Pekerja HireLah</strong>. Dengan mendaftar akaun atau menggunakan platform kami, anda bersetuju untuk mematuhi dan terikat dengan Terma & Syarat berikut.</p>

                <h3>1.1 Pendaftaran Akaun & Kata Laluan Sekali Guna (OTP)</h3>
                <p>Untuk mendaftar akaun di HireLah, anda hendaklah memberikan maklumat yang tepat dan terkini. Pengesahan akaun memerlukan alamat e-mel yang sah melalui proses penghantaran Kod OTP automatik.</p>
                <ul>
                    <li>Anda bertanggungjawab menjaga kerahsiaan maklumat log masuk akaun anda.</li>
                    <li>Setiap pengguna hanya dibenarkan memiliki satu akaun calon atau majikan utama.</li>
                    <li>Akaun yang belum disahkan boleh dihalang daripada membuat permohonan kerja.</li>
                </ul>

                <h3>1.2 Penapisan Resume AI & Penilaian Skor Padanan Kerja</h3>
                <p>HireLah menggunakan kecerdasan buatan (Model Anthropic Claude AI) untuk mengekstrak kandungan resume, menganalisis kemahiran, menilai pengalaman kerja, dan mengira skor padanan kelayakan kerja.</p>
                <ul>
                    <li>Skor Padanan AI dihasilkan hanya sebagai bantuan penapisan tambahan untuk majikan.</li>
                    <li>Skor AI tidak menjamin tawaran temuduga atau keputusan pengambilan kerja muktamad.</li>
                    <li>Calon mempunyai kawalan penuh ke atas fail resume yang dimuat naik.</li>
                </ul>

                <h3>1.3 Etika Pengguna & Majikan</h3>
                <p>Pengguna bersetuju untuk tidak memuat naik maklumat resume palsu atau tawaran kerja yang mengelirukan. HireLah berhak menggantung akaun yang melanggar syarat ini.</p>
            </div>

            <!-- Akta PDPA Section -->
            <div class="doc-section" id="pdpa-ms">
                <h2>2. Dasar Perlindungan Data Peribadi (PDPA 2010 - Akta 709)</h2>
                <p>Selaras dengan <strong>Akta Perlindungan Data Peribadi 2010 ("PDPA") Malaysia</strong> (Akta 709), HireLah komited untuk melindungi data peribadi anda dan menghormati hak privasi anda.</p>

                <h3>2.1 Pengumpulan Data Peribadi</h3>
                <p>Semasa mendaftar sebagai Calon atau Majikan, HireLah mengumpul maklumat peribadi termasuk:</p>
                <ul>
                    <li>Nama Penuh, Alamat E-mel, dan Maklumat Akaun</li>
                    <li>Dokumen Resume PDF, sejarah pekerjaan, kemahiran, pendidikan, dan maklumat portfolio</li>
                    <li>Nama syarikat majikan, kategori jabatan, dan maklumat hubungan perekrut</li>
                </ul>

                <h3>2.2 Tujuan Pengumpulan & Pemprosesan Data</h3>
                <p>Data peribadi anda diproses khusus untuk tujuan berikut:</p>
                <ul>
                    <li>Memadankan kelayakan calon dengan jawatan kosong yang dipohon.</li>
                    <li>Menghasilkan ringkasan resume AI, ekstraksi kemahiran, dan skor padanan kelayakan kerja.</li>
                    <li>Menghantar e-mel pengesahan keselamatan OTP dan pemberitahuan soalan kaji selidik.</li>
                    <li>Menguruskan profil calon dan rekod sejarah permohonan kerja anda.</li>
                </ul>

                <h3>2.3 Pendedahan Kepada Pihak Ketiga</h3>
                <p>Data peribadi dan dokumen resume anda hanya akan didedahkan kepada majikan berdaftar yang jawatannya anda mohon secara rasmi. Kami tidak menjual atau menyewakan data peribadi anda kepada pihak ketiga.</p>

                <h3>2.4 Hak Subjek Data Di Bawah PDPA 2010</h3>
                <p>Di bawah Akta PDPA 2010 Malaysia, anda mempunyai hak-hak berikut terhadap data peribadi anda:</p>
                <ul>
                    <li><strong>Hak Akses & Pembetulan:</strong> Anda boleh melihat dan mengemas kini maklumat profil serta resume pada bila-bila masa melalui <a href="profile.php" style="color:var(--acc); font-weight:700;">Tetapan Profil</a>.</li>
                    <li><strong>Hak Pemadaman Data:</strong> Anda berhak memadam akaun dan semua rekod data permohonan secara kekal melalui fungsi Pemadaman Akaun di halaman profil.</li>
                    <li><strong>Hak Menarik Balik Kebenaran:</strong> Anda boleh menarik balik kebenaran pemprosesan data dengan menamatkan akaun calon anda.</li>
                </ul>
            </div>
        </div>
    </main>

    <script>
        function switchLang(lang) {
            document.querySelectorAll('.lang-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.lang-content').forEach(c => c.classList.remove('active'));
            
            if (lang === 'ms') {
                document.querySelectorAll('.lang-tab')[1].classList.add('active');
                document.getElementById('lang-ms').classList.add('active');
            } else {
                document.querySelectorAll('.lang-tab')[0].classList.add('active');
                document.getElementById('lang-en').classList.add('active');
            }
        }
    </script>
    <script src="theme.js"></script>
</body>
</html>
