<style>
#loadingScreen {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 23, 42, 0.85);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    z-index: 99999;
    display: none;
    align-items: center;
    justify-content: center;
    font-family: 'Barlow', 'Segoe UI', sans-serif;
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.5s ease, visibility 0.5s ease;
}
#loadingScreen.visible {
    display: flex;
    opacity: 1;
    visibility: visible;
}
#loadingScreen.hidden {
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
}
.loading-card {
    background: #fff;
    border-radius: 24px;
    padding: 2.5rem 2.5rem 2rem;
    max-width: 400px;
    width: 90vw;
    box-shadow: 0 25px 60px rgba(0,0,0,0.3);
    text-align: center;
}
.loading-title {
    font-size: 1.15rem; font-weight: 700;
    color: #1e293b; margin: 0 0 0.25rem;
}
.loading-subtitle {
    font-size: 0.85rem; color: #64748b;
    margin: 0 0 1.25rem;
}
.loading-bar {
    width: 100%; height: 6px;
    background: #e2e8f0; border-radius: 9999px;
    overflow: hidden; margin-bottom: 0.75rem;
}
.loading-bar-fill {
    height: 100%;
    background: linear-gradient(90deg, #6366f1, #8b5cf6);
    border-radius: 9999px;
    transition: width 0.4s ease;
    width: 0%;
}
.loading-step {
    font-size: 0.8rem; color: #94a3b8;
    transition: color 0.3s ease;
    margin: 0;
}
.loading-step.active { color: #6366f1; font-weight: 600; }

/* ── Truck Animation ── */
.truck-bounce {
    animation: truckBounce 2s ease-in-out infinite;
    display: block;
    margin: 0 auto 0.75rem;
}
@keyframes truckBounce {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-4px); }
}
.cargo-box {
    transition: opacity 0.6s ease, transform 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
    opacity: 0;
    transform: translateY(12px) scale(0.85);
}
.cargo-box.visible {
    opacity: 1;
    transform: translateY(0) scale(1);
}
.exhaust-puff {
    animation: puff 1.2s ease-in-out infinite;
}
@keyframes puff {
    0%, 100% { opacity: 0; transform: translateX(0) scale(0.6); }
    50% { opacity: 0.5; transform: translateX(6px) scale(1); }
}
.wheel-spin {
    transform-origin: center;
    animation: wheelRotate 2s linear infinite;
}
@keyframes wheelRotate {
    to { transform: rotate(360deg); }
}
</style>
<div id="loadingScreen" role="status" aria-label="Loading page">
    <div class="loading-card">
        <svg viewBox="0 0 240 125" width="260" height="135" xmlns="http://www.w3.org/2000/svg" class="truck-bounce">
            <ellipse cx="120" cy="118" rx="92" ry="4" fill="rgba(0,0,0,0.06)"/>
            <rect x="12" y="22" width="142" height="68" rx="6" fill="#eef2ff" stroke="#6366f1" stroke-width="2.5"/>
            <line x1="12" y1="83" x2="154" y2="83" stroke="#c7d2fe" stroke-width="2"/>
            <g id="box1" class="cargo-box">
                <rect x="20" y="49" width="36" height="28" rx="4" fill="#c084fc" stroke="#a855f7" stroke-width="1.2"/>
                <line x1="38" y1="49" x2="38" y2="77" stroke="#a855f7" stroke-width="1.2" opacity="0.4"/>
                <line x1="20" y1="63" x2="56" y2="63" stroke="#a855f7" stroke-width="1.2" opacity="0.4"/>
            </g>
            <g id="box2" class="cargo-box">
                <rect x="60" y="49" width="36" height="28" rx="4" fill="#a855f7" stroke="#9333ea" stroke-width="1.2"/>
                <line x1="78" y1="49" x2="78" y2="77" stroke="#9333ea" stroke-width="1.2" opacity="0.4"/>
                <line x1="60" y1="63" x2="96" y2="63" stroke="#9333ea" stroke-width="1.2" opacity="0.4"/>
            </g>
            <g id="box3" class="cargo-box">
                <rect x="100" y="49" width="36" height="28" rx="4" fill="#9333ea" stroke="#7c3aed" stroke-width="1.2"/>
                <line x1="118" y1="49" x2="118" y2="77" stroke="#7c3aed" stroke-width="1.2" opacity="0.4"/>
                <line x1="100" y1="63" x2="136" y2="63" stroke="#7c3aed" stroke-width="1.2" opacity="0.4"/>
            </g>
            <g id="box4" class="cargo-box">
                <rect x="38" y="25" width="36" height="22" rx="4" fill="#7c3aed" stroke="#6d28d9" stroke-width="1.2"/>
                <line x1="56" y1="25" x2="56" y2="47" stroke="#6d28d9" stroke-width="1.2" opacity="0.4"/>
                <line x1="38" y1="35" x2="74" y2="35" stroke="#6d28d9" stroke-width="1.2" opacity="0.4"/>
            </g>
            <g id="box5" class="cargo-box">
                <rect x="78" y="25" width="36" height="22" rx="4" fill="#6d28d9" stroke="#5b21b6" stroke-width="1.2"/>
                <line x1="96" y1="25" x2="96" y2="47" stroke="#5b21b6" stroke-width="1.2" opacity="0.4"/>
                <line x1="78" y1="35" x2="114" y2="35" stroke="#5b21b6" stroke-width="1.2" opacity="0.4"/>
            </g>
            <path d="M154 42 L163 24 L204 24 L215 42 L215 90 L154 90 Z" fill="#6366f1" stroke="#4f46e5" stroke-width="2.5" stroke-linejoin="round"/>
            <rect x="167" y="28" width="30" height="3" rx="1.5" fill="#818cf8" opacity="0.4"/>
            <path d="M159 44 L167 30 L197 30 L206 44 Z" fill="#a5b4fc" stroke="#818cf8" stroke-width="1.5" stroke-linejoin="round" opacity="0.85"/>
            <line x1="183" y1="30" x2="188" y2="44" stroke="#818cf8" stroke-width="1" opacity="0.5"/>
            <rect x="207" y="55" width="6" height="8" rx="2" fill="#fde68a"/>
            <rect x="196" y="60" width="8" height="3" rx="1.5" fill="#818cf8" opacity="0.7"/>
            <rect x="15" y="86" width="196" height="6" rx="3" fill="#4f46e5"/>
            <g>
                <circle cx="62" cy="98" r="15" fill="#1e293b"/>
                <circle cx="62" cy="98" r="8" fill="#64748b"/>
                <circle cx="62" cy="98" r="3" fill="#475569"/>
                <g class="wheel-spin">
                    <line x1="62" y1="90" x2="62" y2="106" stroke="#475569" stroke-width="2"/>
                    <line x1="54" y1="98" x2="70" y2="98" stroke="#475569" stroke-width="2"/>
                    <line x1="56.3" y1="92.3" x2="67.7" y2="103.7" stroke="#475569" stroke-width="1.5"/>
                    <line x1="56.3" y1="103.7" x2="67.7" y2="92.3" stroke="#475569" stroke-width="1.5"/>
                </g>
                <circle cx="177" cy="98" r="15" fill="#1e293b"/>
                <circle cx="177" cy="98" r="8" fill="#64748b"/>
                <circle cx="177" cy="98" r="3" fill="#475569"/>
                <g class="wheel-spin">
                    <line x1="177" y1="90" x2="177" y2="106" stroke="#475569" stroke-width="2"/>
                    <line x1="169" y1="98" x2="185" y2="98" stroke="#475569" stroke-width="2"/>
                    <line x1="171.3" y1="92.3" x2="182.7" y2="103.7" stroke="#475569" stroke-width="1.5"/>
                    <line x1="171.3" y1="103.7" x2="182.7" y2="92.3" stroke="#475569" stroke-width="1.5"/>
                </g>
            </g>
            <rect x="210" y="76" width="14" height="5" rx="2.5" fill="#64748b"/>
            <g class="exhaust-puff">
                <circle cx="227" cy="78" r="4" fill="#cbd5e1"/>
                <circle cx="235" cy="76" r="3" fill="#e2e8f0"/>
            </g>
            <g transform="translate(173, 72)" opacity="0.25">
                <path d="M0-6 L1.5-2.5 L6-4 L3-0.5 L6 3 L2 2.5 L0 6 L-2 2.5 L-6 3 L-3-0.5 L-6-4 L-1.5-2.5 Z" fill="#e0e7ff"/>
            </g>
        </svg>
        <h2 class="loading-title">VIP Villanueva Ice Plant</h2>
        <p class="loading-subtitle">Loading, please wait...</p>
        <div class="loading-bar">
            <div class="loading-bar-fill" id="loadingProgress"></div>
        </div>
        <p class="loading-step" id="loadingStatus">Initializing...</p>
    </div>
