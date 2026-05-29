# TP Planner

A complete web application for managing practical work (TP) sessions: classes, TP sessions with steps and materials, checklists, mini-quizzes, and PDF export.

## Requirements

- PHP 7.4+
- MySQL 5.7+ (database `tp_planner`)
- Composer (for PDF export)

## Installation

1. **Database**  
   - Create database: `CREATE DATABASE tp_planner;`  
   - If tables do not exist, run: `database/create_tables.sql`  
   - Optionally load sample data: `database/sample_data.sql`

2. **Configuration**  
   - Edit `config/database.php` and set your MySQL credentials:
     - `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`

3. **PDF export**  
   - From the project root run: `composer install`  
   - This installs TCPDF for “Export PDF” on TP sessions.

4. **Web server**  
   - Point document root to the project folder (or put the project in a subfolder, e.g. `htdocs/TP PLANNER`).  
   - Open in browser: `http://localhost/TP%20PLANNER/` or your base URL.

## Default login

- **Username:** `admin`  
- **Password:** `password123`  

(Change in production; create users in the `users` table with `password_hash()` or `password_verify()`.)

## Project structure

```
TP PLANNER/
├── assets/
│   ├── css/style.css
│   ├── js/app.js
│   └── images/
├── config/
│   ├── config.php
│   └── database.php
├── database/
│   ├── create_tables.sql
│   ├── sample_data.sql
│   └── schema_reference.sql
├── includes/
│   ├── functions.php
│   ├── header.php
│   ├── navbar.php
│   └── footer.php
├── pages/
│   ├── login.php
│   ├── logout.php
│   ├── dashboard.php
│   ├── classes.php
│   ├── tp_sessions.php
│   ├── tp_edit.php
│   ├── tp_view.php
│   ├── tp_pdf.php
│   └── ...
├── index.php
├── composer.json
└── README.md
```

## Features

- **Dashboard:** Stats, recent classes/sessions, quiz scores chart (Chart.js), alert for incomplete checklists.
- **Classes:** CRUD, search, link to sessions per class.
- **TP Sessions:** CRUD, filter by class/date, sort by title/date/duration; steps, materials, checklists, mini-quiz.
- **Checklists:** Before/during/after phases; mark done/undo with badges.
- **Mini-quiz:** Multiple-choice (A/B/C/D), record student name and answers, scores per student and per class.
- **PDF export:** Full TP (title, objectives, steps, materials, checklist, quiz) via TCPDF.

## Database note

If your `quiz_answers` table uses `quiz_id` instead of `tp_quiz_id`, update the references in `pages/tp_view.php`, `pages/tp_pdf.php` (if used there), and `pages/dashboard.php` to use `quiz_id`.

## Security

- Passwords hashed with `password_hash()` / `password_verify()`.
- CSRF token on forms; prepared statements for SQL.
- Role-based access: teacher and admin.
