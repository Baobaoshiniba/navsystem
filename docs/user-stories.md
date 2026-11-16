# User Stories — Team Resource Hub

**System under test:** PHP 8 + PDO SQLite navigation portal（小组项目导航门户）
**Version:** 1.0 · 2026-11-06 · Prepared for the progress check on Sunday, 15 November 2026
**Related documents:** [Functional Requirements](functional-requirements.md) · [中文需求说明](functional-requirements.md)

Each user story below is written in the standard format *As a …, I want …, so that …*, followed by rule-oriented acceptance criteria. The `Covers` column links every story to the functional requirement IDs (FR-xx) defined in the Functional Requirements Specification, so that TestLink requirements can be traced from story → AC → test case.

---

## Actors / 角色

| Actor | Description | 描述 |
|---|---|---|
| **Visitor** | Anonymous user browsing the portal without logging in | 未登录的匿名访客 |
| **Editor** | Authenticated user who may maintain links and categories only | 已登录、仅可维护链接与分类的用户 |
| **Owner** | First administrator created by the installation wizard; full access | 安装向导创建的第一位站长，拥有全部权限 |

---

## 1. Visitor Stories / 访客故事

### US-01 — Browse the resource grid（浏览资源网格）
**As a** visitor, **I want** to see all shared resources grouped by category on the home page, **so that** I can quickly find the tools my team uses.
**Covers:** FR-01, FR-02 · **Priority:** High

**Acceptance criteria:**
1. Cards are grouped by category and ordered by `sort_order` ascending.
2. Only records with `is_active = 1` are displayed.
3. Selecting a category tag shows only that category; selecting "All" restores the full list.
4. A category with no active links shows an empty state without errors.

### US-02 — Filter resources by keyword（关键词即时筛选）
**As a** visitor, **I want** to filter resource cards instantly by typing a keyword, **so that** I can locate a specific tool without browsing every category.
**Covers:** FR-03 · **Priority:** High

**Acceptance criteria:**
1. The filter matches name, keyword, description and URL.
2. Matching is case-insensitive.
3. When nothing matches, an empty-state message is shown.
4. Clearing the input restores the full grid.

### US-03 — Search with an external engine（聚合搜索）
**As a** visitor, **I want** to submit my query to a selectable external search engine, **so that** I can search the web without leaving the portal.
**Covers:** FR-04 · **Priority:** Medium

**Acceptance criteria:**
1. The engine dropdown lists the engines configured in site settings.
2. Submitting replaces the `{q}` placeholder with the URL-encoded keyword.
3. Spaces and non-ASCII characters are encoded correctly.
4. The default engine preselected is the one configured by the owner.

### US-04 — See a daily quote（每日一言）
**As a** visitor, **I want** to see a daily quote on the home page, **so that** the portal feels alive each day.
**Covers:** FR-05 · **Priority:** Low

**Acceptance criteria:**
1. The quote matches the interface language (separate sources for zh / en).
2. The quote is fetched at most once per day (daily cache).
3. When the upstream API fails, a local bilingual fallback is displayed and no error breaks the page.

### US-05 — Switch colour theme（主题切换）
**As a** visitor, **I want** to switch between light and dark themes, **so that** I can read comfortably in my environment.
**Covers:** FR-06 · **Priority:** Medium

**Acceptance criteria:**
1. Toggling the theme switches immediately without a reload.
2. The choice persists for 365 days via cookie.
3. With no cookie present, the system preference is honoured.

### US-06 — Switch interface language（语言切换）
**As a** visitor, **I want** to switch the interface between Chinese and English, **so that** I can use the portal in my preferred language.
**Covers:** FR-07 · **Priority:** High

**Acceptance criteria:**
1. Language priority is `?lang=` → `nav_lang` cookie → default `zh-CN`.
2. An invalid `lang` value falls back to the default.
3. User-entered content falls back field-by-field when the English value is empty.
4. The choice persists across pages and visits.

### US-07 — Count resource clicks（点击计数）
**As a** visitor, **I want** my clicks on a resource to be counted silently, **so that** the team can see which tools are popular.
**Covers:** FR-08, FR-27, FR-28 · **Priority:** Medium

