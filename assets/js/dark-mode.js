/**
 * Dark Mode Toggle Functionality
 */

// Initialize dark mode on page load
document.addEventListener('DOMContentLoaded', function() {
    // Check for saved theme preference or default to light mode
    const savedTheme = localStorage.getItem('theme') || 'light';
    document.documentElement.setAttribute('data-theme', savedTheme);

    // Bind header toggle if present, else create floating toggle
    const headerToggle = document.getElementById('themeToggleHeader');
    if (headerToggle) {
        headerToggle.addEventListener('click', toggleDarkMode);
        // Sync icon based on theme
        updateHeaderToggleIcon(headerToggle, savedTheme);
        // Also update icon after toggles
        document.addEventListener('themechange', function(e){
            updateHeaderToggleIcon(headerToggle, e.detail.theme);
        });
    } else if (!document.querySelector('.dark-mode-toggle')) {
        createDarkModeToggle();
    }
});

function updateHeaderToggleIcon(btn, theme){
    if(!btn) return;
    btn.textContent = theme === 'dark' ? '☀️' : '🌙';
}

// Create dark mode toggle button (floating)
function createDarkModeToggle() {
    const toggle = document.createElement('button');
    toggle.className = 'dark-mode-toggle';
    toggle.setAttribute('aria-label', 'Toggle dark mode');
    toggle.innerHTML = `
        <span class="sun-icon">☀️</span>
        <span class="moon-icon">🌙</span>
    `;
    
    toggle.addEventListener('click', toggleDarkMode);
    document.body.appendChild(toggle);
}

// Toggle dark mode
function toggleDarkMode() {
    const currentTheme = document.documentElement.getAttribute('data-theme');
    const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
    
    // Apply theme
    document.documentElement.setAttribute('data-theme', newTheme);
    
    // Save preference
    localStorage.setItem('theme', newTheme);
    
    // Add animation to floating toggle if present
    const floatingToggle = document.querySelector('.dark-mode-toggle');
    if (floatingToggle) {
        floatingToggle.style.transform = 'rotate(360deg)';
        setTimeout(() => { floatingToggle.style.transform = ''; }, 300);
    }

    // Emit custom event for listeners (e.g., header icon sync)
    const evt = new CustomEvent('themechange', { detail: { theme: newTheme } });
    document.dispatchEvent(evt);
}

// Export functions for external use
window.toggleDarkMode = toggleDarkMode;
