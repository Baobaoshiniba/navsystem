# 小组项目导航（Team Resource Hub）

面向小组作业的双语言资源导航门户：原生 PHP 8 + PDO SQLite，无 Composer、无框架、无外部运行时依赖，整目录拷贝即可迁移。

- 中 / 英双语言界面（前台、登录、注册、后台全覆盖）
- 深浅色双主题，cookie 记住选择
- 分类分组导航 + 关键词即时过滤 + 多引擎聚合搜索
- 点击统计（带去重与限流）
- 独立管理后台：链接 / 分类 / 站点设置 / 统计 / 重置 / 密码保险箱
- 两级角色（owner / editor）、登录限流、验证码、CSP

---

## 快速开始

```bash
./start.sh                    # 默认 http://127.0.0.1:8000
```

| 页面 | 地址 |
|---|---|
| 前台导航页 | http://127.0.0.1:8000/index.php |
| 管理后台 | http://127.0.0.1:8000/admin/login.php |

**没有默认账号。** 首次访问管理后台会被引导到安装向导 `admin/install.php`，当场设置第一位站长的用户名与密码；首次访问任意页面会自动创建 `data/company_nav.sqlite` 并写入演示数据（4 个分类、12 条链接）。

> 本项目是小组作业 Demo。若要在公网部署，**务必先读完 [安全边界](#安全边界) 一节**——默认配置对公网是不安全的。

---

## 功能特性

### 前台（`index.php`）

- 简洁资源工作台布局：品牌栏 → 介绍区 → 聚合搜索 → 分类筛选 → 资源网格
- **聚合搜索**：可切换多个搜索引擎（后台「站点设置」配置清单与默认引擎），回车跳转到所选引擎
- 关键词即时过滤：按名称 / 关键词 / 描述 / URL 匹配，附分类快捷标签
- 按分类分组展示卡片：emoji 或本地缓存的站点图标、描述、点击次数
- 深色 / 浅色主题一键切换（cookie 记忆 365 天 + 系统偏好兜底）
- 点击卡片无刷新上报计数（`track_click.php`）
- 响应式：桌面多列网格，手机双列 / 单列自适应，后台侧栏在手机端变为横向滚动导航

### 管理后台（`admin/`）

| 模块 | 说明 | 所需角色 |
|---|---|---|
| `index.php` | 链接管理：新增 / 编辑 / 删除 / 排序 / 置顶 / 启停；字段含中英文标题与描述、URL、图标、所属分类 | editor |
| `categories.php` | 分类管理：新增 / 编辑 / 删除 / 上下移排序 / 启停 | editor |
| `stats.php` | 点击统计：汇总卡片、分类点击分布、链接 TOP 排行 | editor |
| `vault.php` | 密码保险箱：AES-256-GCM 加密的凭据库，主密码解锁、按需揭示、15 分钟空闲自动锁定 | owner |
| `settings.php` | 站点设置：站点文案中英文、聚合搜索引擎清单与默认引擎、注册开关 | owner |
| `reset.php` | 一键重置演示数据（带二次确认；**改密码请用 `password.php`，不必清空数据**） | owner |
| `password.php` | 修改当前账号密码：先校验当前密码且计入登录限流，不动任何数据 | owner / editor |
| `install.php` | 安装向导：全新库创建首位站长，装好后立即失效（远程访问需安装密钥） | — |
| `register.php` | 新账号注册，默认关闭，由 owner 在站点设置开启；带图形验证码与按 IP 的注册限流 | — |
| `login.php` | 登录页：账号密码 + 图形验证码 | — |
| `captcha.php` | 验证码图片生成（GD 绘图，本地 TTF 字体） | — |
| `icon_refresh.php` | 图标抓取 JSON 接口：单条重抓 / 批量刷新（游标分批） | editor |

**图标抓取**（`lib/favicon.php`）四级优先级：本地缓存命中 → 源站抓取（`/favicon.ico` + HTML `<link rel="icon">`）→ 第三方服务兜底 → 首字母徽章。取回的图片一律落盘 `assets/icons/`，前台只读本地文件。

### 双语言

- 界面文案以**中文源串为 key**（`t('…')`），词条集中在 `lang/en.php`；`zh-CN` 为源语言，无需词条文件
- 用户录入的内容（分类、链接、保险箱条目、站点文案）走**字段级回退**：英文留空自动回退中文（`localized_field()`）
- 语言优先级：`?lang=` → cookie `nav_lang` → 默认 `zh-CN`
- 设计说明见 [`docs/bilingual-design.md`](docs/bilingual-design.md)，验证记录见 [`docs/verification.md`](docs/verification.md)

---

## 安全边界

> **这一节不是可选项。** 本项目把 SQLite 数据库放在 `data/` 目录下，若 Web 服务器把该目录当静态资源提供，任何人都可直接下载数据库文件，从中读出管理员密码哈希。

### 必须做的三件事

| # | 措施 | 实现位置 |
|---|---|---|
| 1 | 本地开发用 `router.php` 托管，不要用 `php -S -t .` | `start.sh` 已改为 `exec php -S … router.php`；`router.php` 对 `/data/` 与 `/.git/` 返回 403 |
| 2 | 正式部署在 Web 服务器层 deny `data/` | nginx / Apache 样例见 [`docs/deploy.md`](docs/deploy.md)；仓库内已附 `data/.htaccess`（Apache 直接生效） |
| 3 | 远程安装时用安装密钥，别让人抢先建号 | 非本机访问 `admin/install.php` 需要输入 `data/.install_key` 里的密钥；本机（127.0.0.1）免验 |

`data/.htaccess` 已通过 `.gitignore` 的 `!data/.htaccess` 例外规则随仓库分发，不会因本地忽略规则丢失。

### 已实现的防护

- **SQL 注入**：全部走 PDO 预处理，禁用模拟预处理（`ATTR_EMULATE_PREPARES = false`）
- **XSS**：所有输出经 `e()` 转义；配合 CSP nonce，内联脚本必须带 `nonce_attr()`，内联事件属性已改为事件委托（`assets/js/ui-wiring.js`）
- **CSRF**：所有 POST 表单带 token，后台统一 `guard_csrf()`
- **会话**：`secure_session_start()` 启用 `httponly`、`samesite=Lax`，HTTPS 下自动加 `secure`；登录成功重生成 Session ID
- **登录限流**：双维度（账号 / IP）独立计数 + 验证码单独计数，达阈值后指数退避锁定（阈值见 `config.php` 顶部常量）
- **注册限流**：注册**成功**也计入同一 IP 的计数（只统计失败挡不住脚本，它每次都能成功），达到 `REGISTER_MAX_PER_WINDOW` 后锁定；注册页同样要求图形验证码
- **无幽灵会话**：每次后台鉴权回查一次账号是否仍在库中，账号被删除 / 改名后旧会话立即失效
- **点击统计防刷**：同一客户端对同一链接在 `click_dedup_window`（默认 24h）内只计一次，且每小时最多计 `click_max_links_per_hour`（默认 200）个不同链接；IP 以哈希存储
- **SSRF**：`lib/favicon.php` 抓取外链时禁止内网 / 回环 / 云元数据地址，校验 DNS 解析结果，关闭 `FOLLOWLOCATION` 后逐跳重校验，图片按魔数白名单校验
- **安全响应头**：`security_headers()` 统一输出 CSP、`X-Content-Type-Options`、`X-Frame-Options`、`Referrer-Policy` 等
- **角色分级**：`owner` 全权限，`editor` 只能维护链接与分类；注册账号一律为 `editor`，由 `require_owner()` 守卫高危页
- **无默认密码**：系统不自带任何管理员账号，首位站长由 `admin/install.php` 当场设置；远程安装需输入 `data/.install_key` 里的密钥，防止他人抢先建号；认证只认数据库，`config.php` 里不存放任何密码
- **保险箱**：主密码只存 Argon2id 哈希，密钥由 PBKDF2-HMAC-SHA256（210k 迭代）派生且**只存在于 PHP 会话**，明文密码 AES-256-GCM 加密、每条独立随机 IV。忘记主密码无法恢复，只能清空保险箱

### CSP 已生效（enforce）

`config.php` 的 `csp_mode` 当前为 **`'enforce'`**：违规脚本会被浏览器直接拦下。

策略为 `default-src 'self'`，脚本走 per-request nonce，另含 `object-src 'none'`、`frame-ancestors 'self'`、`base-uri 'self'`、`form-action 'self'`。

切到 enforce 需要几个前提，这些前提已由 `tests/structure.php` 第 8 节自动守住（改动代码后请跑 `./tests/run.sh`）：

- 内联 `<script>` 必须写成 `<script<?= nonce_attr() ?>>`
- **不能**写内联事件属性（`onclick` / `onsubmit` 等），一律用 `data-*` + 事件委托
- **不能**用 `eval` / `new Function`（策略未开放 `unsafe-eval`）

> 若打开页面后发现主题切换 / 语言切换 / 二次确认弹窗 / 验证码换图失灵，
> 把 `csp_mode` 改回 `'report'` 即可立刻恢复，再排查是哪条指令被拦。

---

## 项目结构

```
navsystem/
├── index.php               # 前台导航首页
├── track_click.php         # 点击计数接口（去重 + 限流后 +1）
├── config.php              # 全局配置 + 基础设施（DB、设置、鉴权、限流、CSP、渲染）
├── router.php              # PHP 内置服务器路由脚本：拦截 data/ 与 .git/
├── start.sh                # 本地启动脚本（多 worker + router.php）
├── README.md
├── lib/                    # 可复用模块
│   ├── i18n.php            # 语言选择（白名单）
│   ├── ui.php              # 24px 线性图标集
│   ├── icon_security.php   # 图片魔数白名单 + SSRF 防护（纯函数，可独立测试）
│   ├── backup.php          # 数据库快照（VACUUM INTO）与保留份数清理
│   └── favicon.php         # 站点图标抓取与本地缓存（调度 icon_security 做校验）
├── lang/
│   ├── zh-CN.php           # 源语言（空实现，t() 直接返回源串）
│   └── en.php              # 英文词条
├── admin/
│   ├── _init.php           # 后台公共逻辑：鉴权 / require_owner / 布局 / flash
│   ├── _vault.php          # 保险箱公共库：密钥派生 / 加解密 / 自动锁定
│   ├── login.php  logout.php  register.php  captcha.php
│   ├── install.php         # 安装向导：全新库创建首位站长（非本机访问需安装密钥）
│   ├── password.php        # 修改密码（不动任何数据；校验当前密码并计入登录限流）
│   ├── index.php           # 链接管理
│   ├── categories.php      # 分类管理
│   ├── settings.php        # 站点设置
│   ├── stats.php           # 点击统计
│   ├── vault.php           # 密码保险箱（页面交互在 assets/js/vault.js，运行时参数经 #vaultConfig 传入）
│   ├── vault_reveal.php    # 保险箱按需揭示接口（POST + CSRF）
│   ├── icon_refresh.php    # 图标抓取接口（单条 / 批量）
│   └── reset.php           # 重置演示数据
├── assets/
│   ├── css/style.css  workspace.css
│   ├── js/theme.js  i18n.js  ui-wiring.js  workspace.js  vault.js
│   ├── brands/             # 内置品牌 SVG（含 LICENSE.md / SOURCES.md）
│   ├── icons/              # 抓取后落盘的站点图标
│   ├── background/         # 深浅色背景图形
│   ├── fonts/captcha.ttf   # 验证码字体
│   └── favicon.svg  favicon-dark.svg
├── data/                   # 运行时数据（不入库，禁止 Web 访问）
│   ├── .htaccess           # Apache 拦截规则（随仓库分发）
│   ├── demo.json           # 中英文演示资源
│   ├── company_nav.sqlite  # 本地自动生成
│   ├── .install_key        # 安装密钥（首次启动自动生成，仅远程安装时需要）
│   └── backups/            # 重置 / 变更前的自动备份
├── docs/
│   ├── deploy.md           # 部署安全配置（nginx / Apache / 目录权限 / 自检清单）
│   ├── bilingual-design.md # 双语言设计
│   ├── verification.md     # 双语言验证记录
│   └── workspace-design.md # 前台视觉与布局设计
└── tests/
    ├── run.sh              # 统一自检入口
    ├── click_throttle.php  # 点击去重与限流
    ├── admin_logic.php     # 后台 CRUD / 级联删除 / 角色分级 / 登录限流 / 设置往返
    ├── vault.php           # 保险箱：主密码 / KDF / AES-GCM 往返 / 篡改检测 / 空闲锁定
    ├── backup.php          # 数据库快照完整性与保留份数上限
    ├── i18n_smoke.php      # 双语言冒烟（en / zh-CN）
    └── language_switch.test.js
```

---

## 本地启动

环境要求：**PHP 8.x**（含 `pdo_sqlite` 扩展），macOS / Linux / Windows 通用。

```bash
./start.sh                                    # 默认 127.0.0.1:8000，4 个 worker
HOST=0.0.0.0 PORT=8080 WORKERS=8 ./start.sh  # 自定义监听地址 / 端口 / worker 数
```

> - `PHP_CLI_SERVER_WORKERS` 由 PHP 内置服务器（macOS / Linux）支持；Windows 下不生效会回退单进程。单进程模式下，后台批量抓取图标期间同一浏览器的其它后台请求会排队等待。
> - 不要绕过 `start.sh` 直接跑 `php -S 127.0.0.1:8000 -t .`——`-t` 模式会绕过 `router.php`，把 `data/` 里的数据库当静态文件发出去。
> - SQLite 已启用 WAL 并设置 `busy_timeout=60000ms`，多 worker 并发读写不会直接抛 `SQLITE_BUSY`。
> - 耗时抓取前后台会 `session_write_close()` 释放 session 写锁（`release_session_write()`），抓取期间同一浏览器仍可打开其它后台页面。

---

## 账号与角色

| 项 | 值 |
|---|---|
| 登录地址 | http://127.0.0.1:8000/admin/login.php |
| 默认账号 | **无** —— 由安装向导 `admin/install.php` 当场创建 |
| 默认密码 | **无** —— 系统不保存任何明文密码，也不提供找回 |
| 改密入口 | 后台「修改密码」页 `admin/password.php`，改密不会动到分类 / 链接 / 统计数据 |
| 密码长度 | `ADMIN_PASSWORD_MIN_LENGTH`（8 位），安装向导 / 改密页 / 重置演示 / 注册四处统一 |

角色：

| 角色 | 权限 | 来源 |
|---|---|---|
| `owner` | 全部（站点设置、重置演示、密码保险箱、链接与分类） | 安装向导创建的第一位站长 |
| `editor` | 仅链接与分类 | 注册页创建的所有账号 |

全新库的 `admin_users` 表是空的，首位管理员只能由安装向导创建，创建后该页立即失效（再访问会跳登录页）。
认证一律以数据库为准，`config.php` 里**不存放任何密码**：

```php
$admin_config = [
    'username' => 'admin',   // 仅作为安装向导里用户名输入框的预填值
    'session_name' => 'company_nav_admin_session',
];
```

从旧版本升级的站点：存量账号会被自动标记为「需要改密」，登录后在后台顶部看到提醒横幅，改一次密码即消失。
这是因为无法从密码哈希反推它是否仍是当年那个明文默认密码，宁可多提醒一次。

---

## 常用配置（`config.php`）

| 配置 | 说明 |
|---|---|
| `$config['site_name']` | 站点名称（标题 / 主视觉） |
| `$config['logo_text']` | 顶栏品牌区文字 |
| `$config['site_slogan']` | 副标题 / 欢迎语 |
| `$config['search_placeholder']` | 聚合搜索框提示语（`{engine}` 替换为当前引擎名） |
| `$config['site_footer']` | 页脚版权信息 |
| `$config['click_dedup_window']` | 同一客户端对同一链接的计数最小间隔（秒），`0` = 关闭去重 |
| `$config['click_max_links_per_hour']` | 单客户端每小时最多计数的不同链接数，`0` = 不限 |
| `$config['csp_mode']` | `'off'` / `'report'` / `'enforce'`（当前值） |
| `$admin_config` | 安装向导的用户名预填值与 session 名（**不含密码**） |
| `ADMIN_PASSWORD_MIN_LENGTH` | 管理员密码最小长度，安装向导 / 改密页 / 重置演示三处统一 |
| `$db_config['file']` | SQLite 数据库路径 |
| `LOGIN_FAIL_LIMIT` 等常量 | 登录与验证码的失败上限、锁定时长 |

> 站点文案（名称、Slogan、页脚）与搜索引擎清单在后台「站点设置」中可覆盖，库内设置优先于 `$config` 默认值。

---

## 自检

```bash
./tests/run.sh           # 语法检查 + 全部测试
./tests/run.sh --quick   # 跳过语法检查，只跑测试
```

覆盖：全部 PHP / JS 文件语法、点击去重与限流、后台 CRUD 与级联删除、凭据哈希、角色分级、双维度登录限流与指数退避、站点设置读写往返、搜索引擎解析、双语言 en / zh-CN、语言切换与表单脏检查。

也可以只跑双语言：

```bash
php tests/i18n_smoke.php en
php tests/i18n_smoke.php zh-CN
```

所有 PHP 测试使用一次性临时数据库，**不会触碰 `data/company_nav.sqlite`**。

### 持续集成

`.github/workflows/tests.yml` 在每次 push / PR 时自动跑 `./tests/run.sh`：

- PHP 版本矩阵 **8.0 / 8.2 / 8.4**（本地开发环境是 8.0.30，横跨三档防止误用高版本语法）
- 另有一个 job 把 Node 从 `PATH` 中摘掉后重跑，验证 Node 只是可选依赖（缺它应跳过 JS 测试，而不是失败）

> 环境判定用 `PDO::getAvailableDrivers()` 是否含 `sqlite`，**不要**用 `extension_loaded('pdo_sqlite')`
> ——部分 PHP 构建把 sqlite 驱动静态编译进去，后者会返回 `false` 造成误报（本机就是这种情况）。

---

## 部署

见 [`docs/deploy.md`](docs/deploy.md)：nginx / Apache 配置样例、`data/` 拦截、目录权限表、WAL 备份注意事项、上线自检清单。

目录权限：

- `data/`：需 Web 进程可读写，**必须禁止外部访问**（PHP 内置服务器运行时由 `router.php` 兜底）；
- `assets/icons/`：图标落盘目录，需 Web 进程可写；
- 代码不做任何硬编码绝对路径，全部相对项目根目录定位。

---

## 备注

- 演示数据重置：登录后台 →「♻️ 重置演示」→ 二次确认后恢复初始状态。重置前会用 SQLite 的 `VACUUM INTO` 生成一份一致快照存到 `data/backups/`（WAL 模式下直接复制文件可能漏掉仍在 `-wal` 里的已提交事务），默认保留最近 `BACKUP_KEEP_MAX`（10）份，只清理自己生成的 `before-*.sqlite`；
- 主题偏好记录在 `theme` cookie，与浏览器系统深色模式无冲突；
- 图标批量刷新有防僵死保护：单次请求 15s 超时、整批 90s 总时限、单条失败最多重试 1 次，任何情况下进度条都会结束并给出统计；
- 演示链接均使用公共平台入口，不含内网地址或私人资料；初始点击数为 0，按实际访问累加；
- 保险箱初始为空，首次进入需设置主密码；
- 无任何外部依赖，不联网也能跑（图标抓取除外）。
