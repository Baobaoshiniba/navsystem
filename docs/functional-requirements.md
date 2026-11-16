# Functional Requirements Specification / 功能性需求规格说明

**Project / 项目:** Team Resource Hub — group navigation portal（小组项目导航门户）
**System under test / 被测系统:** PHP 8 + PDO SQLite web application, no external runtime dependencies
**Document version / 版本:** 1.0 · 2026-11-06
**Purpose / 用途:** Test basis for TestLink requirement traceability and test case design（TestLink 需求追溯与用例设计的依据）

---

## 1. Scope and Assumptions / 范围与前提

| English | 中文 |
|---|---|
| The system has two roles: `owner` (full access) and `editor` (links and categories only). Test cases are designed for both roles where a permission boundary exists. | 系统有两种角色：`owner`（全权限）与 `editor`（仅链接与分类）。凡存在权限边界处，用例需覆盖两种角色。 |
| The demo environment provides one shared account (`admin` / `admin123.`); a freshly installed instance has no default account and requires the installation wizard. | 演示环境提供共享账号（`admin` / `admin123.`）；全新部署实例无默认账号，需走安装向导创建。 |
| Destructive operations (reset, password change, vault master password) affect shared state and must be executed on a private copy of the database. | 破坏性操作（重置、改密、保险箱主密码）会影响共享状态，必须在数据库私有副本上执行。 |
| All test data must be fictitious. No real credentials or personal data may be entered. | 所有测试数据须为虚构内容，不得录入真实凭据或个人资料。 |

---

## 2. Requirement Index / 需求索引

| Suite（TestLink 测试套件） | Requirement IDs | Count |
|---|---|---|
| Front-end Navigation / 前台导航 | FR-01 – FR-10 | 10 |
| Authentication & Accounts / 认证与账号 | FR-11 – FR-17 | 7 |
| Link Management / 链接管理 | FR-18 – FR-23 | 6 |
| Category Management / 分类管理 | FR-24 – FR-26 | 3 |
| Statistics / 统计 | FR-27 – FR-28 | 2 |
| Password Vault / 密码保险箱 | FR-29 – FR-32 | 4 |
| Settings & Maintenance / 设置与维护 | FR-33 – FR-36 | 4 |
| **Total / 合计** | | **36** |

---

## 3. Front-end Navigation / 前台导航（FR-01 – FR-10）

| ID | Requirement (English) | 需求描述（中文） | Key test points / 关键测试点 |
|---|---|---|---|
| FR-01 | The home page displays categories and resource cards grouped by category, ordered by `sort_order` ascending, showing only records where `is_active = 1`. | 首页按 `sort_order` 升序分组展示分类与链接卡片，仅显示 `is_active = 1` 的条目。 | Active vs. inactive item; empty category.／启用与停用条目；空分类。 |
| FR-02 | Selecting a category tag filters the grid to that category only; selecting "All" restores the full list. | 点击分类标签仅显示该分类链接；选择「全部」恢复完整列表。 | Filter → reset; category with no links.／筛选后恢复；无链接分类。 |
| FR-03 | Typing in the filter box filters cards instantly by name, keyword, description or URL, and shows an empty-state message when nothing matches. | 在过滤框输入关键词即时筛选卡片，匹配名称、关键词、描述或 URL；无匹配时显示空状态提示。 | Case insensitivity; special characters; empty input.／大小写；特殊字符；空输入。 |
| FR-04 | The aggregated search bar submits the keyword to the currently selected engine, replacing the `{q}` placeholder with a URL-encoded query. | 聚合搜索栏将关键词提交至当前选中的搜索引擎，`{q}` 占位符替换为 URL 编码后的查询串。 | Engine switching; encoding of spaces and non-ASCII.／引擎切换；空格与中文编码。 |
| FR-05 | The daily quote is fetched per interface language and cached per day; when the network fails, a local bilingual fallback is displayed. | 每日一言按界面语言抓取并按日缓存；抓取失败时显示本地双语兜底内容。 | API failure; cache expiry; both languages.／接口失败；缓存过期；中英两种语言。 |
| FR-06 | The user can switch between light and dark themes; the choice is stored in a cookie for 365 days, with system preference as fallback. | 用户可切换深色/浅色主题；选择存入 cookie 保留 365 天，无 cookie 时跟随系统偏好。 | First visit; cleared cookie; refresh persistence.／首次访问；清除 cookie；刷新后保持。 |
| FR-07 | The interface language can be switched; priority is `?lang=` parameter → `nav_lang` cookie → default `zh-CN`. | 界面语言可切换，优先级为 `?lang=` 参数 → `nav_lang` cookie → 默认 `zh-CN`。 | Invalid `lang` value; persistence across pages.／非法 lang 取值；跨页保持。 |
| FR-08 | Clicking a resource card reports a click count without a page reload, via a 1×1 GIF endpoint compatible with `sendBeacon` and `Image`. | 点击资源卡片时无刷新上报点击计数，接口返回 1×1 GIF，兼容 `sendBeacon` 与 `Image` 两种方式。 | Network failure fails silently; response code 204.／断网时静默失败；响应码 204。 |
| FR-09 | The layout is responsive: multi-column grid on desktop, two-column or single-column on mobile, and the admin sidebar becomes horizontally scrollable on small screens. | 布局响应式：桌面多列网格，手机双列或单列，后台侧栏在小屏转为横向滚动导航。 | Breakpoint widths; no horizontal overflow.／断点宽度；无横向溢出。 |
| FR-10 | The footer displays the contact e-mail address configured in site settings; the row is hidden when the setting is empty. | 页脚展示站点设置中的联系邮箱；该设置为空时不显示此行。 | Configured vs. empty value.／已配置与留空。 |

