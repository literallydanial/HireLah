// theme.js - HireLah Global Theme, Loading Animations & Toast Notification System
const savedTheme = localStorage.getItem("theme");
if (savedTheme) {
    document.documentElement.setAttribute("data-theme", savedTheme);
}

// --------------------------------------------------------------------------
// 1. Global Navigation Progress Bar
// --------------------------------------------------------------------------
let progressBarEl = null;
let progressTimer = null;

function initProgressBar() {
    if (!progressBarEl) {
        progressBarEl = document.createElement("div");
        progressBarEl.id = "global-progress-bar";
        document.body.appendChild(progressBarEl);
    }
}

function startProgressBar() {
    initProgressBar();
    if (!progressBarEl) return;
    clearInterval(progressTimer);
    progressBarEl.classList.add("active");
    progressBarEl.style.width = "25%";

    let currentWidth = 25;
    progressTimer = setInterval(() => {
        if (currentWidth < 85) {
            currentWidth += Math.random() * 12;
            progressBarEl.style.width = currentWidth + "%";
        }
    }, 200);
}

function finishProgressBar() {
    if (!progressBarEl) return;
    clearInterval(progressTimer);
    progressBarEl.style.width = "100%";
    setTimeout(() => {
        progressBarEl.classList.remove("active");
        setTimeout(() => {
            progressBarEl.style.width = "0%";
        }, 300);
    }, 200);
}

// --------------------------------------------------------------------------
// 2. AI Action & Submission Loading Overlay
// --------------------------------------------------------------------------
let aiOverlayEl = null;
let aiDescTimer = null;

function showAiLoading(customTitle, steps, customIcon) {
    if (!aiOverlayEl) {
        aiOverlayEl = document.createElement("div");
        aiOverlayEl.id = "ai-loading-overlay";
        aiOverlayEl.className = "ai-loading-overlay";
        aiOverlayEl.innerHTML = `
            <div class="ai-loading-card">
                <div class="ai-loading-spinner-wrap">
                    <div class="ai-spinner-ring"></div>
                    <div class="ai-spinner-ring-inner"></div>
                    <span class="ai-spinner-core" id="ai-loading-core-icon">✨</span>
                </div>
                <div class="ai-loading-title" id="ai-loading-title">AI Processing in Progress</div>
                <div class="ai-loading-desc" id="ai-loading-desc">Extracting data and evaluating content...</div>
                <div class="ai-loading-step-bar">
                    <div class="ai-loading-step-bar-fill"></div>
                </div>
            </div>
        `;
        document.body.appendChild(aiOverlayEl);
    }

    const titleEl = aiOverlayEl.querySelector("#ai-loading-title");
    const descEl = aiOverlayEl.querySelector("#ai-loading-desc");
    const iconEl = aiOverlayEl.querySelector("#ai-loading-core-icon");

    if (customTitle && titleEl) titleEl.innerText = customTitle;
    if (customIcon && iconEl) iconEl.innerText = customIcon;

    const stepList = Array.isArray(steps) && steps.length > 0 ? steps : [
        "Analyzing candidate profile & qualifications...",
        "Evaluating technical skills against job requirements...",
        "Benchmarking ATS score & recruiter notes...",
        "Finalizing intelligent report..."
    ];

    let currentStepIdx = 0;
    if (descEl) descEl.innerText = stepList[0];

    clearInterval(aiDescTimer);
    aiDescTimer = setInterval(() => {
        if (descEl) {
            descEl.style.opacity = "0";
            setTimeout(() => {
                currentStepIdx = (currentStepIdx + 1) % stepList.length;
                descEl.innerText = stepList[currentStepIdx];
                descEl.style.opacity = "1";
            }, 250);
        }
    }, 2400);

    aiOverlayEl.classList.add("show");
    startProgressBar();
}

function hideAiLoading() {
    if (aiOverlayEl) {
        aiOverlayEl.classList.remove("show");
    }
    clearInterval(aiDescTimer);
    finishProgressBar();
}

