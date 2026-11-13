# 💉 Covid Vaccination Site Scheduler System

A comprehensive web-based appointment and scheduling platform for Covid-19 vaccination centers. Built with **PHP 8.2**, **Apache (mod_rewrite, headers)**, and **Aiven Cloud MySQL SSL**. Containerized for production deployment on **Render** with robust reverse proxy fixes and an automated 1-Click database installer.

---

## 🚀 Live Deployment Architecture

- **Hosting Platform:** [Render](https://render.com/) (Docker Web Service)
- **Database Service:** [Aiven Cloud MySQL](https://aiven.io/) (High Availability & TLS/SSL Enforced)
- **Runtime:** PHP 8.2 + Apache (`mysqli`, `pdo_mysql`, `gd`, `zip`, `mod_rewrite`, `headers`)
- **Container Port:** Dynamically bound to Render's `$PORT` (Default: `10000` / `80`)
- **Web Root:** `/var/www/html`

---

## 🔑 Credentials & Access Portals

| Role | Username | Password | Access Portal |
| :--- | :--- | :--- | :--- |
| **System Administrator** | `admin` | `admin123` | `/admin/login.php` |
| **Developer Account** | `dev_oretnom` | `admin123` | `/admin/login.php` |
| **Public Portal** | *Guest / Public* | *None* | `/index.php` |
| **1-Click DB Installer** | *Web Utility* | *Direct* | `/db_setup.php` |

---

## 🌐 Environment Variables for Render

Configure these environment variables in your Render Dashboard (**Settings > Environment Variables**):

| Key | Value / Example | Description |
| :--- | :--- | :--- |
| `DB_SERVER` | `mysql-32d19a6e-abadhbihari-33eb.f.aivencloud.com` | Aiven Cloud MySQL hostname |
| `DB_PORT` | `12893` | 5-Digit custom Aiven MySQL port |
| `DB_USERNAME` | `avnadmin` | Database username |
| `DB_PASSWORD` | `<your-aiven-password>` | Database user password |
| `DB_NAME` | `scheduler_db` | Target database name |
| `DB_SSL` | `true` | Enforces TLS/SSL connection (`require_secure_transport`) |
| `BASE_URL` | `https://covid-vaccination-site-scheduler-system.onrender.com` | Live public URL of your deployed app |

---

## ⚡ 1-Click Database Setup (db_setup.php)

This project features a dedicated visual installer and database health checker at `/db_setup.php`.

1. Open `https://<your-app>.onrender.com/db_setup.php` in your browser.
2. The tool verifies:
   - Live TLS/SSL connection to Aiven Cloud MySQL.
   - Target database availability (`scheduler_db`).
   - Status of all 6 required application tables (`individuals`, `individual_meta`, `location`, `schedules`, `system_info`, `users`).
3. Click the **1-Click Import Database** button.
4. The importer executes the schema dump (`database/scheduler.sql`), safely handling:
   - `SET SESSION sql_require_primary_key = 0` (required for Aiven).
   - `SET FOREIGN_KEY_CHECKS = 0`.
   - String literals with HTML entities without semicolon truncation.
5. Once imported, click **Admin Login** to access the dashboard.

---

## 🛡️ Critical Fixes & Modernizations Applied

### 1. PHP 8.2 Compatibility
- **Class Properties:** Class properties in `classes/DBConnection.php` are initialized safely inside `__construct()`, preventing PHP 8.x property initialization errors.
- **Null Safety:** Replaced unprotected query calls with `if ($qry && $qry instanceof mysqli_result)` checks in `classes/SystemSettings.php`, `home.php`, and `classes/Login.php` to prevent fatal errors on uninitialized databases.
- **Report Mode:** Disabled default `mysqli_report` exceptions in PHP 8.1+ to ensure graceful error handling.

### 2. Aiven Cloud MySQL SSL & 5-Digit Port Support
- Enabled `mysqli_init()`, `ssl_set(NULL, NULL, NULL, NULL, NULL)`, and `MYSQLI_CLIENT_SSL` flags.
- Added full support for non-standard 5-digit port forwarding (`12893`).
- Added automatic SSL activation when connecting to remote or cloud databases.

### 3. Render Reverse Proxy & Port 10000 Leak Prevention
- Configured Apache with `UseCanonicalName Off` and `UseCanonicalPhysicalPort Off` via `start.sh` so container-internal `:10000` is never leaked into HTTP redirect headers.
- Enabled `AllowOverride All` for `.htaccess` execution.
- Added `.htaccess` rewrite rules to intercept `/admin` requests and route directly to `/admin/index.php`.
- Added dynamic port binding in `start.sh` listening on Render's dynamic `$PORT`.

---

## 📂 Project Directory Structure

```text
├── admin/                     # Admin dashboard & management modules
│   ├── home.php               # Dashboard overview & statistics
│   ├── index.php              # Admin main container
│   ├── login.php              # Admin authentication interface
│   └── inc/                   # Admin header, footer, navigation, sess_auth
├── classes/                   # Object-oriented core logic
│   ├── DBConnection.php       # PHP 8.2 & Aiven MySQL SSL connector
│   ├── Login.php              # Admin & client authentication handler
│   ├── Master.php             # Core CRUD operations (schedules, locations)
│   ├── SystemSettings.php     # System metadata & branding manager
│   └── Users.php              # User profile management
├── database/                  # SQL dumps & schemas
│   └── scheduler.sql          # Full database dump with tables & seed data
├── dist/                      # AdminLTE CSS & JS assets
├── inc/                       # Public frontend header, navigation, footer
├── plugins/                   # Bundled UI plugins (Bootstrap, jQuery, FullCalendar)
├── uploads/                   # Writable directory for system banners & avatars
├── .htaccess                  # Reverse proxy port-stripping & routing rules
├── db_setup.php               # Visual 1-click database importer & health check
├── Dockerfile                 # Multi-stage PHP 8.2 Apache Docker container
├── initialize.php             # Global environment configuration loader
├── index.php                  # Public landing & appointment booking portal
├── render.yaml                # Render Infrastructure-as-Code blueprint
└── start.sh                   # Apache dynamic port startup script
```

---

## 🚢 Local Docker Run (Optional)

```bash
docker build -t covid-scheduler .
docker run -p 8080:80 \
  -e DB_SERVER="mysql-32d19a6e-abadhbihari-33eb.f.aivencloud.com" \
  -e DB_PORT="12893" \
  -e DB_USERNAME="avnadmin" \
  -e DB_PASSWORD="<your-aiven-password>" \
  -e DB_NAME="scheduler_db" \
  -e DB_SSL="true" \
  covid-scheduler
```
Access the application locally at `http://localhost:8080`.
