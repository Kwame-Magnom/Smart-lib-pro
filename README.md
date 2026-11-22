# SmartLib Pro — School Library Management System

## SmartLib Pro modules

After the existing password-and-email-OTP login, users enter `dashboard.php`, which now opens the SmartLib Pro application shell. The application uses persistent MariaDB data and includes:

- Role-controlled dashboards for Super Administrators, Administrators, Headmasters, Librarians, Assistant Librarians, Teachers, Students, Accountants, IT Support, Pastors, and members.
- Normalized catalogue and physical-copy tracking, circulation, renewal rules, reservation queues, policy-driven overdue fines, payment receipts, in-app notifications, reporting, and audit records.
- Server-side authorization, CSRF protection, prepared statements, account status enforcement, activity time-out, transaction-protected issue/return/payment workflows, and auditable privileged actions.

### Librarian workspace

Librarian accounts land on a dedicated dashboard at `librarian_dashboard.php`. It summarizes catalogue and circulation activity, overdue loans, reservations, damaged/missing/lost inventory, and outstanding fines, with shortcuts for member registration, catalogue and copy management, circulation, payments, and reports. Librarians can send overdue reminders, fulfil ready reservations, search members and borrowing history, and print loan receipts. The dashboard also lets librarians request administrator approval for exceptional transactions; requests are delivered to active administrators and recorded in the audit log.

Librarians can manage library members but cannot assign staff or administrator roles, manage system users, or change library security and fine policies. Those privileged operations remain available only to authorized administrators.

### Headmaster dashboard

Headmaster accounts land on a dedicated read-only oversight dashboard at `headmaster_dashboard.php`. It summarizes registered students, teachers, staff, available and borrowed copies, overdue and lost books; shows lending trends, popular titles, member activity, librarian circulation activity, operational alerts, and authorized fine totals; and supports monthly, termly (user-supplied dates), and annual report generation as PDF or Excel-compatible CSV.

Headmasters may approve or reject pending exceptional-transaction requests with an optional review note. Decisions are audited and sent to the requester; approval records the decision only and does not execute a transaction. Headmasters have no circulation, user-management, or policy-edit permissions. The dashboard does not query password hashes, OTPs, or reset credentials.

### Member dashboard

Student, Teacher, Pastor, and standard User accounts land on the member dashboard at `student_dashboard.php`. Members can search active catalogue records by title, author, category, ISBN, description, publisher, shelf location, or keyword; check live copy availability; view book descriptions and HTTPS cover images; reserve eligible titles; request approval for available course books; and see only their own loans, due dates, borrowing history, reservations, fines, notifications, and profile. The profile form permits name, email, and school ID updates, but does not accept role or transaction changes. Member access to loans, reservations, fines, and notifications is scoped to the signed-in account.

Members cannot manage accounts, change library policies, or alter circulation and fine records. Loan renewals continue to follow the configured renewal limit and reservation-queue rules. Physical book checkout is completed by library staff; course book requests require administrator approval before the member visits the library desk. The course e-library currently provides reading lists and approval requests, not downloadable digital book files.

### Course e-library and administrator activity

Authenticated catalogue users can browse course reading lists at `course_library.php`. Administrators can create courses and assign active catalogue books to each course. Members can request available course books with an optional note, view or cancel their own pending requests, and receive a notification when an administrator reviews a request. Approval authorizes the member to visit the library for checkout; it does not automatically issue a physical copy.

The course e-library also includes a World Classics recommendation collection. Members can open each title and suggest that the library consider acquiring it. Administrators can approve or reject these suggestions and leave a note. Approval does not purchase a title or create physical inventory; new titles remain at zero copies until actual holdings are entered by library staff. The catalogue supports zero-copy recommendation records, and checkout/reservation actions remain unavailable until real copies are added. To (re)populate the curated recommendations, run `C:\xampp\php\php.exe .\seed_world_classics.php` from the project directory; the command is idempotent and CLI-only.

Administrators and Super Administrators land on `admin_dashboard.php`, which summarizes account and activity counts, lists recent audit events, provides a searchable user-account table for role and sign-in status management, and provides a queue to approve or reject course book requests. Accounts can be made inactive or suspended to stop sign-in; records are retained to preserve borrowing and audit history. Only a Super Administrator can modify privileged administrator accounts or assign privileged roles. The dashboard does not expose password hashes, OTPs, or reset credentials.

