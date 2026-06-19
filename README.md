# SenTri
### A Community-Driven Web-Based Safety Incident Reporting & Geospatial Monitoring System

---

## Project Structure

```
sentri-system/
│
├── index.php              # Landing / welcome page
├── login.php              # Multi-portal login (community, barangay, LGU, responder, admin)
├── signup.php             # New user registration with role selector + auto-migration
├── logout.php             # Session destroy
├── dashboard.php          # Main community feed, map, report form
├── admin.php              # Admin control panel (reports, users, audit log, security monitor)
├── forgot_password.php    # Password reset request
├── reset_password.php     # Password reset (via token)
├── verify_email.php       # Email verification handler + resend endpoint
├── install.php            # One-time DB installer - delete after use
│
├── config/
│   ├── db.php             # Database connection (MySQLi, socket + TCP fallback)
│   ├── email.php          # SMTP credentials + APP_URL override (see note below)
│   └── .htaccess          # Blocks direct web access to config/
│
├── core/
│   ├── SenTriMailer.php   # Minimal Gmail SMTP mailer - dynamic APP_URL, no Composer
│   └── HelpGuardMailer.php
│
├── api/
│   ├── reports.php        # Reports CRUD, voting, profile, GPS, image upload, audit log
│   ├── contacts.php       # Emergency contacts CRUD + notification dispatch
│   ├── geocode_proxy.php  # Server-side Nominatim proxy (CORS-safe)
│   └── security.php       # Security Monitor endpoint (failed logins, audit events)
│
├── portal/
│   ├── community.php      # Community member portal
│   ├── barangay.php       # Barangay official portal
│   ├── lgu.php            # LGU portal
│   ├── responder.php      # First responder portal
│   └── _report_table.php  # Shared report table partial
│
├── uploads/
│   ├── .htaccess          # Blocks PHP execution inside uploads/
│   └── reports/           # Uploaded incident photos (runtime, git-ignored)
│
└── sql/
    ├── sentri.sql                          # Full fresh-install schema
    └── migrations/
        ├── 001_map.sql                     # Geo columns (lat/lng/radius)
        ├── 002_email_verification.sql      # Email token columns
        ├── 003_profile.sql                 # Avatar colour, GPS columns
        ├── 004_report_images.sql           # Photo upload table
        ├── 005_emergency_contacts.sql      # LGU/Hospital/Traffic contacts
        └── 006_roles.sql                   # Expanded role ENUM + community columns
```

---

## Quick Install

### Requirements
- PHP 8.0+
- MySQL 8.0
- Apache or Nginx with PHP-FPM
- Local stack: XAMPP / Laragon / MAMP

### Steps

1. Copy the project folder into your web server root  
   (e.g. `htdocs/sentri-system/` for XAMPP)

2. Configure `config/email.php`:
   - Set your Gmail App Password credentials (see instructions inside the file)
   - Set `APP_URL` **only if** you want to pin a specific address (see note below)

3. Run the installer in your browser:
   ```
   http://localhost/sentri-system/install.php
   ```
   This creates the `sentri` database and all tables automatically, including
   all community columns and the full role ENUM.

4. The installer **automatically locks itself** after a successful run via . You do not need to delete  — it will refuse to run again. To re-run setup on a fresh DB, delete  first.

5. Access the system:
   - Community:    `http://localhost/sentri-system/`
   - Admin panel:  `http://localhost/sentri-system/admin.php`
   - Default admin: set via `install.php` on first run — change immediately after setup
   ⚠️  Change the admin password immediately after first login.

---

## Email Verification Links - Dynamic APP_URL

Email links (account verification, password reset) are built using `APP_URL`
defined in `config/email.php`.

**The default value is `http://localhost` which is a placeholder only.**

### How auto-detection works

`core/SenTriMailer.php` checks whether `APP_URL` is still the default placeholder
at runtime. If it is, `sentri_detect_app_url()` reads the live HTTP request to
build the correct base URL automatically:

| Scenario | Auto-detected link |
|---|---|
| XAMPP default | `http://localhost/sentri-system` |
| Custom port | `http://localhost:8080/sentri-system` |
| Live domain (root) | `https://sentri.example.com` |
| Live domain (subdir) | `https://example.com/sentri` |
| Reverse proxy | Reads `X-Forwarded-Proto` / `X-Forwarded-Host` |

### When to set APP_URL manually

You only need to set `APP_URL` explicitly when:
- Your server sits behind a proxy that does **not** set forwarding headers
- You run background/CLI scripts that send emails (no live `$_SERVER` available)
- You want to enforce a canonical URL regardless of how PHP detects the host

Example in `config/email.php`:
```php
// Production domain - remove this line to use auto-detection
define('APP_URL', 'https://sentri.example.com');
```

Leave it as `http://localhost` to keep auto-detection active everywhere.

---

## Features

### Community (Registered Users)
- Submit geo-tagged incident reports with title, category, severity status, and location pin
- Attach up to 3 photos per report (JPG, PNG, WEBP - max 5 MB each)
- Adjustable affected radius slider (50 m to 3 km)
- Upvote / downvote reports for community credibility scoring
- Interactive Leaflet.js map with colour-coded severity overlays
- In-app GTA-style heading-up navigation to report locations (no Google Maps redirect), with auto-arrival detection
- Filter feed by status, category, or keyword
- Dark / light mode toggle

### Role-Based Portals
- **Community** - report submission, feed browsing, map view
- **Barangay Official** - barangay-scoped report management
- **LGU** - city/municipal-level report oversight
- **First Responder** - BFP / PNP / EMS response view (with responder type)
- **Admin** - full moderation, user management, audit log, security monitor

