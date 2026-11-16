> **Language / 语言:** English | [中文](README.zh-CN.md)

# Team Resource Hub

A bilingual resource-navigation portal for group projects: native PHP 8 + PDO SQLite, no Composer, no framework, no external runtime dependencies — copy the whole directory and it moves.

- Chinese / English UI (front end, login, registration and admin panel fully covered)
- Light and dark themes, choice remembered by cookie
- Category-grouped navigation + instant keyword filtering + aggregated multi-engine search
- Click counting (with de-duplication and rate limiting)
- Standalone admin panel: links / categories / site settings / statistics / reset / password vault
- Two role levels (owner / editor), login rate limiting, CAPTCHA, CSP

---

## Quick Start

```bash
./start.sh                    # defaults to http://127.0.0.1:8000
```

| Page | URL |
| ----- | --------------------------------------- |
| Front-end navigation page | <http://127.0.0.1:8000/index.php> |
| Admin panel | <http://127.0.0.1:8000/admin/login.php> |

**There is no default account.** The first visit to the admin panel is redirected to the installation wizard `admin/install.php`, where the first site owner sets their username and password on the spot. The first visit to any page automatically creates `data/company_nav.sqlite` and seeds demo data (4 categories, 100 links).

> This project is a group-assignment demo. Before deploying it to the public internet, **read the [Security Boundary](#security-boundary) section first** — the default configuration is not safe for public exposure.

> Teammates should read the [Demo testing guide](docs/demo-testing.md) before testing: the pages warn about the impact of each operation; changing or resetting the password alters shared state; the full demo state can be restored from the admin panel.

---

## Features

### Front End (`index.php`)

- Compact resource-workspace layout: brand bar → search → daily quote → category filter → resource grid
- **Aggregated search**: switch between multiple search engines (list and default engine configured in admin "Site Settings"), pressing Enter jumps to the selected engine
- The home page shows the resource search and the daily quote above the fold; calls Hitokoto / ZenQuotes per interface language and caches the result per day, falling back to a bilingual local message when the network fails
- Instant keyword filtering: matches name / keyword / description / URL, with category shortcut tags
- Cards grouped by category: local brand SVG or cached site icon, description, click count
- One-click dark / light theme switch (cookie remembered for 365 days, with system preference as fallback)
- Card clicks reported without a page reload (`track_click.php`)
- Responsive: multi-column grid on desktop, two-column / single-column on phones; the admin sidebar becomes a horizontally scrolling nav on small screens

### Admin Panel (`admin/`)

| Module | Description | Required role |
| ------------------ | ------------------------------------------------------------------------------- | -------------- |
| `index.php` | Link management: create / edit / delete / reorder / pin / enable-disable; fields include Chinese and English title and description, URL, icon, category | editor |
| `categories.php` | Category management: create / edit / delete / move up-down / enable-disable | editor |
| `stats.php` | Click statistics: summary cards, click distribution per category, top-link ranking | editor |
| `vault.php` | Password vault: master-password unlock, AES-256-GCM encryption, reveal on demand, 15-minute idle lock; demo master password `12345678` | owner |
| `settings.php` | Site settings: bilingual site copy, contact e-mail, aggregated search engine list and default engine, registration switch | owner |
| `reset.php` | Reset links, clear to blank, or restore the full demo (automatic backup first; includes `admin / admin123.`, 100 links, zero clicks and vault master password `12345678`) | owner |
| `password.php` | Change the current account's password: verifies the current password, counts towards login rate limiting, touches no data | owner / editor |
| `install.php` | Installation wizard: creates the first site owner on a fresh database, becomes invalid immediately after setup (remote access requires the install key) | — |
| `register.php` | New account registration, disabled by default and enabled by the owner in site settings; includes a graphical CAPTCHA and per-IP registration rate limiting | — |
| `login.php` | Login page: username, password and graphical CAPTCHA | — |
| `captcha.php` | CAPTCHA image generation (GD drawing, local TTF font) | — |
| `icon_refresh.php` | Icon-fetching JSON endpoint: single re-fetch / batch refresh (cursor-based batching) | editor |

**Icon fetching** (`lib/favicon.php`) uses four priority levels: local cache hit → fetch from the source site (`/favicon.ico` plus the HTML `<link rel="icon">`) → third-party service fallback → initial-letter badge. Every retrieved image is written to `assets/icons/`; the front end only ever reads local files.

### Bilingual

- UI strings use the **Chinese source string as the key** (`t('…')`); translations live in `lang/en.php`; `zh-CN` is the source language and needs no translation file
- User-entered content (categories, links, vault entries, site copy) uses **field-level fallback**: an empty English field falls back to Chinese (`localized_field()`)
- Language priority: `?lang=` → `nav_lang` cookie → default `zh-CN`
- Design notes: [`docs/bilingual-design.md`](docs/bilingual-design.md); verification record: [`docs/verification.md`](docs/verification.md)

---

## Tech Stack

Language breakdown (GitHub language statistics):

| Language | Share | Where it is used |
| -------- | ----- | ---------------- |
| PHP | 70.1% | All business logic: front end, admin panel, `config.php` infrastructure, `lib/` modules and the test suite |
| CSS | 28.2% | All styling for the site and the admin panel (`assets/css/`), including the light / dark theme variables |
| JavaScript | 1.4% | Progressive enhancement only: theme switch, language switch, event delegation, dirty-form check, vault interaction (`assets/js/`) |
| Shell | 0.3% | The `start.sh` launcher and the `tests/run.sh` self-test entry point |

**Runtime and storage**

- **PHP 8.x** — developed on 8.0.30, CI matrix covers 8.0 / 8.2 / 8.4; required extensions: `pdo_sqlite` (storage) and `gd` (CAPTCHA drawing)
- **SQLite via PDO** — WAL journal mode, `busy_timeout = 60000ms`, foreign keys ON, consistent snapshots taken with `VACUUM INTO`
- **No Composer, no framework, no build step** — a deliberate constraint: migrating means copying the directory; there is nothing to `composer install` or `npm install`

**Front end**

- Server-rendered PHP templates; no template engine, no SPA framework
- Vanilla JavaScript served straight from `assets/js/` (5 files, no bundler); behaviour is wired through `data-*` attributes and event delegation (`ui-wiring.js`), which is what keeps pages working under a strict CSP
- Two themes driven by CSS variables; `workspace.css` layers the admin workspace layout on top of the base stylesheet

**Security facilities** (full list in [Security Boundary](#security-boundary))

- `password_hash()` for credentials; PBKDF2-HMAC-SHA256 (210k iterations) for vault key derivation; AES-256-GCM for vault payloads
- CSP with per-request nonces, CSRF tokens, multi-dimension rate limiting, SSRF-guarded icon fetching with a magic-number allowlist

**Testing and CI**

- Hand-rolled test runner (`tests/run.sh`: 12 PHP groups + 1 JS group + syntax check) — no PHPUnit, keeping the zero-dependency constraint
- GitHub Actions matrix on PHP 8.0 / 8.2 / 8.4, plus a job that verifies Node is only an optional dependency

---

## Security Boundary

> **This section is not optional.** The project stores its SQLite database under `data/`. If the web server serves that directory as static content, anyone can download the database file and read the administrator password hash from it.

### Three things you must do

| # | Measure | Implementation |
| - | --------------------------------------- | ----------------------------------------------------------------------------------------- |
| 1 | Use `router.php` for local development; never `php -S -t .` | `start.sh` already runs `exec php -S … router.php`; `router.php` returns 403 for `/data/` and `/.git/` |
| 2 | Deny `data/` at the web-server layer in production | nginx / Apache samples in [`docs/deploy.md`](docs/deploy.md); `data/.htaccess` ships with the repo (takes effect on Apache directly) |
| 3 | Use an install key for remote installation, so nobody can claim the first account | Non-local access to `admin/install.php` requires the key in `data/.install_key`; local access (127.0.0.1) is exempt |

`data/.htaccess` is distributed through the `!data/.htaccess` exception rule in `.gitignore`, so it is not lost to local ignore rules. Check the "Post-deployment checklist" in [`docs/deploy.md`](docs/deploy.md) before going live.

### Protections in place

- **SQL injection**: every query goes through PDO prepared statements with emulated prepares disabled (`ATTR_EMULATE_PREPARES = false`)
- **XSS**: all output escaped through `e()`; with CSP nonce, inline scripts must carry `nonce_attr()`, and inline event attributes have been replaced by event delegation (`assets/js/ui-wiring.js`)
- **CSRF**: every POST form carries a token; the admin panel uses `guard_csrf()` centrally
- **Session**: `secure_session_start()` enables `httponly` and `samesite=Lax`, adds `secure` automatically under HTTPS, and regenerates the session ID on successful login
- **Login rate limiting**: two independent counters (per account / per IP) plus a separate CAPTCHA counter; exponential back-off lock once the threshold is reached (thresholds are constants at the top of `config.php`)
- **Registration rate limiting**: **successful** registrations also count towards the same per-IP counter (counting only failures does not stop a script, which succeeds every time); the account is locked after `REGISTER_MAX_PER_WINDOW`; the registration page also requires a CAPTCHA
- **No ghost sessions**: every admin authorisation re-checks that the account still exists in the database, so old sessions die immediately when an account is deleted or renamed
- **Click-spam protection**: the same client counts a given link only once per `click_dedup_window` (24 h by default), and at most `click_max_links_per_hour` (200 by default) distinct links per hour; IP addresses are stored hashed
- **SSRF**: when `lib/favicon.php` fetches external links it rejects internal, loopback and cloud-metadata addresses, validates the DNS resolution result, disables `FOLLOWLOCATION` and re-validates each hop, and checks images against a magic-number allowlist
- **Path normalisation**: before matching, `router.php` flattens the request path (dropping empty segments and resolving `.` / `..` one level up) and double-decodes the URL, then falls back to `realpath()` on the final target — otherwise `/./data/…`, `/assets/../data/…` and `/%2e/data/…` bypass the prefix check (the built-in server flattens paths on its own when locating files, and the two disagreeing is exactly the vulnerability). `tests/http_serve.php` guards this with real HTTP requests plus a reverse control server
- **Security response headers**: `security_headers()` emits CSP, `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy` and others in one place
- **Role separation**: `owner` has full rights, `editor` may only maintain links and categories; registered accounts are always `editor`, and high-risk pages are protected by `require_owner()`
- **No default password**: the system ships with no administrator account — the first owner is created on the spot by `admin/install.php`; remote installation requires the key in `data/.install_key` so nobody can claim the account first; authentication relies solely on the database and `config.php` stores no passwords
- **Vault**: the master password is stored only as a Argon2id hash; the key is derived with PBKDF2-HMAC-SHA256 (210k iterations) and **exists only in the PHP session**; plaintext passwords are encrypted with AES-256-GCM, each record with its own random IV. A forgotten master password cannot be recovered — the vault can only be cleared

### CSP is enforced

`csp_mode` in `config.php` is currently **`'enforce'`**: violating scripts are blocked by the browser outright.

The policy is `default-src 'self'`, scripts run on a per-request nonce, plus `object-src 'none'`, `frame-ancestors 'self'`, `base-uri 'self'` and `form-action 'self'`.

Switching to enforce requires several preconditions. The static ones are guarded automatically by `tests/structure.php`; the correctness of the rendered output (every inline script carries a nonce, pages carry no inline event attributes) is asserted by `tests/http_serve.php` on real HTTP responses — run `./tests/run.sh` after changing code:

- Inline `<script>` must be written as `<script<?= nonce_attr() ?>>`
- **No** inline event attributes (`onclick` / `onsubmit` etc.) — use `data-*` plus event delegation instead
- **No** `eval` / `new Function` (the policy does not allow `unsafe-eval`)

> If theme switching, language switching, confirmation dialogs or CAPTCHA refresh stop working after opening a page,
> set `csp_mode` back to `'report'` to restore them immediately, then investigate which directive was blocked.

---

## Project Structure

```
navsystem/
├── index.php               # Front-end navigation home page
├── track_click.php         # Click-count endpoint (increments after de-duplication and rate limiting)
├── daily_quote.php         # Same-origin endpoint for the daily quote (API + daily cache + fallback)
├── config.php              # Global config + infrastructure (DB, settings, auth, rate limiting, CSP, rendering)
├── router.php              # Router script for the PHP built-in server: blocks data/ and .git/ after path flattening
├── start.sh                # Local start script (multiple workers + router.php)
├── README.md
├── lib/                    # Reusable modules (all loaded by config.php, visible to every entry point)
│   ├── i18n.php            # Language selection (allowlist)
│   ├── ui.php              # 24px line icon set
│   ├── layout.php          # Page header/footer, theme switch, brand area, flash messages
│   ├── migrate.php         # Table creation / idempotent column addition / demo data seeding
│   ├── captcha.php         # Login CAPTCHA (generate / verify / draw)
│   ├── icon_security.php   # Image magic-number allowlist + SSRF protection (pure functions, independently testable)
│   ├── backup.php          # Database snapshot (VACUUM INTO) and retention cleanup
│   ├── favicon.php         # Site icon fetching and local caching (delegates validation to icon_security)
│   └── daily_quote.php     # Daily quote API fetching, bilingual cache and fallback
├── lang/
│   ├── zh-CN.php           # Source language (empty implementation; t() returns the source string)
│   └── en.php              # English translations
├── admin/
│   ├── _init.php           # Shared admin logic: auth / require_owner / layout (flash lives in lib/layout.php)
│   ├── _vault.php          # Shared vault library: key derivation / encryption / auto-lock
│   ├── login.php  logout.php  register.php  captcha.php
│   ├── install.php         # Installation wizard: creates the first owner on a fresh database (install key required for non-local access)
│   ├── password.php        # Change password (touches no data; verifies the current password and counts towards login rate limiting)
│   ├── index.php           # Link management
│   ├── categories.php      # Category management
│   ├── settings.php        # Site settings
│   ├── stats.php           # Click statistics
│   ├── vault.php           # Password vault (interaction lives in assets/js/vault.js; runtime parameters passed via #vaultConfig)
│   ├── vault_reveal.php    # Reveal-on-demand endpoint for the vault (POST + CSRF)
│   ├── icon_refresh.php    # Icon fetching endpoint (single / batch)
│   └── reset.php           # Reset demo data
├── assets/
│   ├── css/style.css  workspace.css
│   ├── js/theme.js  i18n.js  ui-wiring.js  workspace.js  vault.js
│   ├── brands/             # Built-in brand SVGs (with LICENSE.md / SOURCES.md)
│   ├── icons/              # Site icons written to disk after fetching
│   ├── background/         # Light and dark background artwork
│   ├── fonts/captcha.ttf   # CAPTCHA font
│   └── favicon.svg  favicon-dark.svg
├── data/                   # Runtime data (not committed; must not be web-accessible)
│   ├── .htaccess           # Apache deny rules (shipped with the repo)
│   ├── demo.json           # Bilingual demo resources
│   ├── company_nav.sqlite  # Created automatically on the local machine
│   ├── .install_key        # Install key (generated on first start; only needed for remote installation)
│   └── backups/            # Automatic backups taken before a reset / change
├── docs/
│   ├── deploy.md           # Deployment security configuration (nginx / Apache / directory permissions / checklist)
│   ├── demo-testing.md     # Demo testing guide (must-read before testing)
│   ├── functional-requirements.md # Functional requirements specification (bilingual, test basis)
│   ├── bilingual-design.md # Bilingual design
│   ├── verification.md     # Bilingual verification record
│   └── workspace-design.md # Front-end visuals and layout design
└── tests/
    ├── run.sh              # Unified self-test entry point (12 PHP test groups + JS tests + syntax check)
    ├── _http.php           # Minimal HTTP client for end-to-end tests (not a test; included by http_serve)
    ├── structure.php       # Engineering-structure guard: config split / data protection / CSP preconditions / no default password
    ├── click_throttle.php  # Click de-duplication and rate limiting
    ├── admin_logic.php     # Admin CRUD / cascading delete / role separation / installation wizard / login and registration rate limiting
    ├── admin_paging.php    # Link-management page return (private functions extracted by anchor point, no duplicate copy)
    ├── i18n_smoke.php      # Bilingual smoke test (en / zh-CN)
    ├── i18n_coverage.php   # Translation coverage (--strict: a missing entry fails)
    ├── icon_security.php   # SSRF protection and image magic-number allowlist
    ├── vault.php           # Vault: master password / KDF / AES-GCM round trip / tamper detection / idle lock
    ├── backup.php          # Database snapshot integrity and retention limit
    ├── http_serve.php      # Starts a real server and issues HTTP requests: data/ blocking (including reverse control), security headers, rendering under CSP, installation flow, admin CRUD
    ├── undefined_calls.php # Entry-point reachability: computes the reachable function set from each entry point's require chain
    └── language_switch.test.js
```

---

## Running Locally

Requirements: **PHP 8.x** (with the `pdo_sqlite` extension), works on macOS / Linux / Windows.

```bash
./start.sh                                    # defaults to 127.0.0.1:8000 with 4 workers
HOST=0.0.0.0 PORT=8080 WORKERS=8 ./start.sh  # custom bind address / port / worker count
```

> - `PHP_CLI_SERVER_WORKERS` is supported by the PHP built-in server (macOS / Linux); on Windows it has no effect and falls back to a single process. In single-process mode, other admin requests from the same browser queue up while a batch icon fetch runs.
> - Do not bypass `start.sh` and run `php -S 127.0.0.1:8000 -t .` directly — `-t` mode bypasses `router.php` and serves the database under `data/` as a static file.
> - SQLite runs in WAL mode with `busy_timeout=60000ms`, so concurrent reads and writes across multiple workers do not raise `SQLITE_BUSY`.
> - The application calls `session_write_close()` before and after long-running fetches (`release_session_write()`), so the same browser can still open other admin pages during a fetch.

---

## Accounts and Roles

| Item | Value |
| ---- | ------------------------------------------------------------ |
| Login URL | <http://127.0.0.1:8000/admin/login.php> |
| Default account | **None** — created by the installation wizard `admin/install.php` |
| Default password | **None** — the system stores no plaintext password and offers no recovery |
| Password change | Admin "Change password" page `admin/password.php`; changing it does not touch categories / links / statistics |
| Password length | `ADMIN_PASSWORD_MIN_LENGTH` (8), consistent across installation wizard, password change, demo reset and registration |

Roles:

| Role | Permissions | Source |
| -------- | ------------------------- | ------------ |
| `owner` | Everything (site settings, demo reset, password vault, links and categories) | First site owner created by the installation wizard |
| `editor` | Links and categories only | Accounts created via the registration page |

The `admin_users` table in a fresh database is empty, so the first administrator can only be created by the installation wizard, which becomes invalid immediately afterwards (further visits redirect to the login page).
Authentication always relies on the database; `config.php` **stores no password**:

```php
$admin_config = [
    'username' => 'admin',   // only pre-fills the username field in the installation wizard
    'session_name' => 'company_nav_admin_session',
];
```

Sites upgraded from an older version: existing accounts are automatically flagged "password change required" and see a reminder banner at the top of the admin panel after logging in; it disappears once the password is changed.
This is because a password hash cannot reveal whether it is still the old default plaintext password, so one extra reminder is preferable.

---

## Common Configuration (`config.php`)

| Setting | Description |
| --------------------------------- | --------------------------------------- |
| `$config['site_name']` | Site name (title / hero section) |
| `$config['logo_text']` | Brand-area text in the top bar |
| `$config['site_slogan']` | Subtitle / welcome line |
| `$config['search_placeholder']` | Placeholder of the aggregated search box (`{engine}` is replaced by the current engine name) |
| `$config['site_footer']` | Footer copyright information |
| `$config['click_dedup_window']` | Minimum interval in seconds before the same client counts the same link again; `0` disables de-duplication |
| `$config['click_max_links_per_hour']` | Maximum number of distinct links a single client may count per hour; `0` means unlimited |
| `$config['csp_mode']` | `'off'` / `'report'` / `'enforce'` (current value) |
| `$admin_config` | Username prefill for the installation wizard and the session name (**no password**) |
| `ADMIN_PASSWORD_MIN_LENGTH` | Minimum administrator password length, shared by the installation wizard, password change and demo reset |
| `$db_config['file']` | SQLite database path |
| `LOGIN_FAIL_LIMIT` and other constants | Failure threshold and lock duration for login and CAPTCHA |

> Site copy (name, slogan, footer) and the search engine list can be overridden in admin "Site Settings"; values in the database take precedence over the `$config` defaults.

---

## Self-Tests

```bash
./tests/run.sh           # syntax check + all tests (12 PHP groups + 1 JS group)
./tests/run.sh --quick   # skip the syntax check and run the tests only
```

| Test | Coverage |
| ------------------------- | ------------------------------------------------------------------------------------------------------------- |
| `structure.php` | Config split, `data/` protection rules, CSP preconditions, default passwords must be completely gone |
| `click_throttle.php` | Click de-duplication, hourly budget, per-client isolation |
| `admin_logic.php` | Admin CRUD and cascading delete, credential verification, role separation, full installation wizard flow, login / registration rate limiting |
| `admin_paging.php` | Page return in link management: page boundaries, in-category ranking, redirect after sorting, clamping after deletion, URL context |
| `i18n_smoke.php` | Bilingual en / zh-CN smoke test |
| `i18n_coverage.php` | Translation coverage (`--strict` fails on a single missing entry, preventing Chinese from appearing in the English UI) |
| `icon_security.php` | SSRF protection and image magic-number allowlist |
| `vault.php` | Vault master password / KDF / encryption round trip / tamper detection / idle lock |
| `backup.php` | Snapshot integrity (WAL-safe) and retention limit |
| `http_serve.php` | Starts a real server and issues HTTP requests: `data/` and `.git/` blocking (13 bypass variants plus a reverse control server), security headers, pages still usable under CSP, admin access blocked when logged out, click de-duplication, full installation flow, link / category CRUD while logged in |
| `undefined_calls.php` | Each entry point computes the reachable function set from its own require chain; unreachable calls are reported |
| `language_switch.test.js` | Language switching and dirty-form check |

Isolation principle: every PHP test uses a throw-away temporary database and **never touches `data/company_nav.sqlite`**; the HTTP end-to-end test copies the whole project to a temporary directory and starts a separate server, so it never touches the real database or real backups either.

> When writing a new assertion, pair it with a negative check: break the source → confirm the test turns red → revert → explicitly re-read the line you changed.

You can also run only the bilingual tests:

```bash
php tests/i18n_smoke.php en
php tests/i18n_smoke.php zh-CN
```

### Continuous Integration

`.github/workflows/tests.yml` runs `./tests/run.sh` on every push / PR:

- PHP version matrix **8.0 / 8.2 / 8.4** (local development runs 8.0.30; spanning three versions prevents accidental use of newer syntax)
- An additional job reruns the suite with Node removed from `PATH`, verifying that Node is only an optional dependency (missing Node should skip the JS tests, not fail)

> Determine the environment with `PDO::getAvailableDrivers()` containing `sqlite`, **not** with `extension_loaded('pdo_sqlite')`
> — some PHP builds compile the SQLite driver in statically, and the latter returns `false`, producing a false alarm (that is the case on this machine).

---

## Deployment

See [`docs/deploy.md`](docs/deploy.md): nginx / Apache configuration samples, `data/` blocking, directory permission table, WAL backup caveats, and a go-live checklist.

> Besides direct paths, the checklist also covers a set of **bypass variants** (`/./data/…`, `/assets/../data/…`, `/%2e/data/…`) — testing direct access alone is not enough: if the blocking rule matches only the raw URI without path normalisation, these variants can still download the database. In the PHP built-in server case, the path-flattening logic in `router.php` covers this; nginx normalises the URI before matching the location, so no extra rule is needed.

Directory permissions:

- `data/`: must be readable and writable by the web process and **must not be externally accessible** (guarded by `router.php` when running on the PHP built-in server);
- `assets/icons/`: icon output directory, must be writable by the web process;
- No hard-coded absolute paths anywhere; everything is resolved relative to the project root.

---

## Notes

- Resetting demo data: log in to the admin panel → "♻️ Reset demo" → confirm twice to restore the initial state. Before resetting, a consistent snapshot is produced with SQLite's `VACUUM INTO` and stored in `data/backups/` (copying the file directly in WAL mode can miss committed transactions still in `-wal`); the ten most recent `before-*.sqlite` snapshots are kept (`BACKUP_KEEP_MAX`) and only files it created itself are cleaned up;
- The theme preference is recorded in the `theme` cookie and does not conflict with the browser's system dark mode;
- Batch icon refresh has dead-lock protection: a 15 s timeout per request, a 90 s overall limit per batch, at most one retry per item, and the progress bar always finishes and reports statistics;
- Demo links all point to public platform entry points and contain no intranet addresses or private data; the initial click count is 0 and accumulates with real visits;
- The vault starts empty and requires setting a master password on first entry;
- There are no external dependencies and the site runs offline (icon fetching excepted).
