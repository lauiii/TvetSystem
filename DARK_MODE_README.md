# Dark Mode Implementation

## Overview
Dark mode has been successfully added to the TVET System with a floating toggle button and persistent theme preference.

## Files Added

### 1. `assets/css/dark-mode.css`
Contains all dark mode styles including:
- Dark theme color variables
- Component-specific dark mode overrides
- Floating toggle button styles
- Responsive adjustments

### 2. `assets/js/dark-mode.js`
JavaScript functionality for:
- Theme initialization on page load
- Toggle button creation and functionality
- LocalStorage theme persistence
- Smooth animations

## Pages with Dark Mode Enabled

### Admin Pages:
- ✅ Dashboard (`admin/dashboard.php`)
- ✅ Students (`admin/students.php`)
- ✅ Courses (`admin/courses.php`)
- ✅ Instructors (`admin/instructors.php`)
- ✅ Programs (`admin/programs.php`)
- ✅ School Years (`admin/school-years.php`)
- ✅ Flags (`admin/flags.php`)
- ✅ Reports (`admin/reports.php`)
- ✅ Grades (`admin/grades.php`)
- ✅ Bulk Enroll (`admin/bulk-enroll.php`)

### Instructor Pages:
- ✅ Dashboard (`instructor/dashboard.php`)
- ✅ Manage Grades (`instructor/manage-grades.php`)

### Student Pages:
- ✅ Dashboard (`student/dashboard.php`)

## How to Add Dark Mode to New Pages

Add these two lines to the `<head>` section of any page:

```html
<link rel="stylesheet" href="../assets/css/dark-mode.css">
<script src="../assets/js/dark-mode.js" defer></script>
```

Adjust the path based on your directory level (use `../` for subdirectories).

## Features

### 1. Floating Toggle Button
- Positioned at bottom-right corner
- Shows sun icon (☀️) in light mode
- Shows moon icon (🌙) in dark mode
- Responsive sizing on mobile devices

### 2. Theme Persistence
- User preference saved in browser's localStorage
- Theme automatically applied on page load
- Works across all pages

### 3. Color Scheme

#### Light Mode:
- Background: #f5f6fa
- Cards: #ffffff
- Text: #333
- Accent: #6a0dad (violet)

#### Dark Mode:
- Background: #121212
- Cards: #242424
- Text: #f0f0f0
- Accent: #bb8fce (lighter violet)

### 4. Improved Contrast
- Brighter text colors for better readability
- Enhanced heading visibility
- Clear button states
- Proper alert styling

## Customization

### To Change Colors:
Edit the CSS variables in `assets/css/dark-mode.css`:

```css
[data-theme="dark"] {
    --bg: #121212;           /* Main background */
    --card: #242424;          /* Card background */
    --text: #f0f0f0;         /* Main text */
    --violet: #bb8fce;       /* Accent color */
    /* ... more variables ... */
}
```

### To Change Toggle Button Position:
Edit `.dark-mode-toggle` in `assets/css/dark-mode.css`:

```css
.dark-mode-toggle {
    bottom: 30px;  /* Distance from bottom */
    right: 30px;   /* Distance from right */
}
```

## Browser Compatibility
- ✅ Chrome/Edge (latest)
- ✅ Firefox (latest)
- ✅ Safari (latest)
- ✅ Mobile browsers

## Technical Details

### Data Attribute Approach
The implementation uses the `data-theme` attribute on the `<html>` element to toggle themes:

```javascript
document.documentElement.setAttribute('data-theme', 'dark');
```

This allows CSS to target elements based on theme:

```css
[data-theme="dark"] .card {
    background: var(--card);
}
```

### LocalStorage Key
Theme preference is stored as:
- Key: `theme`
- Values: `light` or `dark`

## Troubleshooting

### Toggle button not appearing?
- Check that `dark-mode.js` is loaded properly
- Verify the script path is correct
- Check browser console for errors

### Styles not applying?
- Ensure `dark-mode.css` is loaded after other stylesheets
- Check that CSS variables are supported (modern browsers)
- Verify the file paths are correct

### Theme not persisting?
- Check if localStorage is enabled in the browser
- Verify no browser extensions are blocking localStorage
- Clear browser cache and try again

## Future Enhancements

Possible improvements:
- System preference detection (prefers-color-scheme)
- Custom theme colors selector
- Multiple theme options
- Smooth transition animations
- Header/sidebar toggle integration

## Support

For issues or questions, refer to the main project documentation or contact the development team.
