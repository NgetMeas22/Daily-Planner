# Agent Guide — Daily Planner

A guide for AI agents (and humans) working on this codebase. Read this before editing code.

## 1. What this project is

A **student-focused "Daily Planner" web app** built with **procedural PHP + MySQL** (no framework, no Composer). It helps users manage:

- **Subjects** — subjects/courses a user studies (CRUD).
- **Planner** — daily study schedule: tasks linked to a subject with a date, day, time range, topic/goal, progress and status.
- **Goals** — long-term goals with target hours, logged hours, auto-computed progress %, category, priority, deadline. Overdue goals (past deadline & not completed) are highlighted red.
- **Expenses** — expenses AND income in dual currency (USD/KHR), per-user monthly budget, remaining-budget calculation, per-day/month/all-time totals.
- **Notes** — two kinds of notes stored in DB: **Simple** (visible immediately) and **Secure** (content is hidden until the user enters their account password to unlock it). Both can store an image directly in the DB.
- **Settings** — functional settings page (`setting.php`): per-user preferences (deep work mode, daily reminders, default focus duration) persisted in the `settings` table. **Profile was merged into it** as tabs (`?tab=account|preferences|danger`): Account = avatar upload + full name + change password; Preferences = the settings form; Danger Zone = account deletion.
- **Theme** — light/dark mode with an **instant toggle** in the navbar (no page reload): JS flips `body[data-theme]` immediately and persists in the background via `theme_toggle.php` (fetch). Shared dark styles live in `assets/theme.css`; Bootstrap's native dark mode is driven by `data-bs-theme` on `<html>`.
- **Dashboard** — dynamic stat cards (live percentages), study-hours chart (day/week/month range), subject distribution chart, quick-add forms, recent lists. Charts re-render themselves on theme change via the `dp:themechange` event.
- **Soft navigation (PJAX)** — internal `.php` link clicks are intercepted by navbar JS: only the `<body>` is swapped (no full reload), head resources are merged/deduped, body scripts are replayed in order, history works via pushState/popstate, and any fetch error falls back to a normal navigation. GET-form submits are also intercepted; POST forms keep the normal PRG flow.
- **Auth** — register / login / logout, password hashing, session-based.

The UI is **bilingual (English / Khmer)**, styled with **Bootstrap 5** and **Tailwind (CDN)** plus custom CSS, and **Chart.js** for dashboard charts.

## 2. Tech stack

| Layer     | Choice                                                             |
|-----------|---------------------------------------------------------------------|
| Language  | PHP 8+ (procedural scripts, no classes/namespaces except built-ins) |
| Database  | MySQL via `mysqli`, database `daily_planner`, charset `utf8mb4`      |
| Frontend  | Bootstrap 5.3.3 + Bootstrap Icons, Tailwind (CDN), Chart.js 4        |
| Fonts     | Inter + Noto Sans Khmer (Google Fonts CDN)                          |
| Server    | Local dev (Laragon/XAMPP) — DB creds are `root` / empty password     |
| Assets    | Bootstrap/Tailwind via CDN; profile images stored IN THE DATABASE     |

## 3. Directory / file map

```
daily_planner/
├── config/
│   └── db.php              # DB connection + auto-create schema + auto-migrations
├── includes/
│   ├── auth.php            # session_start, redirect(), is_logged_in(), require_login()
│   ├── i18n.php            # language switching (en/kh) + t('key') helper
│   ├── navbar.php          # global navbar: avatar upload (stored in DB), language pill, Settings link, logout confirm, active-page highlight
│   ├── header.php          # EMPTY (unused)
│   └── footer.php          # EMPTY (unused)
├── index.php               # Login page (real auth lives here)
├── login.php               # Just redirects to index.php
├── register.php            # Registration (fullname, username, password)
├── logout.php              # session_unset + destroy, redirect to index.php
├── dashboard.php           # Overview: dynamic stat cards, charts, quick-add forms, recent lists, deletes
├── planner.php             # Daily planner: add multi-subject tasks, toggle done, copy prev day, report/PDF
├── subjects.php            # Subject CRUD (add, list, delete)
├── goals.php               # Goal tracker: add, log hours, delete, progress bars, OVERDUE highlighting
├── expenses.php            # Expense/income dual-currency tracking + budget + report/PDF (4 stat cards with top accent bars)
├── notes.php               # Notes: add/edit/delete simple + password-protected (secure) notes; optional image stored in DB
├── profile.php             # STUB: redirects to setting.php?tab=account (profile was merged into settings)
├── setting.php             # Settings with tabs (?tab=account|preferences|danger): profile edit + avatar + password, preferences, delete account
├── theme_toggle.php        # POST endpoint persisting theme (JSON for fetch toggles, redirect for no-JS fallback form)
├── users.php               # EMPTY (unused)
├── uploads/avatars/        # Legacy avatar files (kept for backward compat only)
├── assets/
│   └── theme.css           # Shared dark-mode stylesheet, loaded by navbar for every page
└── includes.zip            # Stale zip of includes/ — can be ignored/deleted
```

