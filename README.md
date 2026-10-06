# Bhavi Team Portal

PHP 8.0+ with PDO MySQL, Fileinfo and Zip and MySQL 8.0.16+ or MariaDB 10.4+. XAMPP's bundled PHP 8.2 and MariaDB are supported. No Composer or Node dependencies are required.

## Start in XAMPP

1. Start Apache and MySQL.
2. Open `http://localhost/bhavi-new-software/`. First use opens `setup.php`.
3. Choose the administrator and manager names, usernames and passwords. Setup creates the database and accounts, then closes once accounts exist. There are no default passwords.
4. Sign in as the administrator and use **Add employee** to create accounts. Employee accounts need a department, designation and joining date. Share each employee's assigned username and password with them.

The database is `bhavi_team_portal`, using XAMPP's `root` user with an empty password by default. For different credentials, copy `config.local.example.php` to `config.local.php` and edit it. `BHAVI_DB_HOST`, `BHAVI_DB_PORT`, `BHAVI_DB_NAME`, `BHAVI_DB_USER` and `BHAVI_DB_PASSWORD` environment variables override the file.

To install or update the schema from the terminal:

```powershell
php database/install.php
```

The installer preserves existing records, applies the additional account, assignment and reporting fields, and can be run again. The original SQL file remains the base schema; use the PHP installer to include application migrations. Dates use Asia/Kolkata.

## Workflows

- Administrators can add, edit and delete departments. Deletion hides a department from employee choices while preserving history; move existing employees first. Custom role/job-title text does not change employee permissions.
- Employee documents support multiple images and PDFs (50 MB per file, up to 20 files, below 100 MB per request including the form). Photos are stored in protected uploads/photos/ and PDFs in protected uploads/pdf/, with metadata and paths in the database; older database-stored documents remain downloadable. Only administrators and managers can download them. Back up both the database and private storage. Apache PHP limits are set in `.htaccess`, and FastCGI limits in `.user.ini`.
- Employee profile photos are saved under uploads/photos/ with their paths in users.avatar_path. Admins and managers can upload/view photos from the employee form and directory.
- Notifications are grouped by date with a count; expand a day to read its messages. Managers can permanently delete leave requests from Actions, including their history/calendar entries; existing notification text is preserved.
- Managers can include a message when approving/rejecting leave, or use **Save & send note** afterwards. Employees see the message in **My leave requests** and **Notifications**. Managers and administrators can also send individual employee notifications.

- Managers and administrators use **Employee payslips** to select a department and employee and upload a PDF (up to 2 MB) for a salary month. Employees open **My payslips** from their dashboard or sidebar. Each employee can list/download only their own slips, including when coworkers share the same department. Management can access all slips. PDFs are stored privately in the database with one slip per employee/month; run `php database/install.php` when upgrading to create this table.
- Employees can open **Update daily work** directly from their dashboard or sidebar, choose a work date and optional client, describe their work, and save and submit to their manager. The leave calendar shows monthly request totals, pending/approved/rejected counts, application dates and leave dates; cancelled requests are excluded from these counts.

- Login opens the administrator, manager or employee's department dashboard. Direct page access checks the current role and account status. Names and roles appear in the top-right bar; Sign out closes the session.
- Employee, client and holiday records are shared across roles. Administrators create employee accounts. The manager account is created during initial setup. Administrators and managers can edit/delete employee records, clients and holidays. Employees have read-only shared directories. Eye buttons open details dialogs. Passwords and hashes never appear in those dialogs.
- Managers assign a client task by department, employee and date. Employees see their assigned tasks, choose Pending or Completed, enter remarks, and submit the update. Pending older tasks remain available. Employees submit daily work separately and review it in My submitted work; client and work quantities are optional.
- Daily work reports include all submitted employee sheets. Filter by department, employee and inclusive date range. Today, last seven days, this month and custom ranges are available. Download creates a native Excel (.xlsx) workbook including all department fields. Managers can view details, edit entries, review sheets, give feedback or delete a report.
- Employees apply for leave and see their own request history/calendar. Managers approve/reject pending requests; administrators do not access leave requests. Decisions appear in the employee's history and notifications. Working days exclude Sundays and published holidays unless a department schedule specifies otherwise. Overlapping pending/approved leave is rejected.
- Account and client deletion hides them from current directories and prevents deleted accounts signing in. Historical work and leave records remain available. Holiday and assignment deletion also preserve existing history.
- Client logos accept PNG, JPG and WebP up to 50 MB. Uploaded files live under `uploads/`; database rows store their paths. Brand assets show uploaded client logos and any existing saved brand files.

## Verification

```powershell
php tests/integration.php
```

The suite creates a uniquely named test database, starts a local PHP server, exercises actual HTTP requests and database persistence, and removes its test database and uploaded logo afterward. It covers all five employee departments, role/ownership checks, CSRF, CRUD, work assignment/submission, date/category Excel filters, reviews, leave decisions and logout. PHP must be able to write to its configured session directory.

For interactive checks with an installed Chrome or Edge browser:

```powershell
$env:BHAVI_BROWSER = 'C:\Program Files\Google\Chrome\Application\chrome.exe'
php tests/integration.php
```

These checks exercise the details dialog, report category dropdown, date presets and mobile menu in a headless browser. Preview screenshots are saved under `storage/` and excluded from Git. Node.js 22+ is needed only for these optional browser checks.

Apache `.htaccess` files prevent direct access to configuration, internal code, SQL and tests, and prevent executable uploads. The application requires authenticated sessions, hashes passwords, checks CSRF tokens and uses prepared SQL statements.
