# User Stories by Module — Final / 用户故事终稿（按模块分工）

> ## ⚠️ 本文件归属口径作废，以 [`00-overview.md`](00-overview.md) 为准
>
> **作废原因**：本文件与 `00-overview.md`、5 份个人故事文件生成时间不同。本文件把 M2 写成「认证与账号」、把 FR-33 – FR-36 派给本模块无对应故事材料的成员、并把 FR-24 – FR-28 留成空洞，与 TestLink 已建套件 `G3_M1` – `G3_M5` 的命名和 FR 归属不一致。
>
> **现行口径**（详见 `00-overview.md`「环境与归属口径」一节）：
>
> | 成员 | 套件 | 负责 FR |
> |---|---|---|
> | Chuyanqiao | `G3_M1` | FR-01 – FR-05, FR-08, **FR-28** |
> | Zhoushun | `G3_M2` | FR-06, FR-07, FR-09, FR-10 |
> | Chen Canhui | `G3_M3` | FR-11 – FR-16 |
> | Li Xinxian | `G3_M4` | FR-18 – FR-27, **FR-36** |
> | Liu Weixin | `G3_M5` | **FR-17**, FR-29 – FR-35 |
>
> **使用方式**：下文正文保留仅供追溯（模块描述、代表性故事与 ⭐ 新增 AC 仍可参考），其中模块编号、FR 归属、覆盖数统计**不得**用于 TestLink 建套件或报告追溯表。测试执行与归属一律回到 `00-overview.md` 与 5 份个人故事文件。

**System under test:** Team Resource Hub（小组项目导航门户）
**Structure:** 5 modules × 1 representative user story per member（5 个模块，每人 1 个代表性故事）
**Full story list / 全量故事:** [user-stories.md](user-stories.md) · **Test basis:** [functional-requirements.md](functional-requirements.md)
**Note / 说明:** ⭐ 标记的验收条件为本次终稿新增，用于兑现模块描述中承诺、但原稿未覆盖的范围。

---

## 模块一 · Front-end Navigation & Search（前台资源导航与检索）

**Owner / 负责人:** Chuyanqiao ｜ **Video slot:** 1 min Introduction（开场） ｜ **Module scope / 模块全量:** FR-01 – FR-10

> 面向普通访客，提供团队工具的高效聚合展示与即时查找能力。

**US-02 — Instant keyword filtering（关键词即时筛选）**
**As a** visitor, **I want** to filter resource cards instantly by typing a keyword, **so that** I can locate a specific team tool without browsing category by category.
**As a** 访客, **I want** 能够通过输入关键词即时过滤资源卡片, **so that** 我无需逐个分类浏览就能快速定位到特定的团队工具。

**Acceptance criteria / 验收条件:**

1. 筛选匹配支持名称、关键词、描述和 URL，且不区分大小写。（FR-03）
2. 输入框清空后，资源网格必须立刻恢复完整列表。（FR-03）
3. 无匹配结果时显示空状态提示，不报错、不隐藏分类标签。（FR-03）
4. ⭐ 在聚合搜索栏选择引擎并提交后，跳转至该引擎，`{q}` 被替换为 URL 编码后的关键词；空格与中文正确编码。（FR-04）

---

## 🔒 模块二 · Authentication & Access Control（访问控制与账户安全）

**Owner / 负责人:** Chen Canhui ｜ **Video slot:** 1 min Test basis & techniques（part 2） ｜ **Module scope / 模块全量:** FR-11 – FR-17

> 建立系统的安全边界，保障两级角色（Owner / Editor）的权限隔离与防刷限流。

**US-10 — Multi-dimension protected login（多维度防刷安全登录）**
**As an** editor or owner, **I want** to log in and log out securely with username, password and a graphical CAPTCHA, **so that** only authorised team members can modify the shared navigation data.
**As an** 编辑/站长, **I want** 通过账号、密码以及图形验证码安全登录和退出系统, **so that** 确保只有团队授权人员才能修改共享导航数据。

**Acceptance criteria / 验收条件:**

1. 登录需账号、密码与图形验证码三要素齐全；任一错误即拒绝并给出明确提示。（FR-12）
2. 登录成功必须重新生成 Session ID 以防会话固定攻击。（FR-12）
3. 对登录失败的尝试基于账号和 IP 双维度进行限流，并引入指数退避锁定机制。（FR-13）
4. 退出登录后必须完全销毁当前 Session，阻止通过直接输入后台 URL 绕过鉴权。（FR-16）
5. ⭐ `editor` 直接访问 `settings.php`、`reset.php`、`vault.php` 时被服务端拒绝，且不泄露页面内容；Owner 专属入口对 editor 隐藏。（FR-17）

---

## ✏️ 模块三 · Content Management（导航内容协同管理）

**Owner / 负责人:** Li Xinxian ｜ **Video slot:** 2 min TestLink demo（link & category suites） ｜ **Module scope / 模块全量:** FR-18 – FR-23

> Editor 和 Owner 日常维护的核心，支持团队资源链接与分类的增删改查及个性化排序。