## 4. How the app flows

1. **Entry:** `index.php` renders the login form. `register.php` creates accounts. On success both redirect to `dashboard.php`.
2. **Auth guard:** every protected page starts with:
   ```php
   require_once __DIR__ . '/includes/auth.php';
   require_login();
   $currentLang = $_SESSION['lang'] ?? 'en';
   $userId = (int) $_SESSION['user_id'];
   ```
   `require_login()` redirects to `index.php` when not authenticated.
3. **Database:** `config/db.php` is included via `auth.php`. It connects, creates the DB and tables if missing, and runs idempotent `ALTER TABLE` "migrations" (checks `SHOW COLUMNS` before adding columns) so old installs are upgraded in place.
4. **POST handling:** pages detect `$_SERVER['REQUEST_METHOD'] === 'POST'` with hidden `action` fields (e.g. `add_planner`, `toggle_done`, `delete_goal`, `save_budget`). After a successful write they follow the **Post/Redirect/Get** pattern via `redirect(...)`.
5. **Rendering:** pages echo HTML directly and include `$activePage = '...'; include __DIR__ . '/includes/navbar.php';` to get the shared navbar with the active link highlighted.

## 5. Database schema (auto-created by `config/db.php`)

**users** — `id` (PK), `fullname`, `username` (unique), `password` (password_hash), `avatar` (legacy file path), `avatar_data` (MEDIUMTEXT — the profile image itself as a base64 `data:` URI), `monthly_budget` (default 150.00), `created_at`.

**subjects** — `id`, `user_id` (FK→users, CASCADE), `name`, `description`, `created_at`.

**planner** — `id`, `user_id` (FK), `subject_id` (FK→subjects, CASCADE), `study_date` (DATE), `day_name` (ENUM Mon–Sun), `start_time`, `end_time`, `description`, `topic`, `goal`, `result`, `progress` (INT, 0–100), `status` (ENUM 'Pending'|'In Progress'|'Completed'), `created_at`.

**goals** — `id`, `user_id` (FK), `goal_name`, `category` (default 'General'), `priority` (default 'Medium'), `target_hours`, `completed_hours`, `progress`, `deadline`, `status` (ENUM 'In Progress'|'Completed'), `notes` (TEXT NULL — optional free-text notes/words for the goal), `created_at`.

**expenses** — `id`, `user_id` (FK), `title`, `category`, `amount` (DECIMAL, stored in USD), `type` (ENUM 'expense'|'income'), `expense_date`, `note`, `created_at`.

**notes** — `id`, `user_id` (FK), `title`, `content` (TEXT), `type` (ENUM 'simple'|'secure'), `image_data` (MEDIUMTEXT — optional base64 `data:` URI, NULL when no image), `created_at`, `updated_at` (auto-updates on edit).

**settings** — `id`, `user_id` (FK→users, CASCADE), `deep_work` (TINYINT 0/1), `daily_reminders` (TINYINT 0/1), `focus_duration` (INT, one of 25/45/60/90), `updated_at`. A default row is inserted for each user on first visit to `setting.php`.

All FKs are `ON DELETE CASCADE` — deleting a user removes their data everywhere (including `settings` and note images).

## 6. Key business logic / formulas