Official roles (Barangay, LGU, First Responder) skip email verification and go
through administrator approval instead.

### Admin Panel
- Moderate reports (archive / restore / delete)
- Manage user accounts and roles; approve pending official accounts
- Emergency Contacts directory - LGU offices, hospitals, traffic, police, fire
- Reports Audit Log - full create/edit/delete history
- Security Monitor tab - failed login tracking, suspicious activity

### Automatic Notifications
When a **Dangerous** report is submitted, `api/contacts.php` emails all active
emergency contacts whose city (and optionally barangay) matches the report location.

---

## Technology Stack

| Layer        | Technology                              |
|--------------|-----------------------------------------|
| Backend      | PHP 8.0+ (procedural + MySQLi)          |
| Database     | MySQL 8.0 (InnoDB, utf8mb4)             |
| Frontend     | Vanilla HTML5, CSS3, JavaScript ES6+    |
| Map Engine   | Leaflet.js 1.9.4 + OpenStreetMap        |
| Geocoding    | Nominatim (server-proxied)              |
| Icons        | Font Awesome 6.5                        |
| Typography   | Google Fonts - Inter / Poppins          |
| Auth         | PHP native sessions + bcrypt (cost 12)  |
| Email        | Gmail SMTP via SenTriMailer (no Composer) |
| Web Server   | Apache or Nginx + PHP-FPM               |

---

## Database Tables

| Table                  | Purpose                                         |
|------------------------|-------------------------------------------------|
| users                  | Accounts - roles: community / barangay / lgu / first_responder / admin |
| reports                | Incident reports with geo fields                |
| report_images          | Photo attachments linked to reports             |
| report_votes           | Upvote / downvote records (1 per user/report)   |
| emergency_contacts     | LGU, hospital, traffic, police, fire directory  |
| contact_notifications  | Log of emails sent per report                   |
| login_logs             | Authentication audit trail                      |

### users table columns (current)
`id`, `first_name`, `last_name`, `email`, `password`, `role`, `phone_number`,
`org_name`, `position`, `barangay_name`, `municipality`, `responder_type`,
`is_approved`, `email_verified`, `verification_token`, `token_expires_at`,
`reset_token`, `reset_token_expires`, `created_at`

---

## Security Notes

- `config/` is protected by `.htaccess` (direct web access denied)
- `uploads/` blocks PHP execution via `.htaccess`
- All DB queries use prepared statements with bound parameters
- Passwords hashed with `password_hash()` at bcrypt cost 12
- Role-based session checks on every protected page and API endpoint
- Rate limiting: 10 reports per user per hour
- Failed login attempts tracked; Security Monitor tab surfaces suspicious IPs

---

## Changelog

| Date       | Change |
|------------|--------|
| 2026-06-11 | feat: Live GTA-style heading-up in-app navigation (Leaflet/OSM) replacing Google Maps redirects; auto-arrival detection |
| 2026-06-11 | feat: Auto-arrival resolves report without flipping status to Safe; added Resolved/Unresolved badge |
| 2026-06-11 | fix: Duplicate responder marker on repeat navigation |
| 2026-06-10 | fix: responder.php and lgu.php errors (prepared statement handling, fatal error on line 44) |
| 2026-06-09 | feat: Barangay portal toast notifications, state-aware modal buttons, escalate button + escalated badge in report table |
| 2026-06-09 | fix: Removed ob_start/ob_end_flush causing 500 on action requests; added shutdown error handler; audit-log resolve_report |
| 2026-06-08 | fix: Resolved vulnerability assessment crash (normalized scan shape, guarded statusMap lookups) |
| 2026-06-08 | fix: dashboard.php / community role routing redirect to portal/community.php (backward compat) |
| 2026-06-03 | **fix:** `SenTriMailer` now auto-detects `APP_URL` dynamically - email links work on any port, domain, or subdirectory without touching config |
| 2026-06-03 | **fix:** `install.php` creates full `users` schema with all community columns and correct role ENUM |
| 2026-06-02 | **fix:** Auto-migrations moved before POST handler in `signup.php`; free result sets to fix `bind_param` error |
| 2026-05-30 | **feat:** Security Monitor tab added to `admin.php` + `api/security.php` endpoint |
| 2026-05-30 | **fix:** `signup.php` extra `'s'` parameter bug in `bind_param` |
| 2026-05-30 | **feat:** `getFailedAttemptsCount()` helper added to `login.php` |
| 2026-05-29 | **feat:** `sql/migrations/006_roles.sql` - expanded role ENUM + community columns |
| 2026-05-29 | **feat:** Role-based portal pages (`portal/community.php`, `barangay.php`, `lgu.php`, `responder.php`) |
| 2026-05-29 | **feat:** Reports Audit Log tab in `admin.php`; audit trail on delete/restore |
| 2026-04-27 | **fix:** Map bleeding over sidebar (Leaflet stacking context isolation) |
| 2026-04-26 | **fix:** Double-close bug in `post_report`; block `.php` disguised uploads; admin guard on `notify_report` |
| 2026-03-28 | **fix:** Forgot-password link no longer expires immediately on send |
| 2026-03-19 | **feat:** Email verification system added |

---

## Roadmap

- [ ] Real-time proximity alerts via polling or WebSocket
- [ ] SMS notification support (Semaphore / Vonage PH)
- [ ] Auto-call integration for Dangerous reports (Twilio)
- [ ] Mobile app wrapper (future)
