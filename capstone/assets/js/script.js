// VIP System - Dashboard JavaScript

document.addEventListener('DOMContentLoaded', function () {
    // Ensure stat-card-link navigates on click
    document.querySelectorAll('.stat-card-link').forEach(function(el){
        el.addEventListener('click', function(e){
            // if clicked element is a link or has href, allow default
            var href = el.getAttribute('href');
            if(href){
                // let anchor navigate normally
                return true;
            }
            return false;
        });
    });
    
    // Sidebar collapse functionality
    initSidebarCollapse();

    // Session heartbeat (keeps manager "active users" accurate)
    try {
        var heartbeat = function () {
            var fd = new FormData();
            // Best-effort CSRF if available on page
            var token = (typeof window.csrfToken === 'string' && window.csrfToken)
                || (document.querySelector('meta[name="csrf-token"]') || {}).content
                || (document.querySelector('input[name="csrf_token"]') || {}).value
                || '';
            if (token) {
                fd.append('csrf_token', token);
            }
            fd.append('path', (location.pathname + location.search + location.hash).slice(0, 255));
            fetch((location.pathname.includes('/pages/') ? '../api/session_heartbeat.php' : 'api/session_heartbeat.php'), {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            }).catch(function(){});
        };
        heartbeat();
        setInterval(heartbeat, 20000);
    } catch (e) {}
});

function initSidebarCollapse() {
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const mobileSidebarToggle = document.getElementById('mobileSidebarToggle');
    
    if (!sidebar) return;
    
    // Check localStorage for saved state
    const savedState = localStorage.getItem('sidebarCollapsed');
    if (savedState === 'true') {
        sidebar.classList.add('collapsed');
    }
    
    // Desktop toggle
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
        });
    }
    
    // Mobile toggle
    if (mobileSidebarToggle) {
        mobileSidebarToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            sidebar.classList.toggle('mobile-open');
        });
    }
    
    // Close mobile sidebar when clicking outside
    document.addEventListener('click', function(e) {
        if (window.innerWidth <= 768) {
            if (sidebar && sidebar.classList.contains('mobile-open')) {
                if (!sidebar.contains(e.target) && (!mobileSidebarToggle || !mobileSidebarToggle.contains(e.target))) {
                    sidebar.classList.remove('mobile-open');
                }
            }
        }
    });
    
    // Handle window resize
    window.addEventListener('resize', function() {
        if (window.innerWidth > 768) {
            sidebar.classList.remove('mobile-open');
        }
    });
}

// Modal functions
function openModal(id) {
    const modalId = id || 'productionModal';
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'flex';
        // Add smooth fade in if needed or just block
        if (document.body) document.body.style.overflow = 'hidden';
    }
}

function closeModal(id) {
    const modalId = id || 'productionModal';
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'none';
        if (document.body) document.body.style.overflow = '';
    }
}

// Close modal when clicking the overlay (guard: no classList on some targets / browsers)
window.addEventListener('click', function (event) {
    var t = event && event.target;
    if (!t) {
        return;
    }
    var cl = t.classList;
    if (!cl || typeof cl.contains !== 'function') {
        return;
    }
    if (cl.contains('modal') || cl.contains('inventory-modal')) {
        t.style.display = 'none';
        if (document.body) document.body.style.overflow = '';
    }
});

// Near–real-time sync when a manager changes this user's module access (loads sibling script).
(function () {
    var cs = document.currentScript;
    if (!cs || !cs.src) {
        return;
    }
    var u = cs.src.replace(/[^/]+$/, 'module_access_realtime.js');
    var s = document.createElement('script');
    s.src = u;
    s.async = true;
    document.head.appendChild(s);
})();