- **Goal progress:** `progress = min(100, round((completed_hours / target_hours) * 100))`; `status = 'Completed'` when ≥ 100. "Log hours" adds to `completed_hours` and recomputes. See `goals.php`.
- **Locked goals:** a goal is **locked** when `status === 'Completed'` OR `deadline < today` (`goal_is_locked()` in `goals.php`). Locked goals **cannot be edited or have hours logged** — those controls are hidden/disabled and the POST handlers reject them. They can still be deleted and viewed. Colors: Completed = blue accents/progress bar, Overdue = red, active = amber/info based on progress (the accent bar is only 3px and borders are subtle/light so they don't look thick). **Notes/words live only on the detail page** (`goals.php?view=ID`): they are nullable, are NOT on the add/edit form or the cards, and are saved via the `save_goal_notes` POST handler — notes are editable even on locked goals (they don't affect hours/progress). Goal fields themselves are edited via `goals.php?edit=ID` (unlocked only).
- **Overdue goal:** a goal is overdue when `status !== 'Completed'` AND `deadline < today` (`goals.php`). Rendered with a red card border, "Overdue" badge, red progress bar, and an "X days overdue" counter. A summary strip shows Total / In Progress / Completed / Overdue counts.
- **Currency:** amounts are stored in **USD**. KHR is converted at `1 USD = 4050 KHR` (`$khrRate` in `expenses.php`). If USD input is empty, KHR is converted to USD on save.
- **Remaining budget:** `max(0, monthly_budget - monthly_expenses + monthly_income)` for the month of the selected date (`expenses.php`).
- **Planner done state:** a task counts as done when `progress >= 100` OR `status === 'Completed'` (both `planner.php` and `dashboard.php`). Completion time = `end_time - start_time` (only counted when end > start).
- **Profile image:** stored IN THE DATABASE as a base64 `data:` URI in `users.avatar_data` (upload handler in `includes/navbar.php` and `profile.php`). `index.php` loads it into `$_SESSION['user_avatar']` on login. `avatar` (legacy file path) is kept only for backward compatibility. Max upload 2 MB.
- **Secure notes:** `notes.php` unlocks a `secure` note only after `password_verify` succeeds against the user's account password. The unlock is **single-use**: the `unlock_secure` POST handler sets `$_SESSION['secure_notes_unlocked_at'] = time()` and redirects; the whole "Secure Notes" section renders unlocked for that one page view, then the flag is consumed (`unset`) at the **very end of `notes.php` (after the HTML output)** — so **refreshing the page or navigating away re-locks** and a new password entry is required. A **3-minute (180 s) expiry** (`SECURE_UNLOCK_SECONDS`, checked as `time() - timestamp <= SECURE_UNLOCK_SECONDS`) is a secondary safety net: an expired flag is cleaned up on the next load, and client-side JS schedules `location.reload()` after the remaining milliseconds so the view locks in place too. The `lock_secure` POST ("Lock all" button) manually unsets the flag. Content is never rendered until unlocked. The unlock bar shows the remaining time as `ceil($secureUnlockRemaining / 60)` minutes.
- **Note timezone:** `config/db.php` sets `date_default_timezone_set('Asia/Phnom_Penh')` on first line. `format_note_date()` (notes.php) renders `created_at`/`updated_at` (stored in MySQL SYSTEM = local time) with this timezone. Do not remove the timezone line — the default PHP tz on this machine (Europe/Berlin) would render times 5 hours off.
- **Note images:** `upload_note_image()` in `notes.php` validates type (jpg/jpeg/png/webp/gif) and size (≤ 2 MB), returns `null` (no file), `false` (invalid), or a base64 `data:` URI stored in `notes.image_data`. On edit the user may replace or remove the image ("Remove image" checkbox). Form must be `enctype="multipart/form-data"`; field name is `note_image`. Clicking a note image opens a Bootstrap modal lightbox (`#fullImageModal`, `openImage(img)` in the page JS) showing the full image.
- **Note storage:** `notes.title` and `notes.content` are stored **exactly as typed** — they are never hashed or encrypted. The only value hashed anywhere in the app is the account password (`users.password` via `password_hash`); a `$2y$10$...` string can therefore only legitimately appear in `users.password`. If a bcrypt-looking string shows up in a note's `content`, it was pasted in as literal text (e.g., a browser password-manager autofill), not produced by the app.
- **Settings:** `setting.php` is tabbed via `?tab=account|preferences|danger` (server-rendered active state). Preferences save = `save_settings` POST (`deep_work`/`daily_reminders` checkboxes + `focus_duration` whitelist 25/45/60/90 + `theme_mode`, `UPDATE ... SET updated_at = NOW()`). Account tab hosts the former profile handlers: `save_profile` (fullname) and `change_password` (verify current, min 6 chars, match confirm). Danger tab hosts `delete_account` (requires account password, `DELETE FROM users` cascades everything, session destroyed). ALL forms on this page require the hidden `csrf_token`; success feedback flows through `$_SESSION['flash_success']`/`flash_error` + PRG redirect back to the same tab.
- **Theme resolution:** `includes/auth.php` reads `$_COOKIE['theme_mode']` first (no DB hit per request); only when no cookie exists does it query `settings.theme_mode` once and set the cookie. The instant toggle posts to `theme_toggle.php` which updates session + cookie + DB and answers JSON when called via fetch. Pages must render `<body data-theme="...">` from `current_theme()`; navbar JS syncs `data-bs-theme` for Bootstrap.
- **Dashboard stat queries:** all counts/percentages/budget/spent come from ONE prepared subselect query (was 10 separate queries).
- **Expenses totals:** all-time income/expense totals come from a `SUM ... GROUP BY type` aggregate (never fetch-all); the report modal lists only the last 90 days of entries grouped by date.
- **Dashboard dynamic stat cards:**
  - Subjects = % of subjects that have planner sessions; subtext "N used in planner".
  - Planner = % of planner tasks completed.
  - Goals = % of goals with status Completed.
  - Expenses = % of monthly budget spent (red when ≥ 100%).
  - Ring `stroke-dashoffset` = `113 - 113 * pct / 100` (ring circumference is 113).
