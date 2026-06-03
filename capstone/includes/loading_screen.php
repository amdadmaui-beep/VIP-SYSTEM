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
    padding: 2.5rem;
    max-width: 400px;
    width: 90vw;
    box-shadow: 0 25px 60px rgba(0,0,0,0.3);
    text-align: center;
}
.loading-spinner {
    width: 48px; height: 48px;
    border: 4px solid #e2e8f0;
    border-top-color: #6366f1;
    border-radius: 50%;
    animation: loadingSpin 0.8s linear infinite;
    margin: 0 auto 1rem;
}
@keyframes loadingSpin { to { transform: rotate(360deg); } }
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
</style>
<div id="loadingScreen" role="status" aria-label="Loading page">
    <div class="loading-card">
        <div class="loading-spinner"></div>
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

    function setProgress(pt, msg) {
        if (pt > p) { p = pt; }
        lastMsg = msg;
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
