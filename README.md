<<<<<<< HEAD
# PaySphere

Payroll and HR automation for Kenyan SMEs: employee records, monthly payroll with PAYE, NSSF, SHIF and the
Affordable Housing Levy, payslips, statutory reports, leave and attendance, an employee self-service portal and a
Flutter mobile app.

Final-year project, BSc Computer Science, Kabarak University: Machogu Morghan Atandi (CS/MK/1462/09/23).

| Part | Technology | Folder |
| --- | --- | --- |
| Database | MySQL 8.0 (MariaDB 10.6+ also works) | `database/` |
| API | Native PHP 8.1+ with PDO, PHPMailer | `backend/` |
| Web app | React 18, Vite 5, Material UI 5, React Query, Recharts | `frontend/` |
| Mobile app | Flutter 3 (employees: attendance, payslips, leave, profile) | `mobile/` |

## Quick start with Docker

```bash
cp .env.example .env                 # change the passwords
docker compose up -d --build
docker compose exec backend php scripts/seed_demo.php   # optional demo data
```

Open http://localhost:8080. Demo logins (password `PaySphere2026`):

| Username | Role |
| --- | --- |
| `admin` | Administrator (everything) |
| `hr` | HR manager (employees, payroll, approval, leave) |
| `payroll` | Payroll officer (prepares payroll; cannot approve) |
| `manager` | Line manager (leave and attendance) |
| `jane.wanjiku` | Employee (portal and mobile app) |

## Local development (XAMPP or plain PHP)

1. **Database.** Import `database/schema.sql` (phpMyAdmin → Import, or `mysql -u root -p < database/schema.sql`).
   It creates the `paysphere` database and all tables.
2. **API.**
   ```bash
   cd backend
   cp .env.example .env          # set DB_USER / DB_PASS
   composer install              # PHPMailer; optional in development
   php scripts/seed_demo.php     # optional demo organisation
   php -S 127.0.0.1:8000 -t .    # API at http://127.0.0.1:8000/api/health.php
   ```
3. **Web app.**
   ```bash
   cd frontend
   npm install
   npm run dev                   # http://localhost:5173 (proxies /api to port 8000)
   ```
4. **Mobile app** (needs the Flutter SDK).
   ```bash
   cd mobile
   flutter create . --platforms=android,ios --project-name paysphere_mobile   # generates platform folders once
   flutter pub get
   flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000/api
   ```
   `10.0.2.2` is the host machine as seen from the Android emulator; on a real phone use your computer's LAN IP.
   For plain `http://` during testing, add `android:usesCleartextTraffic="true"` to the `<application>` tag in
   `android/app/src/main/AndroidManifest.xml`. Production should use HTTPS.

Without `MAIL_HOST` set, emails (welcome, login details, payslips, leave decisions) are written to
`backend/logs/email.log` instead of being sent.

## Shared hosting (cPanel / Apache)

1. Build the web app with `VITE_API_BASE_URL=/backend/api npm run build` and upload `frontend/dist/*` to `public_html/`.
2. Upload the `backend/` folder (after `composer install`) to `public_html/backend/` and create `backend/.env`.
   The included `.htaccess` blocks everything except `backend/api/` and passes the Authorization header to PHP.
3. Add an SPA fallback to `public_html/.htaccess`:
   ```apache
   RewriteEngine On
   RewriteCond %{REQUEST_FILENAME} !-f
   RewriteCond %{REQUEST_FILENAME} !-d
   RewriteCond %{REQUEST_URI} !^/backend/
   RewriteRule . /index.html [L]
   ```

## Tests

```bash
php backend/tests/run_tests.php      # 35 payroll-engine tests against hand-calculated values (TC-11 to TC-17)
php backend/scripts/seed_demo.php
php backend/tests/api_test.php       # 21 end-to-end API tests (TC-01 to TC-24) against a running API
php backend/scripts/seed_demo.php --employees=100   # then generate payroll to time 100 employees (TC-18)
```

## Statutory rates

All rates live in `backend/config/payroll_config.php`, each with the date it took effect, so a payroll for any month
uses the rules in force that month and past payslips can be reproduced. When a rate changes, add a new dated entry
(do not edit old ones) and re-run `run_tests.php`.

Current computation:

1. Gross = basic + allowances + overtime (1.5 × basic ÷ 225 h) − unpaid absence (basic ÷ 22 per day)
2. NSSF = 6% of Tier I (up to the lower limit) + 6% of Tier II (up to the upper limit)
3. SHIF = 2.75% of gross, minimum KES 300
4. Housing Levy = 1.5% of gross (employer adds 1.5%)
5. Taxable pay = gross − NSSF − SHIF − Housing Levy (SHIF and the Levy deductible from January 2025)
6. PAYE = band tax (10%, 25%, 30%, 32.5%, 35%) − KES 2,400 personal relief, never below zero
7. Net pay = gross − all deductions

**Check the NSSF limits for February 2026 onwards (KES 9,000 / 108,000) against the current NSSF notice before using
the system for real payroll.**

## Project structure

```
database/schema.sql            all tables (ERD: users, employees, payroll runs, payslips, leave, attendance, subscriptions)
backend/api/                   JSON endpoints (auth, employees, payroll, leave, attendance, dashboard, settings, ...)
backend/lib/PayrollCalculator  the payroll engine (pure, unit-tested)
backend/lib/PayrollService     payroll runs: generate, approve, pay, email payslips
backend/lib/Auth               sessions, lockout, role-based access control
backend/lib/Renderers          printable payslips and reports, statutory CSV
backend/config/                statutory rates
backend/scripts/seed_demo.php  demo data
backend/tests/                 engine and API tests
frontend/src/pages/            web pages (HR app and employee portal)
mobile/lib/                    Flutter employee app
docs/screenshots/              screenshots of the running system for the project report
```

## Not yet implemented

Automated salary disbursement through the Safaricom Daraja B2C API. Salaries are paid through the organisation's own
M-Pesa business account or bank and recorded in PaySphere with their transaction references.
=======
# paysphere
Payroll &amp; HR system for Kenyan SMEs: automated PAYE, NSSF, SHIF and Housing Levy with date-effective rates, payroll approval workflow, payslips, statutory reports, leave and attendance, and an employee self-service portal. PHP 8 + MySQL API, React web app, Flutter mobile app. BSc Computer Science final-year project, Kabarak University.
>>>>>>> 3aed149ce0d70a3aa7dd9f29a40843a8daff97fa