**Acceptance criteria:**
1. Clicking a card reports the count without a page reload (1×1 GIF, HTTP 204).
2. The same client counts the same link once per 24-hour window.
3. One client counts at most 200 distinct links per hour.
4. Network failure does not affect page behaviour (silent fail).

### US-08 — Use the portal on mobile（响应式布局）
**As a** visitor, **I want** the layout to adapt to my screen, **so that** the portal is usable on my phone.
**Covers:** FR-09 · **Priority:** Medium

**Acceptance criteria:**
1. Desktop shows a multi-column grid; mobile shows two columns or one.
2. The admin sidebar becomes horizontally scrollable on small screens.
3. No horizontal page overflow at common breakpoints.

### US-09 — See the contact address（联系邮箱）
**As a** visitor, **I want** to see the team's contact e-mail in the footer, **so that** I know how to reach the owners.
**Covers:** FR-10 · **Priority:** Low

**Acceptance criteria:**
1. The footer shows the configured contact e-mail.
2. When the setting is empty, the footer row is hidden entirely.

---

## 2. Editor Stories / 编辑故事

### US-10 — Log in and log out（登录与退出）
**As an** editor, **I want** to log in with my account, password and a CAPTCHA, and log out safely, **so that** only team members can modify shared data.
**Covers:** FR-12, FR-13, FR-16 · **Priority:** High

**Acceptance criteria:**
1. Login requires username, password and a valid CAPTCHA; any wrong field rejects the attempt with a clear message.
2. Successful login regenerates the session ID.
3. Failed attempts are rate-limited per account and per IP with exponential back-off; CAPTCHA attempts are counted separately.
4. Logout destroys the session; any admin URL is then redirected to the login page.

### US-11 — Manage links（链接管理）
**As an** editor, **I want** to create, edit, delete, reorder, pin, enable and disable links, **so that** the portal always reflects the team's current toolset.
**Covers:** FR-18 – FR-23 · **Priority:** High

**Acceptance criteria:**
1. Creating requires title, URL and an existing category; validation errors are listed together.
2. Editing supports Chinese and English title/description, URL, icon, category and sort order; an empty English field falls back to Chinese.
3. Links can be moved up, moved down or pinned; after sorting, the list returns to the page containing the record.
4. Disabled links are hidden on the front end and stop counting clicks.
5. The list is paged at 8 records; deleting the last record of a page clamps the page number.
6. Deleting a link removes it from list, front end and statistics.

### US-12 — Manage categories（分类管理）
**As an** editor, **I want** to create, edit, reorder, enable and delete categories, **so that** the resource grid stays organised as the team grows.
**Covers:** FR-24 – FR-26 · **Priority:** High

**Acceptance criteria:**
1. A category requires a non-empty name; icon, sort order and English name are optional.
2. Deleting a category cascades to all its links and reports the number removed.
3. Categories can be moved up/down/pinned via `sort_order` swaps.
4. A disabled category is hidden on the front end but preserved in the admin list.

### US-13 — Change my own password（修改密码）
**As an** editor, **I want** to change my password after verifying the current one, **so that** I can keep my account secure.
**Covers:** FR-15 · **Priority:** Medium

**Acceptance criteria:**
1. The current password must be verified first.
2. The new password is at least 8 characters and both fields match.
3. Changing the password does not modify any business data.
4. Failed verification counts towards login rate limiting.

### US-14 — View click statistics（查看统计）
**As an** editor, **I want** to see total clicks, per-category distribution and top links, **so that** I can judge which resources matter to the team.
**Covers:** FR-27 · **Priority:** Low

**Acceptance criteria:**
1. The page shows total clicks, category distribution and a ranked link list.
2. A zero-click state renders gracefully.
3. Rankings update after new clicks are counted.

---

## 3. Owner Stories / 站长故事

### US-15 — Set up the first account（安装向导）
**As an** owner installing the system, **I want** a wizard that creates the first administrator on a fresh database, **so that** no default credentials ever exist.
**Covers:** FR-11 · **Priority:** High

**Acceptance criteria:**
1. An empty database redirects the first admin visit to the installation wizard.
2. The created account is `owner`; the password is at least 8 characters.
3. After setup, the wizard is inaccessible (redirects to login).
4. Non-local access to the wizard requires the install key from `data/.install_key`.