### Local demonstration data

The demo seed is intentionally **not** run automatically. It creates clearly labelled `demo.*` accounts and catalogue records, uses the persistent database, and can only run from the CLI:

```powershell
$env:SMARTLIB_SEED_DEMO = '1'
C:\xampp\php\php.exe .\seed_demo.php
```

Demo accounts share the local-test password `SmartLibDemo!2026`; their `.example.test` addresses cannot receive OTP email. Change each email to a deliverable development address before signing in, or use them only to inspect the database. Never run this seed in production.

### Automated smoke tests

The transactional schema and core permission/circulation/payment rules can be verified without leaving test records behind:

```powershell
$env:SMARTLIB_RUN_TESTS = '1'
C:\xampp\php\php.exe .\tests\smartlib_rules_test.php
```

### Inventory, exports, and daily tasks

Librarians and authorized assistants can manage individual physical copies at `http://localhost/assignment/copies.php`. Authorized reports can be downloaded as Excel-compatible CSV files, for example `report_export.php?report=overdue`, `inventory`, `circulation`, or `fines`.

Run the daily reservation/overdue maintenance process from Windows Task Scheduler, never through the browser:

```powershell
C:\xampp\php\php.exe .\daily_library_tasks.php
```

## What changed

- Registration collects a username, email, and password. Public registration always assigns the `User` role.
- Sign-in checks the password first, then emails a newly generated six-digit OTP for each sign-in.
- OTPs expire after 10 minutes, are single-use, are stored as peppered hashes, and are limited to five attempts. Resending is limited to once per 60 seconds.
- New self-registered accounts receive the standard `User` member role and active account status; registration never accepts a role. After registration, users sign in with their password and verify the one-time code sent to their email. An administrator can separately update a member's role when appropriate.
- The dashboard requires an authenticated session. Role checks are performed on the server; a browser form cannot grant an `Administrator` role.
- The library catalogue tracks titles, authors, ISBNs, categories, and individual physical copies. Administrators and Librarians manage titles; Librarians and Assistant Librarians manage circulation.
- Passwords use PHP's `password_hash()`/`password_verify()` with a separate application pepper. PHP's password hash includes its own random salt.
- CSRF tokens, prepared SQL statements, session ID rotation, and HttpOnly/SameSite cookies are enabled.

## Automatic database backups

`backup_database.php` is a command-line-only backup job. It uses `mysqldump` without placing the database password on the command line, writes SQL files to `C:\xampp\backups\secure-portal` (outside the public web root), and removes backups older than 14 days. Change `BACKUP_DIRECTORY`, `MYSQLDUMP_PATH`, or `BACKUP_RETENTION_DAYS` with environment variables when needed.

Administrators can also use **Database backup → Back up now** on `admin_dashboard.php`. This triggers the installed `SecurePortalDatabaseBackup` Windows Task Scheduler task; the page shows the most recent backup file and timestamp. The task must first be installed on the server, and the Apache account must be permitted to run it.

First test one backup from PowerShell:

```powershell
C:\xampp\php\php.exe .\backup_database.php
```

Then create the daily Windows task (default time: 02:00):

```powershell
PowerShell -ExecutionPolicy Bypass -File .\install_backup_task.ps1
```

Use a different time with `-Time '03:30'`. The task is named `SecurePortalDatabaseBackup`; inspect it with `Get-ScheduledTask -TaskName SecurePortalDatabaseBackup`. Keep the backup folder access restricted because database backups contain account information.

## Library roles and database restore points

The available roles are `Super Administrator`, `Administrator`, `Headmaster`, `Librarian`, `Assistant Librarian`, `Teacher`, `Student`, `Accountant`, `IT Support`, `Pastor`, and `User` (member). New registrations are always members. SmartLib Pro exposes only the modules authorized for each role and checks permission on the server for every action. Only a Super Administrator can assign the Super Administrator role.

Administrators can open `security_report.php` to view a password-security report. It shows the password-hash algorithm, cost, and a short SHA-256 fingerprint of each stored hash, along with salt, pepper, and 2FA status. The full hash and pepper are deliberately not displayed in the browser.

To restore a database backup, use the command below. It deliberately accepts only `.sql` files from `BACKUP_DIRECTORY` and requires the explicit confirmation flag. A restore replaces database data but does **not** change `config.php`, Apache environment variables (including `APP_PEPPER`), SMTP settings, backup retention settings, or the scheduled backup task.

