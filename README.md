# SRVP — Student Request & Payment Portal

A PHP + MySQL web app where students request registrar documents, record payments,
and track their status — with a frosted-glass (glassmorphism) UI on every page.

## Features

**Student**
- Register / sign in (student number or email)
- Browse services with fees & processing times
- Submit document requests with notes (gets a reference number like `REQ-20260925-A1B2C3`)
- Record payments (cash / GCash / Maya / bank) with reference no. + optional receipt image upload
- Track every request on a status timeline (pending → processing → ready → released)
- Printable official receipt per request
- Profile: update email / course / year level, change password

**Admin**
- Dashboard KPIs: students, pipeline stages, collected revenue
- Review each request: verify payment, set status, leave a note for the student
- Payments ledger with per-method totals and receipt previews
- Full CRUD for services (name, description, fee, processing days, active toggle)
- Audit log of every administrative action

## Requirements

- XAMPP (or any stack with PHP 8.0+ and MySQL/MariaDB)
- No Composer or npm dependencies

## Install (XAMPP)

1. Copy this folder to `C:\xampp\htdocs\` (or point the Apache DocumentRoot at it).
2. Start **Apache** and **MySQL** from the XAMPP control panel.
3. Open <http://localhost/setup.php> — it creates the database, tables and seed data.
   Re-running it is safe; delete the file on production.
4. Open <http://localhost/> and sign in.

### Roles & ID Formats

| Role | ID Format | Example ID | Email Requirement | Default Demo Login | Password |
|---|---|---|---|---|---|
| **Student** | `YYYY-NNNNN` | `2024-10001` | `@gmail.com` | Register on portal | Your chosen password |
| **Cashier** | `CSH-XXXXX` | `CSH-00001` | `@gmail.com` | `cashier.admin@gmail.com` or `CSH-00001` | `Admin@1234` |
| **Registrar** | `REG-XXXXX` | `REG-00001` | `@gmail.com` | `registrar.admin@gmail.com` or `REG-00001` | `Admin@1234` |

> **Note:** All roles can be registered directly at `/register.php`. The system automatically detects your role based on the ID prefix (`CSH-` for Cashier, `REG-` for Registrar, or `YYYY-NNNNN` for Student) and mandates a `@gmail.com` address.

## Configuration

Database credentials live in `config/database.php` (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`).
Defaults match a stock XAMPP install (`root`, empty password).

## Structure

```
config/database.php   PDO connection + session bootstrap
includes/             auth guards, helpers, glass layout (header/footer)
admin/                dashboard, requests, process, payments, services, audit
student/              dashboard, services, request, requests, payment, profile, receipt
assets/               style.css (frosted-glass design system), main.js
uploads/              receipt images (PHP execution blocked via .htaccess)
database.sql          Unified single schema + stored procedures + seed data
```

## Security notes

- Passwords hashed with `password_hash()` (bcrypt)
- All queries use prepared statements (PDO)
- CSRF tokens on every mutating form; `session_regenerate_id()` on login
- Uploads validated by real image type & size; `.htaccess` blocks script execution
- Output escaped with `e()` (htmlspecialchars) throughout
