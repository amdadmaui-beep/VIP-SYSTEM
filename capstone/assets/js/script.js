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
                if (!sidebar.contains(e.target) && !mobileSidebarToggle.contains(e.target)) {
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
function openModal() {
    const modal = document.getElementById('productionModal');
    if (modal) {
        modal.style.display = 'block';
    }
}

function closeModal() {
    const modal = document.getElementById('productionModal');
    if (modal) {
        modal.style.display = 'none';
    }
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('productionModal');
    if (event.target == modal) {
        modal.style.display = 'none';
    }
}
