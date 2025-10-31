/**
 * Dark Mode Toggle Functionality
 */

// Initialize dark mode on page load
document.addEventListener('DOMContentLoaded', function() {
    // Check for saved theme preference or default to light mode
    const savedTheme = localStorage.getItem('theme') || 'light';
    document.documentElement.setAttribute('data-theme', savedTheme);
    
    // Create and insert dark mode toggle button if it doesn't exist
    if (!document.querySelector('.dark-mode-toggle')) {
        createDarkModeToggle();
    }
});

// Create dark mode toggle button
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
    
    // Add animation class
    const toggle = document.querySelector('.dark-mode-toggle');
    if (toggle) {
        toggle.style.transform = 'rotate(360deg)';
        setTimeout(() => {
            toggle.style.transform = '';
        }, 300);
    }
}

// Export functions for external use
window.toggleDarkMode = toggleDarkMode;
