# Bhavi Team Portal

PHP 8.0+ with PDO MySQL, Mbstring, Fileinfo and DOM, and MySQL 8.0.16+ or MariaDB 10.4+. Zip is optional for native Excel downloads. XAMPP's bundled PHP 8.2 and MariaDB are supported. No Composer or Node dependencies are required.

## Start in XAMPP

1. Start Apache and MySQL.
2. Open `http://localhost/bhavi-new-software/`. First use opens `setup.php`.
3. Choose the administrator and manager names, usernames and passwords. Setup creates the database and accounts, then closes once accounts exist. There are no default passwords.
4. Sign in as the administrator and use **Add employee** to create accounts. Employee accounts need a department, designation and joining date. Share each employee's assigned username and password with them.

On localhost the database is `bhavi_team_portal`, using XAMPP's `root` user with an empty password by default. For different local credentials, copy `config.local.example.php` to `config.local.php` and edit it. Subdomains use `config.live.php` instead. `BHAVI_DB_HOST`, `BHAVI_DB_PORT`, `BHAVI_DB_NAME`, `BHAVI_DB_USER` and `BHAVI_DB_PASSWORD` environment variables override the selected file.

## Deploy or update a live subdomain

1. Set the subdomain's document root to the directory containing `index.php`. Upload the complete application, including `includes/`, `database/`, `assets/`, the protected upload folders, `.htaccess` and `.user.ini`. Preserve existing uploaded files when updating.
2. Create or select the database in your hosting panel. Assign its database user with SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX and REFERENCES permissions on that database. Use the full database name and username, including any hosting account prefix.
3. Copy `config.live.example.php` to `config.live.php` and enter the hosting host/name/user/password. If `config.live.php` is already present, verify its settings instead. This private file is ignored by Git, so include it explicitly in your upload. Localhost continues to use `config.local.php`; copying that file to hosting will not replace the live profile.
4. Open the subdomain. Every database-backed page checks the schema version and applies missing migrations through version 10 before loading. This adds salary, payment, payslip, attendance, document and work-time tables/columns while preserving existing accounts and records. An existing database is selected directly; database creation is attempted only when the configured database does not exist. Optional reporting views are skipped when the account lacks view permissions.
5. Existing accounts can sign in normally. Only an empty installation opens `setup.php` to create administrator and manager accounts. To retry a failed update without SSH, open `setup.php`; existing accounts are preserved and the page returns to sign-in after updating.

For a hosting terminal update, select the live profile explicitly:

```sh
BHAVI_APP_ENV=live php database/install.php
```

In PowerShell, set `$env:BHAVI_APP_ENV = 'live'` before running the installer. CLI otherwise defaults to local settings. Set `BHAVI_APP_ENV=local` for a development domain that is not localhost, a loopback address or a `.localhost` name.

PHP-FPM/FastCGI reads upload limits from `.user.ini`. Apache mod_php uses the conditional PHP module block in `.htaccess`, preserving XAMPP's upload limits. If your hosting provider prohibits `php_value` overrides, remove only that conditional block and set `upload_max_filesize=100M`, `post_max_size=2100M`, `max_file_uploads=21` in the hosting PHP panel or `php.ini`. Upload folders must be writable by PHP. Page links and asset paths are relative and support both a subdomain root and an application subdirectory.

To install or update the schema from the terminal:

```powershell
php database/install.php
```

The installer preserves existing records, applies the additional account, assignment and reporting fields, and can be run again. The original SQL file remains the base schema; use the PHP installer to include application migrations. Dates use Asia/Kolkata.

## Workflows