### US-16 — Configure site settings（站点设置）
**As an** owner, **I want** to edit bilingual site copy, the contact e-mail, the search engine list and the registration switch, **so that** the portal matches our team without touching code.
**Covers:** FR-33, FR-34, FR-10 · **Priority:** High

**Acceptance criteria:**
1. Text fields are saved bilingually and take effect immediately; empty fields fall back to system defaults.
2. Search engine lines are parsed as `name|URL template`; invalid or duplicate lines are ignored and the count of ignored lines is reported.
3. The default engine must be one of the engines in the list.
4. The registration switch takes effect on the login/registration pages immediately.

### US-17 — Enable self-registration（开放注册）
**As an** owner, **I want** to allow teammates to register their own editor accounts, **so that** I do not have to create accounts manually.
**Covers:** FR-14 · **Priority:** Medium

**Acceptance criteria:**
1. When registration is enabled, the login page shows the sign-up entry and the form requires a CAPTCHA.
2. Every registered account is `editor`, never `owner`.
3. Successful registrations also count towards the per-IP rate limit.
4. When registration is disabled, the form cannot be submitted at all.

### US-18 — Use the password vault（密码保险箱）
**As an** owner, **I want** to store shared credentials encrypted behind a master password, **so that** sensitive logins are not kept in plain chat history.
**Covers:** FR-29 – FR-32 · **Priority:** High

**Acceptance criteria:**
1. The vault unlocks only with the correct master password; the master password is stored as a hash only.
2. Entries support create, read, update, delete; each password is encrypted with its own random IV and masked by default.
3. Reveal-on-demand requires a POST with CSRF token; hide-after-reveal works.
4. Entries can be searched and filtered by category.
5. The vault locks manually on demand and automatically after 15 minutes idle; a locked vault requires the master password again.

### US-19 — Reset the demo environment（重置演示）
**As an** owner, **I want** to restore the demo environment to a known state, **so that** the next tester starts from clean, documented data.
**Covers:** FR-35 · **Priority:** Medium

**Acceptance criteria:**
1. Three modes are offered: reset links, clear to blank, restore full demo.
2. Restoring takes an automatic snapshot first and only the ten most recent `before-*` snapshots are retained.
3. The confirmation phrase must be typed exactly; a wrong phrase blocks the reset.
4. Restoring recreates the public account, demo links and clears clicks and vault entries, while site copy, theme and search settings are preserved.

### US-20 — Trust the role boundary（角色隔离）
**As an** owner, **I want** editor accounts to be blocked from owner-only pages even by direct URL, **so that** privileges cannot be bypassed.
**Covers:** FR-17 · **Priority:** High

**Acceptance criteria:**
1. An editor requesting `settings.php`, `reset.php` or `vault.php` directly is denied server-side.
2. Denied access does not leak page content.
3. Owner-only entries are hidden from the editor's navigation.

### US-21 — Refresh site icons（图标抓取）
**As an** owner, **I want** icons fetched automatically for new links, **so that** the grid looks consistent without manual artwork.
**Covers:** FR-36 · **Priority:** Low

**Acceptance criteria:**
1. A single link can be re-fetched; batch refresh runs cursor-based and reports per-item statistics.
2. The fallback chain is cache → source site → third-party service → initial-letter badge.
3. Requests to internal, loopback or cloud-metadata addresses are rejected.
4. Timeouts and non-image payloads end with a graceful message, never a hang.

---

## 4. Traceability Summary / 追溯总览

| User Story | Covers | Suite in TestLink |
|---|---|---|
| US-01 – US-09 | FR-01 – FR-10 | Front-end Navigation |
| US-10 – US-14 | FR-12 – FR-16, FR-18 – FR-27 | Authentication · Link Management · Category Management · Statistics |
| US-15 – US-21 | FR-11, FR-14, FR-17, FR-29 – FR-36 | Settings & Maintenance · Password Vault |

**Coverage check / 覆盖检查:** all 36 functional requirements are covered by at least one user story; every acceptance criterion is a candidate test condition for ATDD-derived test cases.