---

## 4. Authentication & Accounts / 认证与账号（FR-11 – FR-17）

| ID | Requirement (English) | 需求描述（中文） | Key test points / 关键测试点 |
|---|---|---|---|
| FR-11 | On an empty database, the first visit to the admin area is redirected to the installation wizard, which creates the first `owner` account; the wizard becomes inaccessible afterwards. | 空库首次访问后台会进入安装向导，由部署者创建首位 `owner`；创建完成后该页面立即失效。 | Second visit redirects to login; remote install requires the install key.／二次访问跳登录；远程安装需安装密钥。 |
| FR-12 | Login requires username, password and a graphical CAPTCHA; a successful login regenerates the session ID. | 登录需账号、密码与图形验证码；登录成功后重新生成 Session ID。 | Wrong / expired CAPTCHA; wrong password; success path.／验证码错误或过期；密码错误；成功路径。 |
| FR-13 | Failed logins are rate-limited on two independent counters (per account and per IP), with exponential back-off once the threshold is reached; CAPTCHA attempts are counted separately. | 登录失败按账号与 IP 两个维度独立计数限流，达阈值后指数退避锁定；验证码尝试单独计数。 | Threshold boundary; lock duration; unlock after expiry.／阈值边界；锁定时长；解锁后恢复。 |
| FR-14 | Self-registration can only be submitted when the owner has enabled `register_enabled`; it requires a CAPTCHA and always creates an `editor` account. | 仅当 owner 开启 `register_enabled` 时注册页可提交；需图形验证码，新账号固定为 `editor`。 | Switch off → submission rejected; rate limit per IP.／开关关闭时提交被拒；按 IP 限流。 |
| FR-15 | The password change page verifies the current password, enforces the minimum length of 8 characters, requires matching confirmation, and does not modify any business data. | 修改密码页校验当前密码，强制最小长度 8 位，两次输入须一致，且不改动任何业务数据。 | 7 vs. 8 characters; mismatched confirmation; data unchanged after change.／7 与 8 位边界；确认不一致；改密后业务数据不变。 |
| FR-16 | Logging out destroys the session, and any subsequent admin request is redirected to the login page. | 退出登录销毁会话，之后任何后台请求均重定向至登录页。 | Direct URL access after logout.／退出后直接访问后台 URL。 |
| FR-17 | Role separation is enforced server-side: an `editor` cannot open site settings, demo reset or the password vault, which are `owner`-only. | 角色隔离在服务端强制：`editor` 无法进入站点设置、重置演示与密码保险箱，这三处仅 `owner` 可用。 | Direct URL entry as editor; hidden vs. blocked.／以 editor 直接输入 URL；隐藏与拦截的差别。 |