- **Dashboard charts:**
  - Study hours by `?range=day|week|month` (default `week`). Week groups by `day_name`; month groups by `study_date`; day sums per time slot. Chart panel title uses the range label.
  - Subject distribution = count of planner sessions per subject (top 5).

## 7. Conventions to follow

- **One file per page**, procedural style. Match existing structure (PHP block → HTML → inline JS).
- **Use prepared statements** with `$conn->prepare()` + `bind_param`. Prefer this over string-interpolated SQL. Note: `dashboard.php` currently interpolates `$userId` directly — it is cast to `(int)` so it's safe, but new code should use prepared statements.
- **Escape output** with `htmlspecialchars(...)`. Existing code applies it inconsistently; do it correctly in new/modified code.
- **PRG pattern:** `redirect('page.php?param=' . urlencode($value));` after every successful POST, never echo-and-stay.
- **Include the navbar** on protected pages:
  ```php
  $activePage = 'subjects'; include __DIR__ . '/includes/navbar.php';
  ```
- **i18n:** only the navbar and a few strings use `t('key')`. If you add new UI strings, add keys to both `en` and `kh` arrays in `includes/i18n.php` and keep the `t('english' | 'khmer')` usage in the language pill.
- **Date handling:** validate user-supplied dates with `preg_match('/^\d{4}-\d{2}-\d{2}$/', ...)` before use (see `planner.php`, `expenses.php`).
- **No build step** — CSS/JS are CDN `<link>`/`<script>` tags. New styles go in the page's own `<style>` block.
- **PJAX compatibility is mandatory for every page:** keep `<style>` blocks in `<head>` and page scripts at the END of `<body>`. The navbar shell swaps the whole body, merges head resources (deduped), then replays body scripts in order. Page JS must be idempotent-safe: guard global bindings (`window.__dpX` flags) if you bind to anything outside your own body content. Charts/canvases must re-render on the `dp:themechange` event (see dashboard.php `renderCharts()` pattern) and guard against their canvas being gone (`if (!document.getElementById(...)) return;`). Timers that should not survive navigation go on `window.` (e.g. notes' auto-lock timer is cleared by the shell's `cleanupAfterSwap()` via `window.__notesReloadTimer`).
- **Theme toggle forms:** any form posting to `theme_toggle.php` with hidden `theme_mode` + `return_to` fields works; add the `data-theme-form` attribute ONLY on the navbar instant-toggle form (JS intercepts it).

## 8. Authentication & security notes

