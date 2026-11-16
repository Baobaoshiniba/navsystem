# User Stories — Member 5 · Liu Weixin（刘炜鑫 · Test Leader）

**Test Module / 测试模块:** 系统配置与安全管理（System Configuration & Security Management）
**Primary Role / 主要角色:** Owner（站长）
**Original User Stories:** US-16, US-18, US-19, US-20
**Video slot / 视频时段:** Segment 3 Part 2（90 秒，TestLink demo：settings & vault suites）+ Segment 4（30 秒，Coverage summary / Test Plan-Build / challenges 收尾），本人合计 ≤2 min

**环境前提 / Environment:**
- 线上后台 `https://nav.ptyilingjiu.cn/admin/login.php`；本模块全部用例需要 **owner** 身份（`admin / admin123.`），对照组用 `editor / editor123.`
- **重置与保险箱用例会改共享状态**：执行前先做快照（`VACUUM INTO` 或后台导出备份到 `data/backups/`，保留上限 10 份），并在群内预告时间窗；破坏性用例排在最后一批
- 线上无 shell 时，US-18 步骤 3 的「库内无明文」改「本地库副本取证 + 线上界面行为」双证据，在该用例 Description 标 `Evidence: local DB copy + online UI behavior`
- 保险箱演示主密码 `12345678`；仅使用虚构凭据

---

## US-16 — Configure site settings（站点设置）

**As an** owner, **I want** to edit bilingual site copy, the contact e-mail and the search engine list, **so that** the portal matches our team without touching code.
**As an** 站长，**I want** 编辑双语站点文案、联系邮箱与搜索引擎清单，**so that** 不改代码就能让门户符合团队需求。

**Acceptance criteria / 验收条件:** 双语保存即时生效、留空回退；引擎行解析与忽略提示；默认引擎必须存在。（FR-33, FR-34）

**测试操作指引 / How to Test:**

| # | 操作步骤 | 预期结果 | AC |
|---|---|---|---|
| 1 | 站点设置修改「站点名称」中文并保存，打开前台 | 标题与品牌区即时变为新名称，无需重启 | 1 |
| 2 | 只填英文名、清空中文名，前台切中文查看 | 显示英文（无中文时回退）；切英文显示英文 | 1 |
| 3 | 清空全部文案字段保存 | 前台回退 `config.php` 默认文案 | 1 |
| 4 | 引擎清单加一行 `测试\|ftp://x.com/{q}`（非法协议）保存 | 该行被忽略，提示「有 1 行格式无效或重复，已自动忽略」 | 2 |
| 5 | 再加一行与现有引擎重名（如 `必应\|…`）保存 | 重名行同样被忽略，忽略计数 +1 | 2 |
| 6 | 「默认引擎」下拉选择某引擎保存，前台查看搜索条 | 该引擎默认高亮；若清单中已无此名则回退首个 | 3 |

---

## US-18 — Use the password vault（密码保险箱）

**As an** owner, **I want** to store shared credentials encrypted behind a master password, **so that** sensitive logins are not kept in plain chat history.
**As an** 站长，**I want** 用主密码加密保存团队共享凭据，**so that** 敏感密码不会暴露在聊天记录里。

**Acceptance criteria / 验收条件:** 主密码哈希存储；独立 IV 加密；掩码与 CSRF 揭示；搜索筛选；15 分钟自动锁定。（FR-29 – FR-32）

**测试操作指引 / How to Test:**

| # | 操作步骤 | 预期结果 | AC |
|---|---|---|---|
| 1 | 进入「密码保险箱」，输入错误主密码 | 拒绝解锁并提示；多次错误计入限流 | 1 |
| 2 | 输入正确主密码 `12345678` 解锁 | 进入保险箱界面 | 1 |
| 3 | 点「填入测试样例」→「加入保险箱」 | 条目保存；直接查 SQLite 文件看不到明文（密文存储） | 2 |
| 4 | 点「显示」查看密码，再点一次 | 先显示明文，再恢复掩码 | 2 |
| 5 | 点「复制」粘贴到记事本 | 与样例密码一致 | 2 |
| 6 | 搜索框输入关键词 / 切换分类筛选 | 仅匹配条目变化 | 4 |
| 7 | 解锁后停止操作 15 分钟（或手动点锁定） | 保险箱自动/立即锁定，需重新输入主密码 | 5 |

---

## US-19 — Reset the demo environment（重置演示）

**As an** owner, **I want** to restore the demo environment to a known state, **so that** the next tester starts from clean, documented data.
**As an** 站长，**I want** 将演示环境恢复到已知状态，**so that** 下一位测试者从干净且可预期的数据开始。

**Acceptance criteria / 验收条件:** 三种模式；VACUUM INTO 快照保留 10 份；确认短语拦截；恢复后状态可预期。（FR-35）

**测试操作指引 / How to Test:**

| # | 操作步骤 | 预期结果 | AC |
|---|---|---|---|
| 1 | 进入「重置演示」，选「重置链接」并确认 | 全部链接恢复初始状态，分类与账号保留 | 1 |
| 2 | 记录 `data/backups/` 现有快照数，执行一次重置 | 出现新的 `before-*.sqlite`；超过 10 份时最旧的被清理 | 2 |
| 3 | 确认短语故意输错提交 | 重置被拦截，数据不变 | 3 |
| 4 | 输入正确短语执行「恢复完整 Demo」 | 备份先行成功；账号重建为 `admin / admin123.`、100 条链接、点击清零、保险箱清空 | 1, 4 |
| 5 | 恢复后检查站点文案、主题、搜索配置 | 均保留未受影响 | 4 |
| 6 | 其他成员用旧会话刷新后台 | 会话失效，需重新登录 | 4 |

---

## US-20 — Trust the role boundary（角色隔离）

**As an** owner, **I want** editor accounts to be blocked from owner-only pages even by direct URL, **so that** privileges cannot be bypassed.
**As an** 站长，**I want** 编辑账号即使直接输入 URL 也无法进入站长专属页面，**so that** 权限无法被绕过。

**Acceptance criteria / 验收条件:** editor 直接请求被服务端拒绝（HTTP 403 + 跳回列表页）；不泄露内容；导航隐藏。（FR-17）

**测试操作指引 / How to Test:**

| # | 操作步骤 | 预期结果 | AC |
|---|---|---|---|
| 1 | 用 `editor / editor123.` 登录，逐一直接访问 `settings.php`、`reset.php`、`vault.php` | 三个页面均返回 403 并跳回 `admin/index.php`，提示「该操作需要站长权限，当前账号只能维护链接与分类。」 | 1 |
| 2 | 观察拒绝后的页面 | 不出现站点配置、备份列表或保险箱条目等敏感信息 | 2 |
| 3 | 查看 editor 的后台侧栏导航 | 仅有链接管理、分类管理、点击统计、修改密码；无站点设置、重置演示、密码保险箱入口 | 3 |
| 4 | 用 owner 重复第 1 步 | 三个页面均可正常打开（对照组） | 1 |
| 5 | editor 对 `settings.php` 直接 POST（构造表单） | 同样 403 拒绝，服务端校验失败不依赖前端隐藏 | 1 |