- Administrators can add, edit and delete departments. Deletion hides a department from employee choices while preserving history; move existing employees first. Custom role/job-title text does not change employee permissions.
- Employee documents support multiple images and PDFs (100 MB per file, up to 20 files (2,000 MB of documents per request; PHP request limit 2,100 MB)). Photos are stored in protected uploads/photos/ and PDFs in protected uploads/pdf/, with metadata and paths in the database; older database-stored documents remain downloadable. Only administrators and managers can download them. Back up both the database and private storage. Apache PHP limits are set in the conditional `.htaccess` module block, and FastCGI limits in `.user.ini`.
- Employee profile photos are saved under uploads/photos/ with their paths in users.avatar_path. Admins and managers can upload photos. Management's employee directory lists the team; employees open **My details** to see only their own saved record, with a **Profile & salary** link. Their own profile displays the same saved personal and salary details as the management profile, including username, role, guardian phone, manager and employment dates. Only management can edit or delete accounts.
- Notifications are grouped by date with a count; expand a day to read its messages. Managers can permanently delete leave requests from Actions, including their history/calendar entries; existing notification text is preserved.
- Managers and administrators can review employee leave requests, include a message when approving/rejecting, or use **Save & send note** afterwards. Employees see the message in **My leave requests** and **Notifications**. Both roles receive a notification when an employee applies; only managers can delete requests. Managers and administrators can also send individual employee notifications.

- Managers and administrators use **Employee payslips** to select a department and employee and upload a PDF (up to 2 MB) for a salary month. Employees open **My payslips** from their dashboard or sidebar. Each employee can list/download only their own slips, including when coworkers share the same department. Management can access all slips. PDFs are stored privately in the database with one slip per employee/month; run `php database/install.php` when upgrading to create this table.
- Employees can open **Update daily work** directly from their dashboard or sidebar, choose a work date and optional client, describe their work, and save and submit to their manager. The leave calendar shows monthly request totals, pending/approved/rejected counts, application dates and leave dates; cancelled requests are excluded from these counts.

- Login opens the administrator, manager or employee's department dashboard. Direct page access checks the current role and account status. Names and roles appear in the top-right bar; Sign out closes the session.
- Administrators create employee accounts. The manager account is created during initial setup. Administrators and managers can edit/delete employee records, clients and holidays. Employees see their own employee record and read-only client/holiday directories. Eye buttons open details dialogs. Passwords and hashes never appear in those dialogs.
- Employees can use the sidebar **Login** and **Logout** attendance buttons to save their check-in and check-out times. The dashboard **Attendance** card opens day-wise history with leave requests; past working days missing either time are saved as leave, while today stays pending until completed. Sundays and published holidays are excluded. These controls record attendance without signing the employee into or out of the portal.
- Administrators have an **Employee attendance** sidebar page with a list of employees. Selecting an employee opens their day-wise login, logout and leave history. Managers and employees cannot use this administrator page to view other employees' attendance.
- Managers and administrators assign one or more numbered tasks with individual briefs to an employee by department, client and date. Employees see each task, choose Pending or Completed, and enter time from 0 through 24 hours, including `2 hours 30 min`, `02:30`, `2.5` hours or `45 min`. Saved times persist in minutes and reload in the assigned work input. Daily work also accepts an optional duration, shown in history and report downloads; management can edit saved durations. Management can filter assigned work by employee and inspect each task's brief and submitted progress. Pending older tasks remain available. Employees submit other daily work separately and review it in My submitted work; client and work quantities are optional.
- Daily work reports include all submitted employee sheets. The **Review** column and exported Review show **Completed** when every entry in the report is completed, and **Pending** while any entry is pending. This updates automatically when work changes. Filter by department, employee and inclusive date range, or choose **All dates** for an employee's full submitted history. Today, last seven days, this month and custom ranges are also available. Download creates a native Excel (.xlsx) workbook including all department fields, status, time spent and remarks. Managers can view details, edit entries, review sheets, give feedback or delete a report.
- Excel downloads use PHP's ZIP extension (`extension=zip` in `php.ini`). If it is unavailable, report downloads fall back to Excel-compatible UTF-8 CSV instead of failing. Enable ZIP support and restart Apache in XAMPP for `.xlsx` downloads.
- Employees apply for leave and see their own request history/calendar. Managers and administrators can approve/reject pending requests; decisions appear in the employee's history and notifications. Working days exclude Sundays and published holidays unless a department schedule specifies otherwise. Overlapping pending/approved leave is rejected.
- Account and client deletion hides them from current directories and prevents deleted accounts signing in. Historical work and leave records remain available. Holiday and assignment deletion also preserve existing history.
- Client logos accept PNG, JPG and WebP up to 50 MB and are saved under `uploads/photos/`; protected image delivery keeps this folder private. Employee images also use `uploads/photos/`, and employee PDFs use `uploads/pdf/`. Database rows store file paths. Brand assets show uploaded client logos and any existing saved brand files.

