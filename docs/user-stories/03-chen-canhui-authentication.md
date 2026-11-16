# User Stories — Member 3 · Chen Canhui（陈灿辉）

**Test Module / 测试模块:** 身份认证与账户管理（Authentication & Account Management）
**Primary Role / 主要角色:** Visitor / Editor / Owner（跨角色）
**Original User Stories:** US-10, US-13, US-15, US-17
**Video slot / 视频时段:** Segment 2 Part 2（1 min，Test basis 的技术部分）+ Segment 3 Part 3（30 秒，TestLink demo：Authentication suite），本人合计 1 min 30 s；段序不得调换，全程画中画摄像头

**环境前提 / Environment:**
- 后台入口：`https://nav.ptyilingjiu.cn/admin/login.php`（前台首页 `https://nav.ptyilingjiu.cn/index.php`）
- 演示账号 `admin / admin123.`；验证码为图片显示，输错可点图片换一张
- **限流与锁定类用例会锁定共享账号**（线上是整组共用数据）：锁定类用例排在当天最后一批，执行前在群内预告时间窗，跑完等待锁定期自然过期，不要反复试错

---

## US-10 — Log in and log out（登录与退出）

**As an** editor, **I want** to log in with my account, password and a CAPTCHA, and log out safely, **so that** only team members can modify shared data.
**As an** 编辑，**I want** 通过账号、密码与图形验证码登录并安全退出，**so that** 只有团队成员能修改共享数据。

**Acceptance criteria / 验收条件:** 四字段齐全（含隐藏 CSRF）；成功重生成 Session ID；IP 与账号双维度限流并指数退避；退出后重定向。（FR-12, FR-13, FR-16）

**测试操作指引 / How to Test:**

| # | 操作步骤 | 预期结果 | AC |
|---|---|---|---|
| 1 | 输入正确账号 + 错误密码 + 正确验证码提交 | 提示「账号或密码错误，还可尝试 2 次。」，停留在登录页 | 1 |
| 2 | 输入正确账号密码 + 错误验证码提交 | 提示「验证码错误或已失效，请重新输入。还可尝试 9 次。」；点验证码图片可换一张（`captcha.php?v=` 变化） | 1 |
| 3 | 四要素全部正确提交 | 进入后台；DevTools 可见 Session Cookie 值已变化（`session_regenerate_id`） | 2 |
| 4 | 连续输错密码至 3 次 | 第 3 次后 IP 与账号双维度锁定 30 秒；锁定期内即使密码正确也拒绝，提示剩余秒数 | 3 |
| 5 | 锁定后再错，观察锁定时长 | 按 30 → 60 → 120 … 指数退避（封顶 32 倍即 960 秒） | 3 |
| 6 | 点击退出登录，再直接访问 `admin/index.php` | 重定向回登录页，后台内容不可见 | 4 |

---

## US-13 — Change my own password（修改密码）

**As an** editor, **I want** to change my password after verifying the current one, **so that** I can keep my account secure.
**As an** 编辑，**I want** 在校验当前密码后修改自己的密码，**so that** 我能保证账号安全。

**Acceptance criteria / 验收条件:** 校验当前密码；≥8 位；两次一致；不动业务数据；计入限流。（FR-15）

**测试操作指引 / How to Test:**

| # | 操作步骤 | 预期结果 | AC |
|---|---|---|---|
| 1 | 后台进入「修改密码」，当前密码故意输错 | 拒绝修改并提示 | 1 |
| 2 | 当前密码正确，新密码输 7 位 | 拒绝，提示最短 8 位 | 2 |
| 3 | 新密码 8 位但两次输入不一致 | 拒绝，提示不一致 | 2 |
| 4 | 全部正确提交后退出，用新密码重新登录 | 登录成功；旧密码失效 | 3 |
| 5 | 改密后检查链接/分类/统计页 | 数据与改密前完全一致 | 3 |
| 6 | 连续输错当前密码多次 | 计入登录限流（与登录失败同一计数） | 4 |

---

## US-15 — Set up the first account（安装向导）

**As an** owner installing the system, **I want** a wizard that creates the first administrator on a fresh database, **so that** no default credentials ever exist.
**As an** 站长，**I want** 通过安装向导在全新数据库上创建首位管理员，**so that** 系统不存在任何默认密码。

**Acceptance criteria / 验收条件:** 空库跳转向导；owner 账号 ≥8 位；装好后向导失效；非本机来源须输入安装密钥。（FR-11）

**测试操作指引 / How to Test:**

> ⚠️ 本条**不在线上执行**（线上库已有管理员账号，`admin_needs_install()` 恒为假，向导页只会 `redirect('login.php')`，无法触发）。取证方式：由 owner 在服务器侧重建演示库后，Tester 只验证首次访问行为；或改用**本地副本**取证，并在报告与用例 Description 注明取证环境。

| # | 操作步骤 | 预期结果 | AC |
|---|---|---|---|
| 1 | 由 owner 在服务器侧重建演示库（空库启动时 `config.php` 自动 `init_database()` 并 `seed_demo_data()` 写入演示数据），Tester 只验证首次访问行为 | 全新库首次访问任意页自动建表并写入演示数据；后台因 `admin_users` 为空而跳转向导 | — |
| 2 | 访问 `admin/login.php` | 跳转到 `admin/install.php` 安装向导 | 1 |
| 3 | 设置用户名与 8 位以上密码提交 | 创建成功并直接登录进入后台（首位管理员恒为 owner） | 2 |
| 4 | 再次访问 `admin/install.php` | 重定向到登录页，向导失效 | 3 |
| 5 | 由非 `127.0.0.1` / `::1` 来源访问向导页（如线上域名） | 须额外输入 `data/.install_key` 中的 40 位十六进制安装密钥，错误提示「安装密钥不正确，请查看 data/.install_key 文件。」；本机来源免密钥 | 4 |

---

## US-17 — Enable self-registration（开放注册）

**As an** owner, **I want** to allow teammates to register their own accounts, **so that** I do not have to create accounts manually.
**As an** 站长，**I want** 允许组员自助注册账号，**so that** 我不必手动逐个建号。

**Acceptance criteria / 验收条件:** 开关控制入口；需验证码；新账号为 editor；成功也计限流；关闭时不可提交。（FR-14）

**测试操作指引 / How to Test:**

| # | 操作步骤 | 预期结果 | AC |
|---|---|---|---|
| 1 | owner 在站点设置勾选「开放注册」并保存 | 登录页出现「立即注册」入口 | 1 |
| 2 | 打开注册页，填写用户名/密码（≥8 位）+ 正确验证码提交 | 注册成功，用新账号登录后仅能看到链接与分类管理 | 2 |
| 3 | 检查新账号角色 | 为 `editor`，访问 `settings.php` 被拒 | 2 |
| 4 | 同一 IP 短时间连续注册至 `REGISTER_MAX_PER_WINDOW` | 后续注册被锁定提示 | 3 |
| 5 | owner 关闭「开放注册」后直接 POST 注册表单 | 一律拒绝，注册入口消失 | 4 |
