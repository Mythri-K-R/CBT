# ExamSphere Backend — Setup Guide

## Prerequisites (already done)
- [x] Laragon installed at `D:\laragon`
- [x] PHP 8.3 active
- [x] Composer installed
- [x] `vendor/` dependencies installed

> **Tip:** Open the **Laragon Terminal** (right-click the Laragon tray icon → Terminal).
> It puts `php` and `composer` on PATH so you can type commands without full paths.
> All commands below assume you are in the Laragon Terminal inside `D:\PROJECTS\ExamSphere\backend`.

---

## Step 1 — Create your .env file

```bash
cp .env.example .env
php artisan key:generate
```

Then open `.env` and set your database credentials:

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=examsphere
DB_USERNAME=root
DB_PASSWORD=
```

> Laragon's MySQL runs on port **3306** with user `root` and **no password** by default.

---

## Step 2 — Create the database

Open **Laragon → Database** (HeidiSQL) and create a new database named `examsphere`, or run:

```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS examsphere CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

---

## Step 3 — Run migrations

```bash
php artisan migrate
```

This creates all 34 tables plus sessions, jobs, and failed_jobs.

---

## Step 4 — Seed platform data

```bash
php artisan db:seed
```

This seeds:
- Super admin user (`username: superadmin`, `password: change-me-immediately`)
- NEET + JEE Main subjects and chapters
- Exam templates (NEET Full Mock, JEE Main)
- Default platform settings

**Change the super admin password immediately after first login.**

---

## Step 5 — Create storage symlink

```bash
php artisan storage:link
```

---

## Step 6 — Start the development server

```bash
php artisan serve
```

API is now live at `http://127.0.0.1:8000/api/`

---

## Step 7 — Run the queue worker

Open a second Laragon Terminal tab and run:

```bash
php artisan queue:work --queue=high,default,analytics
```

This processes: question imports, QR code generation, PDF reports, test auto-submit, rankings.

---

## Step 8 — Scheduled commands (cron)

For development, trigger manually:

```bash
php artisan schedule:run
```

For production on Windows, add to Task Scheduler:

```
* * * * * cd D:\PROJECTS\ExamSphere\backend && php artisan schedule:run >> NUL 2>&1
```

---

## Super Admin Login

```
POST http://127.0.0.1:8000/api/login
Content-Type: application/json

{
  "username": "superadmin",
  "password": "change-me-immediately"
}
```

---

## Architecture Notes

### Exam Engine Flow
```
Student clicks link → GET /api/test/join/{slug}
Student selects name → POST /api/test/join/{slug}/identify
Read instructions   → GET /api/test/join/{slug}/instructions
Start test          → POST /api/test/join/{slug}/start
  └─ Creates TestAttempt + all TestResponse rows + TestTimerState
  └─ Returns: attempt_uuid, session_token, questions[]

During test (each answer) → POST /api/test/exam/save-response
  └─ X-Exam-Session-Token header required (prevents dual-tab cheating)

Timer sync (every 30s) → GET /api/test/exam/{uuid}/timer
  └─ Returns server-computed remaining_seconds (tamper-proof)

Submit → POST /api/test/exam/{uuid}/submit
  └─ Evaluates all responses, fires TestSubmitted event
  └─ Listeners update student stats + batch stats

View result → GET /api/test/exam/{uuid}/result
```

### Multi-Tenancy
Every institution-scoped model uses the `BelongsToInstitution` trait which auto-applies
`InstitutionScope` — adds `WHERE institution_id = X` to all queries automatically.
Super admin bypasses this and sees all institutions.

### Faculty Permissions
8 boolean columns on `users` table. Middleware `CheckFacultyPermission` enforces them per route.
Institution admins bypass all permission checks.

### Queue Channels
- `high` — auto-submit expired tests (time-sensitive)
- `default` — imports, QR code generation, PDF reports
- `analytics` — stats updates after test submission

## API Base URL
All routes are prefixed with `/api/`. E.g., `POST /api/login`.

## Key Tables
| Table | Purpose |
|-------|---------|
| `test_attempts` | One row per student-test pair |
| `test_responses` | Pre-created at test start, updated on each save |
| `test_timer_state` | Server-side timer (tamper-proof) |
| `proctor_events` | Anti-cheat violations logged here |
| `student_subject_stats` | Running accuracy per student per subject |
| `student_chapter_stats` | Running accuracy per student per chapter |
| `batch_test_stats` | Aggregated batch performance per test |