---

## 5. Link Management / 链接管理（FR-18 – FR-23）

| ID | Requirement (English) | 需求描述（中文） | Key test points / 关键测试点 |
|---|---|---|---|
| FR-18 | Creating a link requires title, URL and category; the category must exist; after saving, the link appears in the list and on the front end. | 新增链接需填写标题、URL 与分类，分类必须存在；保存后列表与前台均可见。 | Empty title / empty URL / non-existent category.／空标题；空 URL；分类不存在。 |
| FR-19 | Editing a link allows changes to the Chinese and English title and description, URL, icon, category and sort order. | 编辑链接可修改中英文标题与描述、URL、图标、分类与排序值。 | English left empty falls back to Chinese.／英文留空回退中文。 |
| FR-20 | Deleting a link removes it from the list, the front end and the statistics. | 删除链接后，列表、前台与统计中均不再出现。 | Cascading effect on statistics.／对统计的影响。 |
| FR-21 | Links can be moved up, moved down or pinned to the top; after the operation the list returns to the page containing the record. | 链接可上移、下移或置顶；操作后列表回跳至该记录所在页。 | First item up; last item down; cross-page move.／首条上移；末条下移；跨页移动。 |
| FR-22 | A link can be enabled or disabled; a disabled link is hidden on the front end and no longer receives click counts. | 链接可启用或停用；停用后前台不展示，且不再累计点击次数。 | Clicking a stale card after disabling.／停用后点击旧卡片。 |
| FR-23 | The link list is paged server-side at 8 records per page; when a deletion empties the current page, the page number is clamped to the last valid page. | 链接列表服务端分页每页 8 条；删除导致当前页为空时，页码自动回退至最后一个有效页。 | 8th / 9th record boundary; delete on last page.／第 8 与第 9 条边界；末页删除。 |

---

## 6. Category Management / 分类管理（FR-24 – FR-26）

| ID | Requirement (English) | 需求描述（中文） | Key test points / 关键测试点 |
|---|---|---|---|
| FR-24 | A category can be created or edited with a mandatory name plus optional icon, sort order and English name. | 可新增或编辑分类：名称必填，可设图标、排序值与英文名。 | Empty name; whitespace-only name.／空名称；仅空格。 |
| FR-25 | Deleting a category also deletes all links under it and reports how many were removed. | 删除分类时级联删除其下全部链接，并提示删除条数。 | Category with links vs. empty category.／有链接与无链接。 |
| FR-26 | Categories can be reordered (up / down / pin to top) and enabled or disabled; ordering is realised by swapping `sort_order`. | 分类可上移、下移、置顶以及启用或停用；排序通过交换 `sort_order` 实现。 | Pin to top ordering; disabled category hidden on front end.／置顶后顺序；停用后前台隐藏。 |

---

## 7. Statistics / 统计（FR-27 – FR-28）

| ID | Requirement (English) | 需求描述（中文） | Key test points / 关键测试点 |
|---|---|---|---|
| FR-27 | The statistics page shows the total click count, the click distribution per category and a ranked list of the most clicked links. | 统计页展示总点击量、各分类点击分布与链接点击排行。 | Zero-click state; ranking order.／零点击状态；排行顺序。 |
| FR-28 | Click counting is de-duplicated: the same client and link are counted once per 24-hour window, and at most 200 distinct links per hour per client. | 点击计数去重：同一客户端对同一链接 24 小时内只计一次，且每小时最多计 200 个不同链接。 | Window boundary; budget exceeded returns no increment.／窗口边界；超预算不计数。 |

---

## 8. Password Vault / 密码保险箱（FR-29 – FR-32）