## Employee salary and client payments

The latest schema is version 10. Missing migrations run automatically on the first database-backed request after an update; the terminal installer can also be used. Existing accounts, clients and documents are preserved.

- Employee forms include employee ID (unique when provided), phone, guardian phone, relieving date, active/inactive status, PF/ESI/Other benefits and dated salary periods. Use **+ Add salary period** for increments. An ongoing previous period closes the day before the next period. Saved periods cannot overlap and retain their original benefit selections and amounts; add a dated period when benefits change. Relieving dates close an ongoing salary period.
- As requested for this portal, both PF and ESI use **50% of monthly salary**. Employee PF is 12% of that base; company PF is another 12%. Employee ESI is 0.75%; company ESI is 3.25%. Unchecked benefits contribute zero. Each component is rounded to paise; take-home subtracts employee contributions only. For INR 14,000 with both benefits: employee PF 840, company PF 840, employee ESI 52.50, company ESI 227.50, take-home 13,107.50. These are the configured business calculations, not an attendance-based payroll engine.
- Employees use the dashboard card or sidebar **My profile & salary** to see their own saved personal details, salary history and contribution breakdown in the same layout used by management. Management opens **Profile & salary** from the employee directory. Employees cannot open another employee's profile.
- Clients include phone, contract dates, package, website/social/GMB URLs, and monthly reels/posters/carousels. Add dated receipts under **Payment**. The database stores the payment ledger and paid total, with a generated remaining balance. Repeated submissions of the same payment form are deduplicated; overpayments and negative amounts are rejected transactionally.
- Add as many named social media links as needed for each client. Managers and administrators can edit a saved payment date or amount from the client's **View saved payments** list; payment edits are rejected if they would exceed the agreed total.
- Each client has a **Profile** action opening a page with its logo, contact details, contract dates, monthly work and social/website links, using the employee profile layout. Management also sees the package, total, receipts, remaining balance and dated payment history, with links to edit the client or saved payments. Employees can view shared contact and work details; financial data and editing controls remain private to management.
- Document files are private on disk; their metadata and paths are persisted in MySQL. Back up uploads together with the database. Apache mod_php reads upload limits from the conditional `.htaccess` module block; FastCGI reads `.user.ini` (which may be cached for several minutes).

## Verification

```powershell
php tests/integration.php
php tests/hosting.php
```

The suite includes an actual 100 MB upload and an over-limit rejection check, which can take several minutes on Windows. Set `BHAVI_SKIP_LARGE_UPLOAD_TEST=1` for a faster run that skips those four boundary assertions.

The suite creates a uniquely named test database, starts a local PHP server, exercises actual HTTP requests and database persistence, and removes its test database and uploaded logo afterward. It covers all five employee departments, role/ownership checks, CSRF, CRUD, work assignment/submission, date/category Excel filters, reviews, leave decisions and logout. PHP must be able to write to its configured session directory.

`tests/hosting.php` checks profile selection and upgrades the original SQL in a disposable database using a temporary user with table permissions and no view permissions. It requires a local test database administrator able to create and remove that temporary database/user. To exercise the full HTTP suite with a subdomain hostname and subdirectory paths, set `BHAVI_TEST_HOST=portal.example.test` and `BHAVI_TEST_SUBDIRECTORY=1` when running `tests/integration.php`. All database settings are overridden with the disposable test database; the real live database is never used by the HTTP server.

For interactive checks with an installed Chrome or Edge browser:

```powershell
$env:BHAVI_BROWSER = 'C:\Program Files\Google\Chrome\Application\chrome.exe'
php tests/integration.php
```

These checks exercise the details dialog, report category dropdown, date presets and mobile menu in a headless browser. Preview screenshots are saved under `storage/` and excluded from Git. Node.js 22+ is needed only for these optional browser checks.

Apache `.htaccess` files prevent direct access to configuration, internal code, SQL and tests, and prevent executable uploads. The application requires authenticated sessions, hashes passwords, checks CSRF tokens and uses prepared SQL statements.