- Passwords: `password_hash($pwd, PASSWORD_DEFAULT)` on register, `password_verify` on login (`index.php`). Secure-note unlock also uses `password_verify` against the account password.
- Sessions store `user_id`, `user_name`, optionally `user_avatar` (data URI or legacy path), `lang`, `secure_notes_unlocked_at` (unix timestamp of the last secure-notes unlock — present only while a single-use unlock is in effect), and `csrf_token`.
- **CSRF:** `notes.php` and `setting.php` use per-session CSRF tokens (`$_SESSION['csrf_token']` + a hidden `csrf_token` field; handlers check `csrf_valid()`). **Any new POST form on those pages MUST include the hidden `csrf_token` input or the handler will reject it.** Other pages do not have CSRF yet.
- All multi-user queries filter by `user_id = ?` — keep it that way (it's the only ownership check; there is **no role system**).
- Known gaps (do not introduce new ones): most pages still have **no CSRF tokens**, no `htmlspecialchars` on a few echoes, avatar/image uploads whitelist extensions (`jpg jpeg png webp gif`) and limit size to 2 MB but do not re-check MIME/content.
- Credentials in `config/db.php` are local-dev defaults (`root` / empty). Don't commit real credentials.

## 9. Known issues / gotchas (important!)

- **Schema-version gate in `config/db.php`:** all `CREATE TABLE` / `ALTER TABLE` checks are wrapped in a version gate. They run only once per `SCHEMA_VERSION` and the result is cached in the `meta` table, so later page loads skip ~20 `SHOW COLUMNS` checks (this was the main source of slow page loads). **Always bump `SCHEMA_VERSION` when you add a new migration** or existing installs won't upgrade.
- **Planner history is windowed:** `planner.php` now fetches only the last 30 days of history for the report modal + "copy previous day" list; all-time totals come from a cheap `COUNT`/`SUM` aggregate query instead of loading every row. `goals.php` uses prepared statements for all reads/writes (was interpolating `$userId`).

- **`setting.php` is now functional AND hosts the profile page** (merged): tabs Account/Preferences/Danger Zone via `?tab=`. `profile.php` is a 5-line redirect stub to `setting.php?tab=account` — old links keep working. Its POST forms require the hidden `csrf_token`. The old broken sidebar links to `settings.php` (plural) are gone.
- **Theme gotchas:** `theme_toggle.php` requires POST with a `theme_mode` field (no CSRF — it only flips a preference). The avatar upload handler lives in `includes/navbar.php` and triggers on ANY form field named `user_avatar_file`, then redirects back to the current URL — setting.php's account tab reuses that exact field name for its own upload control.
- **`users.php`, `includes/header.php`, `includes/footer.php` are empty** placeholder files. `header.php`/`footer.php` are not used anywhere.
- **`includes.zip`** is a stale archive of `includes/` — ignore it; don't treat it as source of truth.
- Dashboard stat cards previously had **hardcoded decorative progress rings**; they are now computed from real data in `dashboard.php`.
- `dashboard.php` quick-add "Add Planner" form inserts a single subject with `topic`+`goal`; `planner.php` "Add Daily Tasks" inserts multiple subjects without topic/goal. They both write to `planner` but populate different columns — keep that in mind when changing schema or queries.
- When a subject is deleted, its planner rows are cascade-deleted (FK). Dashboard's `delete_subject` binds only `id` (subjects are unique per user by FK), others bind `id + user_id`.
- `planner.php` copies prior-day templates client-visible via `?copy_from=YYYY-MM-DD`; the copy only pre-fills the form, it does not duplicate rows until saved.
- Profile images uploaded before this change used a file path in `users.avatar` + `uploads/avatars/`. New uploads store a base64 data URI in `users.avatar_data` and null out `avatar` — keep the fallback chain (`avatar_data` then `avatar`) when displaying.
- The navbar logout link uses `onclick="return confirm(...)"` — the confirm text is hardcoded English (not in i18n). If you translate it, add keys to `includes/i18n.php`.
- Expenses' four stat cards use the shared `.exp-stat` / `.exp-accent` / `.exp-label` / `.exp-value` / `.exp-sub` CSS classes defined in `expenses.php`'s `<style>` block. Keep them consistent if you restyle.

## 10. Running / testing locally

- Requires a local MySQL server (Laragon/XAMPP). DB auto-creates on first page load via `config/db.php`.
- Start Apache + MySQL, open the project root in a browser (e.g. `http://localhost/ProJect_All/daily_planner/`).
- There is **no test suite, no linter, no Composer, no build step** in this repo. Verify changes by opening the affected page(s) and exercising the flows (add/delete/toggle).
- When changing schema in `config/db.php`, follow the existing "idempotent migration" pattern: `SHOW COLUMNS FROM ... LIKE 'col'` then `ALTER TABLE ... ADD COLUMN` only when absent.

## 11. When making changes

1. Read the page you're editing fully first; match its style.
2. Keep auth guard lines at the top of any new protected page.
3. Filter every query by the logged-in `$userId`.
4. Use prepared statements + `htmlspecialchars` in new code.
5. If adding POST handlers to `notes.php`/`setting.php`, include the hidden `csrf_token` field and check `csrf_valid()` — those pages require it.
6. Update both `en` and `kh` translation arrays when adding user-visible strings.
7. Test manually via browser since no automated tests exist.
