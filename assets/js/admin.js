// Admin UI utilities: sidebar collapse toggle
(function(){
    const SIDEBAR_KEY = 'adminSidebarCollapsed';
    const sidebar = document.getElementById('sidebar');
    const mainElems = document.querySelectorAll('.main, .main-content');
    const toggleBtn = document.getElementById('sidebarToggle');

    function setCollapsed(collapsed, persist=true){
        if(!sidebar) return;
        if(collapsed){
            sidebar.classList.add('collapsed');
            mainElems.forEach(e=>e.classList.add('collapsed'));
        } else {
            sidebar.classList.remove('collapsed');
            mainElems.forEach(e=>e.classList.remove('collapsed'));
        }
        if(persist) localStorage.setItem(SIDEBAR_KEY, collapsed ? '1' : '0');
    }

    // Initialize from localStorage (desktop only)
    try{
        const stored = localStorage.getItem(SIDEBAR_KEY);
        if(stored === '1' && window.innerWidth > 900) setCollapsed(true, false);
    }catch(e){/* ignore */}

    if(toggleBtn){
        toggleBtn.addEventListener('click', function(){
            if(!sidebar) return;
            if(window.innerWidth <= 900){
                // Mobile: slide sidebar in/out
                sidebar.classList.toggle('active');
            } else {
                // Desktop: collapse state with persistence
                const isCollapsed = sidebar.classList.contains('collapsed');
                setCollapsed(!isCollapsed);
            }
        });
    }

    // Close mobile sidebar when clicking outside
    document.addEventListener('click', function(e){
        if(window.innerWidth <= 900 && sidebar && !sidebar.contains(e.target) && !e.target.closest('#sidebar') && !e.target.closest('#sidebarToggle') && sidebar.classList.contains('active')){
            sidebar.classList.remove('active');
        }
    });

})();

})();