// --------------------------------------------------------------------------
// 3. Document Ready Initialization
// --------------------------------------------------------------------------
document.addEventListener("DOMContentLoaded", () => {
    initProgressBar();

    // Theme Toggle Button
    const btn = document.createElement("button");
    btn.innerHTML = document.documentElement.getAttribute("data-theme") === "dark" ? "☀️" : "🌙";
    btn.setAttribute("aria-label", "Toggle dark/light theme");
    btn.style.cssText = "position:fixed; bottom:20px; left:20px; z-index:9999; background:var(--surf); border:1px solid var(--bdr); border-radius:50%; width:44px; height:44px; cursor:pointer; font-size:18px; box-shadow:var(--shadow-md); backdrop-filter:var(--glass-blur); -webkit-backdrop-filter:var(--glass-blur); display:flex; align-items:center; justify-content:center; transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.2s ease;";
    
    btn.onclick = () => {
        const isDark = document.documentElement.getAttribute("data-theme") === "dark";
        const newTheme = isDark ? "light" : "dark";
        document.documentElement.setAttribute("data-theme", newTheme);
        localStorage.setItem("theme", newTheme);
        btn.innerHTML = newTheme === "dark" ? "☀️" : "🌙";
    };
    
    btn.onmouseover = () => btn.style.transform = "scale(1.1) translateY(-2px)";
    btn.onmouseout = () => btn.style.transform = "scale(1)";
    document.body.appendChild(btn);

    // Attach Top Progress Bar to internal links
    document.addEventListener("click", (e) => {
        const link = e.target.closest("a");
        if (link && link.href) {
            const target = link.getAttribute("target");
            const href = link.getAttribute("href");
            if (
                target !== "_blank" &&
                !href.startsWith("#") &&
                !href.startsWith("javascript:") &&
                !href.startsWith("mailto:") &&
                !href.startsWith("tel:") &&
                !link.hasAttribute("download") &&
                link.origin === window.location.origin
            ) {
                startProgressBar();
            }
        }
    });

    // Form Submission Loading Interceptors
    document.addEventListener("submit", (e) => {
        const form = e.target;
        if (!form) return;

        // Check if form is resume upload
        if (form.id === "uploadForm" || form.action.includes("upload.php")) {
            showAiLoading(
                "Gemini AI Screening Resumes",
                [
                    "Uploading & securely extracting PDF text...",
                    "Stripping candidate PII (privacy redaction)...",
                    "Analyzing skills & qualifications with Google Gemini AI...",
                    "Calculating match score and ATS recommendations...",
                    "Saving recruiter evaluation reports..."
                ],
                "⚡"
            );
            return;
        }

        // Check if form is AI Resume Builder
        if (form.action.includes("resume_builder.php") || form.querySelector('input[name="action"][value="generate"]')) {
            showAiLoading(
                "AI Resume Builder Generating...",
                [
                    "Structuring raw experience notes & achievements...",
                    "Polishing impact verbs & professional phrasing...",
                    "Formatting ATS-optimized layout & skills summary...",
                    "Assembling professional resume document..."
                ],
                "📝"
            );
            return;
        }

        // Check if form is AI Resume Checker
        if (form.action.includes("resume_check.php")) {
            showAiLoading(
                "AI Resume Coach Reviewing...",
                [
                    "Extracting resume sections & measuring content depth...",
                    "Evaluating clarity, structural impact & quantifiable metrics...",
                    "Checking ATS header formatting & parser compliance...",
                    "Synthesizing actionable coaching recommendations..."
                ],
                "🎯"
            );
            return;
        }

        // Check if submit button is re-screening
        const submitter = e.submitter;
        if (submitter && submitter.name === "action" && submitter.value === "rescreen_ai") {
            showAiLoading(
                "Re-screening with Gemini AI",
                [
                    "Fetching updated candidate resume text & job requirements...",
                    "Re-evaluating skills alignment with Google Gemini AI...",
                    "Updating ATS match score & interview probing questions...",
                    "Finalizing candidate profile record..."
                ],
                "🤖"
            );
            return;
        }

        // Custom data-ai-loading forms
        if (form.hasAttribute("data-ai-loading")) {
            const title = form.getAttribute("data-loading-title") || "AI Processing...";
            showAiLoading(title);
            return;
        }

        // Generic form submissions: start progress bar and add button loading spinner
        startProgressBar();
        if (submitter && (submitter.classList.contains("btn-primary") || submitter.classList.contains("btn-secondary"))) {
            submitter.classList.add("btn-loading");
        }
    });

    // Auto-format & manage toast notifications
    const toastElements = document.querySelectorAll(".toast-notification, [style*='z-index:3000'], [style*='z-index: 3000']");
    toastElements.forEach(toast => {
        // Ensure toast-notification class
        if (!toast.classList.contains("toast-notification")) {
            toast.classList.add("toast-notification");
        }

        // Add icon badge if not present
        if (!toast.querySelector(".toast-icon-badge")) {
            const iconBadge = document.createElement("span");
            iconBadge.className = "toast-icon-badge";
            iconBadge.innerHTML = "🌿";
            toast.prepend(iconBadge);
        }

        // Add dismiss button if not present
        if (!toast.querySelector(".toast-close-btn")) {
            const closeBtn = document.createElement("button");
            closeBtn.className = "toast-close-btn";
            closeBtn.innerHTML = "✕";
            closeBtn.onclick = (e) => {
                e.stopPropagation();
                dismissToast(toast);
            };
            toast.appendChild(closeBtn);
        }

        // Auto dismiss after 4 seconds, pause on hover
        let dismissTimer = setTimeout(() => dismissToast(toast), 4200);
        toast.onmouseenter = () => clearTimeout(dismissTimer);
        toast.onmouseleave = () => {
            dismissTimer = setTimeout(() => dismissToast(toast), 2500);
        };
    });

    function dismissToast(el) {
        if (!el) return;
        el.classList.add("hiding");
        setTimeout(() => el.remove(), 400);
    }
});

// Finish progress bar once page is fully loaded or restored from bfcache
window.addEventListener("load", finishProgressBar);
window.addEventListener("pageshow", (e) => {
    if (e.persisted) {
        hideAiLoading();
        finishProgressBar();
    }
});