</div>
<script>
(function() {
    var el = document.getElementById('loadingScreen');
    if (!el) return;
    var bar = document.getElementById('loadingProgress');
    var sts = document.getElementById('loadingStatus');
    var p = 0;
    var lastMsg = 'Initializing...';
    var shown = false;
    var finished = false;

    function updateCargoBoxes(pt) {
        var thresholds = [10, 30, 50, 70, 90];
        for (var i = 0; i < thresholds.length; i++) {
            var box = document.getElementById('box' + (i + 1));
            if (box) {
                if (pt >= thresholds[i]) {
                    box.classList.add('visible');
                }
            }
        }
    }

    function setProgress(pt, msg) {
        if (pt > p) { p = pt; }
        lastMsg = msg;
        updateCargoBoxes(p);
        if (shown) {
            if (bar) bar.style.width = p + '%';
            if (sts) sts.textContent = msg;
        }
    }

    function showScreen() {
        if (finished) return;
        shown = true;
        el.style.display = 'flex';
        el.offsetHeight;
        updateCargoBoxes(p);
        if (bar) bar.style.width = p + '%';
        if (sts) sts.textContent = lastMsg;
        el.classList.add('visible');
    }

    function hideScreen() {
        el.classList.remove('visible');
        el.classList.add('hidden');
        el.setAttribute('aria-hidden', 'true');
    }

    setProgress(10, 'Initializing...');

    var thresholdTimer = setTimeout(showScreen, 600);

    function onDOM() { setProgress(30, 'Loading page structure...'); }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', onDOM);
    } else {
        onDOM();
    }

    function afterDOM() {
        setProgress(50, 'Loading application data...');
        if (document.readyState === 'complete') {
            finish();
        } else {
            window.addEventListener('load', finish);
        }
    }

    var domCheck = setInterval(function() {
        if (document.readyState === 'interactive' || document.readyState === 'complete') {
            clearInterval(domCheck);
            setTimeout(afterDOM, 80);
        }
    }, 30);

    function finish() {
        clearTimeout(thresholdTimer);
        if (!shown) {
            finished = true;
            el.style.display = 'none';
            return;
        }
        if (finished) return;
        finished = true;
        setProgress(90, 'Finalizing...');
        setTimeout(function() {
            setProgress(100, 'Ready!');
            setTimeout(hideScreen, 400);
        }, 200);
    }

    setTimeout(function() {
        if (shown && !finished) {
            finish();
        }
    }, 12000);
})();
</script>