```powershell
C:\xampp\php\php.exe .\restore_database.php --file="C:\xampp\backups\secure-portal\infosec_db_YYYY-MM-DD_HH-MM-SS.sql" --confirm-restore
```

## Local configuration

The database defaults match the existing XAMPP setup: `127.0.0.1:3307`, database `infosec_db`, and `root` with an empty password. Set `DB_HOST`, `DB_USER`, `DB_PASSWORD`, `DB_PORT`, or `DB_NAME` in the Apache environment if your local settings differ.

Set `APP_PEPPER` to a unique, randomly generated value of at least 32 characters. The project reads operating-system environment variables first, then `C:\xampp\secure-portal.env`, which is outside the public `htdocs` folder, then an optional `config.local.php`. Use the optional local PHP config for shared hosting that does not provide environment variables; never commit or share its secrets. If the pepper changes, existing accounts will no longer authenticate.

The local environment file also holds the paths to the RSA key pair: `C:\xampp\secure-portal-keys\private.pem` and `C:\xampp\secure-portal-keys\public.pem`. The private key is kept outside `htdocs` with restricted filesystem permissions; never send it to a browser, commit it, or share it. The public key may be distributed when a signing or encryption integration requires it.

For a remote MySQL-compatible database that requires TLS, set `DB_SSL_CA` to the full path of its downloaded CA certificate. When this value is set, the application requires TLS and server-certificate verification; it will not fall back to an unencrypted connection. The PHP MySQL driver must support certificate verification. Keep the CA file outside the public web root.

The login page displays a safe public-key identifier and includes a **View public key** link for the public PEM file. It never displays the private key.

For local XAMPP, outgoing OTP messages can use PHP `mail()` configured in `C:\xampp\sendmail\sendmail.ini` and `sendmail_path` in `C:\xampp\php\php.ini`. When `SMTP_HOST` is set, the app instead sends OTPs with PHPMailer over authenticated TLS/SSL SMTP. Never commit SMTP credentials. The app intentionally does not display OTPs in the browser if email delivery fails.

## InfinityFree deployment checklist

The application requires PHP 8.1 or newer, MySQLi, and a MySQL-compatible database. Before uploading to InfinityFree:

1. Select PHP 8.1 or newer in the hosting control panel.
2. Create the database in the hosting control panel. Copy `config.local.example.php` to `config.local.php` on the server and enter `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, and `DB_PORT` from its MySQL details; InfinityFree database names/users are account-prefixed and its database host is not `localhost`. Keep the real `config.local.php` private and never add it to a public source repository. The application connects to this existing database and creates/upgrades its tables on first use; it does not create the hosting database.
3. Configure a private `APP_PEPPER` of at least 32 characters. Use environment configuration where the host provides it; for InfinityFree, place it in the guarded `config.local.php`. If importing existing accounts, preserve the pepper used to hash their passwords; without it those users cannot authenticate.
4. Upload the `vendor/` directory and Composer files included in the deployment package, and configure `SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`, `SMTP_ENCRYPTION`, and `APP_MAIL_FROM` in `config.local.php` using an external SMTP provider. Do not rely on PHP `mail()` on InfinityFree free hosting. Do not put SMTP credentials or the pepper in a public source repository.
5. Enable HTTPS, upload the application files into the website document root, import any records you intend to keep, and test registration, OTP delivery, member requests, administrator review, and database-backed dashboards before inviting users.
6. Do not upload local test accounts or expose the test suite, demo seed, or local backup artifacts. InfinityFree free hosting does not provide scheduled cron jobs, so the CLI-only daily overdue/reservation maintenance task will not run automatically there.

InfinityFree hosting configuration is account-specific and cannot be completed from this repository: set the database credentials and private secrets in the hosting environment before opening the live site. PHP linting locally does not verify the host's database grants, SMTP delivery, or HTTPS/session behavior.

## Deploy the PHP application to Vercel

The original PHP application can be deployed as an autoscaling FrankenPHP container using [`vercel.json`](./vercel.json), [`Dockerfile.vercel`](./Dockerfile.vercel), and [`Caddyfile.vercel`](./Caddyfile.vercel). This deploys the full PHP application—not the separate Next.js rewrite.

### Prerequisites & Architecture Overview
* **Compute:** Vercel Container Services (Beta). The container runs PHP 8.3 with the `mysqli` and `redis` extensions on FrankenPHP/Caddy, routing web requests to `/app` on port 80.
* **Storage:** TiDB Cloud (MySQL-compatible database) with mandatory TLS server-certificate verification via `DB_SSL_CA`.
* **Session Storage:** A cloud Redis provider with TLS (`rediss://...`), such as Upstash Redis, Redis Cloud, or Aiven. Vercel containers are stateless across requests and cannot rely on local file sessions.
* **Email Delivery:** Authenticated SMTP (e.g. Resend, SendGrid, Amazon SES, Mailgun, Brevo) for one-time verification passwords (OTPs). Containerized PHP does not include a local sendmail MTA.

