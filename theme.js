// theme.js
const savedTheme = localStorage.getItem("theme");
if (savedTheme) {
    document.documentElement.setAttribute("data-theme", savedTheme);
}

document.addEventListener("DOMContentLoaded", () => {
    const btn = document.createElement("button");
    btn.innerHTML = document.documentElement.getAttribute("data-theme") === "dark" ? "☀️" : "🌙";
    btn.style.cssText = "position:fixed; bottom:20px; left:20px; z-index:9999; background:var(--surf); border:1px solid var(--bdr); border-radius:50%; width:45px; height:45px; cursor:pointer; font-size:20px; box-shadow:0 4px 12px rgba(0,0,0,0.1); display:flex; align-items:center; justify-content:center; transition: all 0.2s;";
    
    btn.onclick = () => {
        const isDark = document.documentElement.getAttribute("data-theme") === "dark";
        const newTheme = isDark ? "light" : "dark";
        document.documentElement.setAttribute("data-theme", newTheme);
        localStorage.setItem("theme", newTheme);
        btn.innerHTML = newTheme === "dark" ? "☀️" : "🌙";
    };
    
    btn.onmouseover = () => btn.style.transform = "scale(1.1)";
    btn.onmouseout = () => btn.style.transform = "scale(1)";
    
    document.body.appendChild(btn);

    // Auto-hide toast messages
    const divs = document.querySelectorAll("div");
    divs.forEach(div => {
        if (div.style.zIndex === "3000") {
            div.style.transition = "opacity 0.5s ease, transform 0.5s ease";
            setTimeout(() => {
                div.style.opacity = "0";
                div.style.transform = "translateY(-20px)";
                setTimeout(() => div.remove(), 500);
            }, 3000);
        }
    });
});