| ID | Requirement (English) | 需求描述（中文） | Key test points / 关键测试点 |
|---|---|---|---|
| FR-29 | The vault is unlocked with a master password; the master password is stored only as a hash, and the encryption key exists only in the PHP session. | 保险箱用主密码解锁；主密码仅以哈希存储，加密密钥只存在于 PHP 会话中。 | Wrong master password repeated; new master password setup.／多次错误主密码；首次设置主密码。 |
| FR-30 | Vault entries support create, read, update and delete; passwords are encrypted at rest with a unique random IV per entry, masked by default and revealed on demand. | 保险箱条目支持增删改查；密码以每条独立随机 IV 加密存储，默认打码，按需揭示。 | Reveal then hide again; reveal endpoint requires POST and CSRF.／揭示后再隐藏；揭示接口需 POST 与 CSRF。 |
| FR-31 | Vault entries can be searched by keyword and filtered by category, and the filter affects only matching entries. | 保险箱条目可按关键词搜索并按分类筛选，筛选仅作用于匹配条目。 | Keyword with no result; filter reset.／无结果关键词；清除筛选。 |
| FR-32 | The vault locks manually on demand and automatically after 15 minutes of inactivity; after locking, the master password is required again. | 保险箱可手动锁定，并在 15 分钟无操作后自动锁定；锁定后需重新输入主密码。 | 14:59 / 15:01 boundary; lock during an open entry.／14:59 与 15:01 边界；条目打开期间锁定。 |

---

## 9. Settings & Maintenance / 设置与维护（FR-33 – FR-36）

| ID | Requirement (English) | 需求描述（中文） | Key test points / 关键测试点 |
|---|---|---|---|
| FR-33 | Site texts (site name, logo text, slogan, search placeholder, footer, contact e-mail) are saved bilingually and take effect immediately; an empty field falls back to the system default. | 站点文案（名称、Logo 文字、副标题、搜索提示、页脚、联系邮箱）双语保存并即时生效；留空字段回退系统默认值。 | All fields empty; English filled, Chinese empty.／全部留空；仅填英文。 |
| FR-34 | The search engine list is parsed line by line as `name|URL template`; invalid or duplicate lines are ignored and the number of ignored lines is reported; the default engine must exist in the list. | 搜索引擎清单按「名称\|URL 模板」逐行解析；非法或重复行被忽略并提示忽略条数；默认引擎必须存在于清单中。 | Missing `{q}`; non http(s) URL; duplicate name.／缺 `{q}`；非 http(s)；重名。 |
| FR-35 | Demo reset offers three modes (reset links, clear to blank, restore full demo); restoring takes an automatic backup first and only the ten most recent `before-*` snapshots are kept. | 重置演示提供三种模式（重置链接、清空为空白、恢复完整 Demo）；恢复前自动备份，仅保留最近 10 份 `before-*` 快照。 | Wrong confirmation phrase; backup failure blocks reset.／确认短语错误；备份失败时阻止重置。 |
| FR-36 | Site icons are fetched on demand (single refresh / cursor-based batch refresh) with a four-level fallback chain, and requests to internal, loopback or metadata addresses are rejected. | 站点图标按需抓取（单条刷新 / 游标分批），采用四级兜底链；对内网、回环与云元数据地址的请求一律拒绝。 | Request timeout; non-image payload; internal address.／请求超时；非图片内容；内网地址。 |

---

## 10. Traceability Notes / 追溯说明

| English | 中文 |
|---|---|
| Requirement IDs above map one-to-one to TestLink requirements. Each requirement must be linked to at least one test case; coverage is read from the requirement coverage report. | 上述需求编号与 TestLink 需求一一对应。每条需求至少关联一条测试用例，覆盖率从需求覆盖报告读取。 |
| Requirements FR-01 – FR-10 and FR-18 – FR-28 are black-box testable from the browser. FR-11 – FR-17 and FR-29 – FR-36 additionally require role switching or direct URL access. | FR-01 – FR-10、FR-18 – FR-28 可在浏览器内以黑盒方式测试；FR-11 – FR-17、FR-29 – FR-36 还需切换角色或直接访问 URL。 |
| Non-functional aspects (performance, security) are out of scope of this document and are covered separately by the JMeter and Semgrep sessions. | 非功能方面（性能、安全）不在本文档范围内，由 JMeter 与 Semgrep 两节课单独覆盖。 |