---

### Step 1: Prepare Git and Import into Vercel

1. Ensure all local credentials, `.env` files, private keys, and SQL dumps are excluded. The repository `.gitignore` and `.dockerignore` automatically block `.env*`, `config.local.php`, `secure-portal.env*`, `*.sql`, `*.key`, and private certificates while preserving `certs/tidb-ca.pem`.
2. Push the repository to a **Private** GitHub repository. Do not use a public repository.
3. Sign in to your [Vercel Dashboard](https://vercel.com/dashboard) and click **Add New... → Project**.
4. Import your private GitHub repository.
5. In the project import screen:
   - **Framework Preset:** Select **Other**.
   - **Root Directory:** Keep as `./` (the repository root). Do **not** select `smartlib-next/`.
   - Do not deploy yet; configure your environment variables first (Step 5).

---

### Step 2: Configure TiDB Cloud & Verify the TLS Certificate

1. **Log in to TiDB Cloud:** Open [TiDB Cloud Console](https://tidbcloud.com/) and navigate to your cluster.
2. **Cluster Type & Certificate Authority:**
   * **TiDB Cloud Serverless (Starter / Essential):** TiDB Cloud uses Let’s Encrypt certificates. The included project file [`certs/tidb-ca.pem`](./certs/tidb-ca.pem) is the official Let’s Encrypt **ISRG Root X1** root certificate (SHA-256 fingerprint: `96BCEC06264976F37460779ACF28C5A7CFE8A3C0AAE11A8FFCEE05C0BDDF08C6`). If using TiDB Cloud Serverless, no certificate file change is required.
   * **TiDB Cloud Dedicated:** Dedicated clusters use AWS Private Certificate Authority. Go to your cluster’s overview page, click **Connect → CA cert**, download the cluster CA file, and overwrite `certs/tidb-ca.pem` with that downloaded certificate before deploying.
3. **Database & User Creation:** Under **Security → Users**, create a database user with read/write grants on your library database (e.g., `infosec_db`).

---

### Step 3: Configure TiDB Cloud Network Access Securely

Do not open your database to all internet addresses (`0.0.0.0/0`) if strict access control is required:

* **With Vercel Static IPs (Pro / Enterprise Add-on):**
  1. In the Vercel dashboard under **Project Settings → Deployment Protection / Networking**, note your project's assigned outbound static IP addresses.
  2. In TiDB Cloud, navigate to **Security → IP Access List** (or **Firewall Rules**).
  3. Click **Add IP Address** and enter only the specific Vercel outbound static IP addresses.
* **Without Vercel Static IPs (Dynamic Serverless Egress):**
  * When using TiDB Cloud Serverless without static egress IPs, firewall rules on public endpoints may require allowing access from your serverless functions.
  * To maintain strong security without static IPs:
    1. Mandatory TLS with server certificate verification is already enforced by the application (`DB_SSL_CA=/etc/ssl/certs/tidb-ca.pem`), preventing man-in-the-middle attacks.
    2. Use a dedicated, randomly generated database password of at least 32 characters.
    3. Grant privileges only to your specific application database (`GRANT ALL ON infosec_db.* TO ...`), never global administrator privileges.
    4. If your organization infrastructure permits, use **VPC Peering** or **Private Endpoints (AWS PrivateLink / Azure Private Link / GCP PSC)** under TiDB Cloud **Settings → Networking** to route database traffic privately.

---

### Step 4: Provision TLS-Enabled Cloud Redis

1. Create a Redis database on a cloud provider such as [Upstash](https://upstash.com/), [Redis Cloud](https://redis.io/cloud/), or [Aiven](https://aiven.io/).
2. Copy the TLS connection URL. It must begin with `rediss://` (note the double 's' indicating TLS).
3. The application parser accepts both common formats:
   * **Redis 6+ ACL (username and password):** `rediss://default:YOUR_PASSWORD@YOUR_REDIS_HOST:6380`
   * **Password only:** `rediss://:YOUR_PASSWORD@YOUR_REDIS_HOST:6380`
   * *Note:* If your password contains special characters (such as `@`, `+`, or `/`), ensure they are URL-encoded in the connection string (for example, `%40` for `@`).

---

### Step 5: Configure Private Environment Variables in Vercel

In the Vercel Dashboard, go to your project’s **Settings → Environment Variables**. Add the following variables for the **Production**, **Preview**, and **Development** environments:

| Variable Name | Required | Description / Example Source |
| :--- | :--- | :--- |
| `DB_HOST` | Yes | TiDB Cloud gateway host (e.g., `gateway01.us-east-1.prod.aws.tidbcloud.com`) |
| `DB_PORT` | Yes | TiDB Cloud port (typically `4000`) |
| `DB_NAME` | Yes | Your database name (e.g., `infosec_db`) |
| `DB_USER` | Yes | TiDB database user (e.g., `<prefix>.root` or your custom user) |
| `DB_PASSWORD` | Yes | TiDB database password |
| `REDIS_URL` | Yes | Encrypted Redis URL starting with `rediss://` |
| `APP_PEPPER` | Yes | Random secret string of at least 32 characters. **Critical:** If migrating existing accounts from local XAMPP, you must use the exact existing `APP_PEPPER` or existing users cannot authenticate. |
| `SMTP_HOST` | Yes | Authenticated SMTP server (e.g., `smtp.resend.com` or `smtp.sendgrid.net`) |
| `SMTP_PORT` | Yes | SMTP port (typically `587` for STARTTLS or `465` for SMTPS) |
| `SMTP_USERNAME` | Yes | SMTP service username or API key name |
| `SMTP_PASSWORD` | Yes | SMTP service password or API key value |
| `SMTP_ENCRYPTION` | Yes | `tls` (for port 587) or `ssl` (for port 465) |
| `APP_MAIL_FROM` | Yes | Verified sender address (e.g., `library@yourverifieddomain.com`) |
| `MOBILE_CORS_ORIGINS` | No | Optional comma-separated origins if connecting Flutter web |

> **Important:** Never put secrets or credentials in `vercel.json`, code files, or Git commits. Enter them only in Vercel’s encrypted environment variables settings.

---

### Step 6: Deploy and Smoke-Test

1. **Deploy:** In the Vercel Dashboard, click **Deploy** (or trigger a push on `main`).
2. **Build Inspection:** Check the Deployment Build Logs. Vercel will build the Docker container using `Dockerfile.vercel`, install the Composer dependencies, position `certs/tidb-ca.pem` at `/etc/ssl/certs/tidb-ca.pem`, and start FrankenPHP.
3. **Smoke-Test Checklist:**
   * **HTTPS & Security Headers:** Open the assigned deployment URL (`https://<project>.vercel.app/login.php`). Verify the connection is HTTPS and that session cookies are created with `Secure; HttpOnly; SameSite=Lax`.
   * **Registration:** Open `/register.php` and create a test user account. Verify that the table schema is created/upgraded in TiDB automatically and the new user is saved.
   * **Email OTP Verification:** Check your email for the six-digit sign-in verification code. Enter the OTP code on the verification screen.
   * **Redis Shared Session:** Once signed in, navigate between `/dashboard.php`, `/course_library.php`, and `/loan_history.php`. Reload the page to confirm the session remains active across stateless container requests.
   * **Platform Behavior Verification:** Sign in with an Administrator account and open `admin_dashboard.php`. Confirm that the Windows Task Scheduler backup section is hidden and displays provider-managed backup instructions instead.

---

### Differences from Local Windows/XAMPP on Vercel

* **Database Backups:** The Windows Task Scheduler backup job (`backup_database.php` / `install_backup_task.ps1`) does not operate inside the Vercel Linux container. Use TiDB Cloud automated snapshots and backups (**TiDB Cloud Console → Backup & Restore**).
* **Daily Maintenance Tasks:** On Windows, `daily_library_tasks.php` is executed daily via Windows Task Scheduler to expire uncollected reservations and update overdue loan states. On Vercel, serverless containers do not run continuous background daemons. If daily reservation processing is required, configure an external scheduler (or a secure cron trigger) to invoke maintenance.
* **Email OTPs:** Local XAMPP often relies on local `sendmail.exe` / `mail()`. On Vercel, authenticated SMTP settings (`SMTP_HOST`, etc.) are required; the application safely halts OTP delivery with a logged diagnostic if SMTP credentials are missing on Vercel.

## Database and existing accounts

On first request, the app creates or upgrades the authentication tables plus roles, catalogue metadata, physical copies, loans, reservations, fines, payments, notifications, audit logs, and library settings in `infosec_db`. Take a database backup before first use. Existing rows from the old TOTP/SHA-256 implementation are preserved, but those credentials are not compatible with the new password format and those accounts have no email address yet. Delete old test rows in phpMyAdmin (or provision/reset them securely) before registering the same usernames again.

To grant an administrator, register the account normally and then change its `role` to `Administrator` in phpMyAdmin or through a trusted database administrator. Existing `Admin` records are automatically migrated to `Administrator` when the app first connects. Never expose role selection on public registration.

## Run

Start Apache and MySQL in the XAMPP Control Panel, then open `http://localhost/assignment/`. The PHP entry point redirects to the sign-in page. `index.html` is only a static fallback; use the URL through Apache, not a `file://` path.

## Flutter mobile app

The Flutter member app is in [`Magnom mobile app/`](./Magnom%20mobile%20app/). It uses the existing PHP authentication and library database through the JSON API at `/api/v1/index.php?route=...`.

From PowerShell, install the Flutter dependencies and start the app:

```powershell
Set-Location '.\Magnom mobile app'
flutter pub get
flutter run
```

The default server URL is `http://10.0.2.2/assignment` on the Android emulator and `http://localhost/assignment` elsewhere. Use the settings icon on the sign-in screen to enter another server URL. A physical phone must be able to reach the computer running Apache; use HTTPS for a deployed or network-accessible server. Android permits cleartext HTTP only to the local emulator/loopback addresses. For Flutter web, the API permits same-host and localhost origins. If the web app is hosted on another origin, set `MOBILE_CORS_ORIGINS` on the PHP server to a comma-separated list of exact trusted origins (scheme and port included).

The app supports password sign-in followed by the existing six-digit email OTP, a role-aware member experience, catalogue search, personal loans and history, reservations, course book requests, notifications, profile display-name/school-ID updates, and sign-out. Email is deliberately read-only in the app so changing the account's second-factor destination still requires a separate, verified workflow.

Mobile authentication does not expose the application pepper or store passwords in the app. The API stores only SHA-256 hashes of random bearer tokens, expires them after 30 days, and revokes them on sign-out. OTP challenges are random, single-use, expire after 10 minutes, and share the existing five-attempt limit. Keep `APP_PEPPER`, database credentials, and SMTP credentials in server-side configuration; never add them to Flutter assets or source. Back up the database before first API use because the server creates the mobile challenge and token tables alongside the existing schema.

The API routes are `POST auth/login`, `POST auth/verify`, `POST auth/logout`, `GET me`, `PATCH profile`, `GET books`, `GET loans`, `GET reservations`, `POST reservations`, `GET courses`, `GET course-requests`, `POST course-requests`, `GET notifications`, and `POST notifications/{id}/read`. Except for sign-in and OTP verification, requests require an `Authorization: Bearer …` token. Member actions and personal records are checked and scoped on the PHP server.

Run Flutter checks from the mobile app directory:

```powershell
flutter analyze
flutter test
flutter build apk
```

## Vercel-ready Next.js rewrite

The separate [`smartlib-next/`](./smartlib-next/) project is the Vercel web application using Next.js and Supabase. Its sign-in requires a password and TOTP authenticator verification; Supabase database access is restricted to MFA-verified sessions. See [`smartlib-next/README.md`](./smartlib-next/README.md) for migrations, password hashing, backup policy, and deployment. The static HTML alternative is under [`static-site/`](./static-site/). Do not commit `config.local.php`, `.env.local`, database exports, or service-role credentials.
