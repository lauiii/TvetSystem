# College Grading System (PHP + MySQL)

This repository contains a starting implementation for a College Grading System in pure PHP with MySQL.

What was added in this initial pass
- `sql/schema.sql` - SQL schema and sample seed data for programs/courses.
- `include/functions.php` - Helper functions: create users, generate ID/passwords, auto-enroll students.
- `admin/download-template.php` - CSV template download for bulk enrollment.
- `admin/bulk-enroll.php` - Upload CSV to create student accounts and auto-enroll them.
- `assets/css/style.css` - Minimal violet-themed stylesheet.

Quick setup (Windows / XAMPP)
1. Place this project in your htdocs (already at `c:/xampp/htdocs/tvetsystem`).
2. Import the database schema:

   - Using phpMyAdmin: Import `sql/schema.sql` or run the file via the MySQL CLI.

3. Ensure `config.php` is present and creates/points to the database. The existing `config.php` in this project will create the database if it doesn't exist and seed a default admin.

4. Install PHPMailer for email sending (optional but recommended):

   - Use Composer in project root: `composer require phpmailer/phpmailer`

5. Configure SMTP constants used by `include/email-functions.php` (define these in a secure config or environment):

   - `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_FROM`, `SMTP_FROM_NAME`, `SITE_NAME`, `SITE_URL`.

How to test bulk enrollment
1. Log in as admin, or run the `config.php` setup to ensure default admin exists (the included setup script creates `admin@college.edu` / `admin123`).
2. Visit `http://localhost/tvetsystem/admin/download-template.php` to download the CSV template.
3. Fill rows with students and upload via `admin/bulk-enroll.php`.

Next recommended work
- Build role-based middleware and dashboards for admin/instructor/student.
- Add UI pages to manage programs, courses, school years.
- Add instructor grading UI, assessments, and reports (PDF/Excel export).
- Add unit tests or simple integration checks for CSV parsing and DB inserts.

If you want, I can continue implementing the admin dashboard, auth middleware, and the rest of the features (PHPMailer wiring, instructor/student dashboards). Tell me which part to prioritize next.