**US-11 — Bilingual field-level and state management of links（链接的字段级双语及状态管理）**
**As an** editor, **I want** to create, edit, pin, enable and disable navigation links freely, **so that** the portal accurately reflects the team's current toolset.
**As an** 编辑, **I want** 自由地创建、编辑、置顶或启停导航链接, **so that** 门户能够准确反映团队当前正在使用的最新工具集。

**Acceptance criteria / 验收条件:**

1. 新增链接时标题、URL 与分类必填，分类必须存在；校验错误一次性合并提示。（FR-18）
2. 编辑支持中英文双语标题/描述，当英文值留空时自动回退显示中文。（FR-19）
3. 链接列表分页展示（每页 8 条），在链接被删除或重新排序后，页面应智能回跳并锚定到当前记录所在的页码。（FR-21, FR-23）
4. 被禁用的链接需立刻在前台隐藏，并停止统计其点击量。（FR-22）
5. ⭐ 删除链接后，后台列表、前台与统计页均不再出现该条目。（FR-20）

---

## 💼 模块四 · Credentials Vault（高安全级别凭据保险箱）

**Owner / 负责人:** Liu Weixin ｜ **Video slot:** 2 min TestLink demo（settings & vault suites）+ 1 min Coverage summary（收尾） ｜ **Module scope / 模块全量:** FR-29 – FR-36

> Owner 专属的高危高安全级模块，用于加密存储团队敏感的共享账号密码。

**US-18 — Encrypted credentials with on-demand reveal and auto-lock（加密凭据按需解锁与自动锁定）**
**As an** owner, **I want** to store the team's shared credentials encrypted behind a master password, **so that** sensitive passwords are never exposed in daily chat history.
**As an** 站长, **I want** 将团队共享的敏感凭据通过主密码加密保存在保险箱中, **so that** 避免将敏感密码直接暴露在日常聊天的历史记录中。

**Acceptance criteria / 验收条件:**

1. 保险箱仅能通过正确的主密码解锁，主密码仅以哈希存储；明文密码采用 AES-256-GCM 进行独立随机 IV 加密。（FR-29, FR-30）
2. 敏感字段默认掩码隐藏，显式查看（Reveal）必须通过带有 CSRF Token 的 POST 请求进行鉴权；揭示后可再次隐藏。（FR-30）
3. 系统在闲置 15 分钟后必须自动重新锁定保险箱；锁定后需重新输入主密码。（FR-32）
4. ⭐ 条目支持按关键词搜索与按分类筛选，筛选仅作用于匹配条目。（FR-31）

---

## 🛠️ 模块五 · Site Maintenance & DevOps（站点运维与环境重置）

**Owner / 负责人:** Zhoushun ｜ **Video slot:** 1 min Test basis & approach（part 1） ｜ **Module scope / 模块全量:** FR-33 – FR-36

> 面向 Owner，处理系统全局配置、数据一致性快照备份以及多维度的测试/演示环境重置。

**US-19 — Safe demo reset with automatic snapshot（演示环境安全重置与自动快照）**
**As an** owner, **I want** to restore the system to a clean demo state or a blank state in one action, **so that** the next tester or handover starts from standard, documented data.
**As an** 站长, **I want** 能够一键将系统恢复到干净的演示数据或空白状态, **so that** 下一位测试或交接的同学能基于标准的文档数据开始工作。

**Acceptance criteria / 验收条件:**

1. 允许选择"重置链接"、"清空为空白"或"恢复完整 Demo"三种模式。（FR-35）
2. 在执行任何重置前，必须使用 SQLite VACUUM INTO 自动截取并留存当前数据的一致性快照，且最多保留最新 10 份历史备份。（FR-35）
3. 执行重置必须要求准确输入二次确认短语，输入错误则强行拦截重置请求。（FR-35）
4. 恢复完整 Demo 后，公开账号、演示链接恢复，点击统计与保险箱条目清空，而站点文案、主题与搜索配置保留。（FR-35）
5. ⭐ 站点文案（名称/Logo/副标题/搜索提示/页脚/邮箱）双语保存并即时生效，留空回退默认；搜索引擎清单中非法或重复行被忽略并提示忽略条数。（FR-33, FR-34）

---

## Coverage Summary / 覆盖说明

| 模块 | 代表故事 | 直接覆盖 | 模块全量需求 |
|---|---|---|---|
| 一 | US-02 | FR-03, FR-04 | FR-01 – FR-10 |
| 二 | US-10 | FR-12, FR-13, FR-16, FR-17 | FR-11 – FR-17 |
| 三 | US-11 | FR-18 – FR-23 | FR-18 – FR-23 |
| 四 | US-18 | FR-29 – FR-32 | FR-29 – FR-32 |
| 五 | US-19 | FR-33 – FR-35 | FR-33 – FR-36 |

> 五个代表性故事直接覆盖 36 条需求中的 22 条；其余需求（FR-01/02/05–11/14/15/24–28/36）由各模块的全量故事清单承接，见 [user-stories.md](user-stories.md)。TestLink 建用例时以模块全量为范围，以代表性故事优先。
