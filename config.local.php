<?php
// Local, untracked overrides for SMTP and site name
// DO NOT COMMIT THIS FILE

// School branding
if (!defined('SITE_NAME')) define('SITE_NAME', 'Andres Soriano Colleges of Bislig');
// Base URL of this app (include subfolder)
if (!defined('SITE_URL')) define('SITE_URL', 'http://localhost/tvetsystem');

// Gmail SMTP (use App Password)
if (!defined('SMTP_HOST')) define('SMTP_HOST', 'smtp.gmail.com');
if (!defined('SMTP_PORT')) define('SMTP_PORT', 587);
if (!defined('SMTP_USER')) define('SMTP_USER', 'mydumpi749@gmail.com');
if (!defined('SMTP_PASS')) define('SMTP_PASS', 'wpbscldmjgtssizo'); // replace with your Gmail App Password
if (!defined('SMTP_FROM')) define('SMTP_FROM', 'mydumpi749@gmail.com');
if (!defined('SMTP_FROM_NAME')) define('SMTP_FROM_NAME', 'Andres Soriano Colleges of Bislig');
if (!defined('SMTP_SECURE')) define('SMTP_SECURE', 'tls');
if (!defined('SMTP_DEBUG')) define('SMTP_DEBUG', 0);
