// Admin UI utilities: sidebar collapse toggle
(function(){
    const SIDEBAR_KEY = 'adminSidebarCollapsed';

    function getSidebar(){ return document.getElementById('sidebar'); }
    function isMobile(){ return window.innerWidth <= 900; }

    function setCollapsed(collapsed, persist=true){
        const sidebar = getSidebar();
        const mainElems = document.querySelectorAll('.main, .main-content');
        if(!sidebar) return;
        if(collapsed){
            sidebar.classList.add('collapsed');
            mainElems.forEach(e=>e.classList.add('collapsed'));
        } else {
            sidebar.classList.remove('collapsed');
            mainElems.forEach(e=>e.classList.remove('collapsed'));
        }
        if(persist) try { localStorage.setItem(SIDEBAR_KEY, collapsed ? '1' : '0'); } catch(e){}
    }

    function init(){
        const sidebar = getSidebar();
        const toggleBtn = document.getElementById('sidebarToggle');

        // Initialize from localStorage (desktop only)
        try{
            const stored = localStorage.getItem(SIDEBAR_KEY);
            if(stored === '1' && !isMobile()) setCollapsed(true, false);
        }catch(e){}

        // Bind primary toggle by id
        // Use delegated handler below for all toggles to avoid double-binding

        // Global delegation as a safety net (works even if buttons render later)
        document.addEventListener('click', function(e){
            const toggleEl = e.target.closest('#sidebarToggle, .menu-toggle');
            if (toggleEl) {
                e.preventDefault();
                window.toggleSidebar();
            }
        }, true);

        // Close mobile sidebar when clicking outside
        document.addEventListener('click', function(e){
            const sb = getSidebar();
            const clickedToggle = e.target.closest('#sidebarToggle') || e.target.closest('.menu-toggle');
            if(isMobile() && sb && !sb.contains(e.target) && !e.target.closest('#sidebar') && !clickedToggle && sb.classList.contains('active')){
                sb.classList.remove('active');
            }
        });
    }

    // Expose a global fallback for inline onclick handlers
    window.toggleSidebar = function(){
        const sidebar = getSidebar();
        if(!sidebar) return;
        if(isMobile()){
            sidebar.classList.toggle('active');
        } else {
            const isCollapsed = sidebar.classList.contains('collapsed');
            setCollapsed(!isCollapsed);
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
