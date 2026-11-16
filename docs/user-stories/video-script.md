# Video Script / 演示视频讲稿（四段结构版）

**Project:** Team Resource Hub · MCSP 1163 Software Testing · Group G3
**Deliverable:** 全组只提交**一个**讲解视频（T4：Submit a group video presentation that explains and demonstrates your test design）
**Total length:** 8 分钟（官方四段 **1 / 2 / 4 / 1** 分钟，总时长须落在 7 – 10 分钟）
**提交:** e-learning → MCSP1163 → Project 1 Submission，随 PR1 report 一起交，截止 **2026-11-22**
**视频载体:** MP4 文件，或 YouTube unlisted / Google Drive 链接（二选一，链接须组外可访问）
**Status:** 讲稿要点已定；【待填】为个人细节占位，录前统一补齐

> ⚠️ **三条铁律**
> 1. **段序不得调换**：Introduction → Test Basis & Approach → TestLink Demo → Coverage Summary，严格按官方 1 / 2 / 4 / 1 分钟推进。
> 2. **每人发言 1 – 2 分钟**（含跨段累计，见下表「个人累计」列），任何人不得超 2 分钟。
> 3. **全程开画中画摄像头**（window box，人脸始终可见），不得只录屏不露脸；共享屏幕时摄像头小窗须保留在画面内。

> ⚠️ **未决事项（11/14 前书面确认）**：Test Leader 人选。下方暂按「Chuyanqiao 负责 Segment 1 开场 + Segment 4 收尾」标注；确认后全局替换人名即可，段时长与段序不变。

---

## 结构总览 / Segment Map

| # | 段落 / Segment | 官方时长 | 主讲 / Speaker | 个人累计 | 内容要点 |
|---|---|---|---|---|---|
| 1 | Introduction 软件与团队介绍 | **1 min** | Leader 开场 20 s → Chuyanqiao 40 s | Chuyanqiao 1 min 10 s | 软件是什么、解决什么问题、5 名成员与角色 |
| 2 | Test Basis & Approach 测试依据与方法 | **2 min** | Zhoushun 1 min → Chen Canhui 1 min | 各 1 min | test basis 来源、EP / BVA / 协作式设计及理由 |
| 3 | TestLink Demo 实操演示（屏幕共享） | **4 min** | Li Xinxian 2 min → Liu Weixin 1 min 30 s → Chen Canhui 30 s | Li 2 min；Liu 累计 2 min；Chen 累计 1 min 30 s | 套件结构、样例用例 Steps/Expected、需求追溯、执行结果 |
| 4 | Coverage Summary 覆盖总结与收尾 | **1 min** | Liu Weixin 30 s → Leader 收尾 30 s | Liu 累计 2 min；Chuyanqiao 累计 1 min 40 s | 覆盖率、Test Plan/Build、遇到的挑战、结论与致谢 |

> 合计 8 分 00 秒；五人个人累计均 ≤ 2 分钟。

---

## Segment 1 · Introduction（1 min · Leader 20 s + Chuyanqiao 40 s）

**讲稿要点 / Talking points:**

1. 〔Leader 20 s〕开场：报组号 G3、说明本视频是 MCSP 1163 Project 1 的 test design 讲解与演示，交代四段结构与时长。
2. 〔Chuyanqiao 40 s〕**软件是什么**：Team Resource Hub（小组项目导航门户），PHP 8 + SQLite，前台按分类展示团队常用链接，后台可维护链接、分类、站点文案与共享凭据。
3. 〔Chuyanqiao〕**解决什么问题**：把散落在聊天记录里的工具链接、账号与站点配置收拢到一个可搜索、可分权限管理的门户，减少「链接找不到、密码到处翻」的重复沟通。
4. 〔Chuyanqiao〕**成员与角色**（一句话一人）：
   - Chuyanqiao — 资源浏览与搜索（M1，Visitor）
   - Zhoushun — 界面设置与用户体验（M2，Visitor）
   - Chen Canhui — 身份认证与账户管理（M3，Visitor / Editor / Owner）
   - Li Xinxian — 资源与分类管理（M4，Editor）
   - Liu Weixin — 系统配置与安全管理（M5，Owner，Test Leader 候选人）
5. 过渡句：引出下一段「我们的测试依据是什么」。

**画面 / Visuals:** 线上首页 `https://nav.ptyilingjiu.cn/index.php` 全屏（4 分类 × 25 = 100 条资源）+ 分工总表（[00-overview.md](00-overview.md)）；摄像头小窗常开。

---

## Segment 2 · Test Basis & Approach（2 min · Zhoushun → Chen Canhui）

### Part 1（1 min · Zhoushun）— Test Basis 测试依据

1. Test basis 两层：**36 条功能需求**（[functional-requirements.md](../functional-requirements.md)，FR-01 – FR-36）+ 本组 **21 个用户故事及其验收标准**（5 份个人故事文件 + [user-stories.md](../user-stories.md)）。
2. 追溯链：需求 FR → 用户故事 US / AC → TestLink 用例（每条用例只挂**一条**主责 FR，不写 `FR-18 – FR-23` 这类范围式编号）。
3. 为什么这样拆：需求给「系统该做什么」，故事与 AC 给「某类用户在什么前提下看到什么结果」，两者合成可执行、可验证、可追溯的用例基线。
4. 过渡句：交给 Chen Canhui 讲设计技术。

### Part 2（1 min · Chen Canhui）— Techniques 设计技术

1. **等价类划分（EP）**：每个等价类取一个代表，valid + invalid 双线覆盖（如登录密码「正确 / 错误 / 空」三类）。
2. **边界值分析（BVA）**：min−1 / min / max / max+1，用在真正有边界的规则上——密码长度 8 位（`ADMIN_PASSWORD_MIN_LENGTH = 8`、`VAULT_MIN_MASTER_LEN = 8`）、验证码 4 位、后台列表每页 8 条。
3. **协作式设计**：五个模块按 owner 归属交叉评审，用例互查（同一 FR 只由归属人建用例，避免重复与冲突）。
4. **正向 / 负向**：Normal 用例证明功能可用，Negative 用例证明限制生效（限流、403 越权、SSRF 拒绝、确认短语拦截）。
5. 举例：US-10 登录限流——密码连错 3 次起锁 30 秒（指数退避，上限 960 秒），验证码连错 10 次锁 300 秒；据此设计「第 2 次错仍可试、第 3 次错触发锁定、锁定期间正确密码也被拒」三条用例。

**画面:** 摄像头 + 需求与故事对照页（或 TestLink 追溯视图），不切到本地地址。

---

## Segment 3 · TestLink Demo（4 min · 屏幕共享 · Li 2 min → Liu 1 min 30 s → Chen 30 s）

> 屏幕共享期间**摄像头小窗常开**；演示账号与数据用线上 `https://nav.ptyilingjiu.cn`，画面中不得出现 `127.0.0.1` / `8001`。

### Part 1（2 min · Li Xinxian）— Link & Category Suites（M4）

1. 展示 TestLink 层级：Project → Test Plan → Test Suite → Test Case；指出套件命名 `G3_M1` – `G3_M5` 与模块编号一一对齐（**不得新建 project**，只用讲师已建的 G3）。
2. 展开 `G3_M4` 用例树：链接管理（US-11 / FR-18 – FR-23）+ 分类管理（US-14 / FR-24 – FR-26）+ 图标抓取（US-21 / FR-36）。
3. 现场打开一条样例用例（US-11 新增链接）：Summary / Preconditions / Steps / Expected Results 四段齐全，写明对应 AC。
4. 演示需求追溯：把该用例关联到 **FR-18**（单条 FR），展示 Requirements 视图可回查。
5. 演示执行：置为 Passed 并填 Actual Result；再置一条为 Failed（如分类删除后前台仍显示），填实际观察值。

### Part 2（1 min 30 s · Liu Weixin）— Settings & Vault Suites（M5）

1. 展开 `G3_M5` 四个子套件：Settings / Password Vault / Reset & Demo Recovery / Role Boundary（对应 US-16 / US-18 / US-19 / US-20）。
2. 挑一条负向用例现场执行：US-20 角色隔离——editor 直连 `settings.php` 返回 403 并提示「该操作需要站长权限，当前账号只能维护链接与分类。」，置 Failed/Passed 并贴截图证据。
3. 说明保险箱用例的取证口径：线上无 shell 时改「本地库副本取证 + 线上界面行为」双证据（Description 标 `Evidence:`）。
4. 展示 TestLink 导出的 test case report（PDF）与执行统计（Total / Passed / Failed / Blocked）。

### Part 3（30 s · Chen Canhui）— Authentication Suite（M3）

1. 展开 `G3_M3`：注册 / 登录四字段（`username`、`password`、`captcha`、隐藏 `csrf_token`）/ 登录锁定 / 改密。
2. 点开一条锁定用例，指出 Expected Result 写的是「密码连续错 3 次起锁 30 秒」，与 `config.php` 的 `LOGIN_FAIL_LIMIT` / `LOGIN_LOCK_BASE_SECONDS` 对应。
3. 过渡句：交给收尾总结。

---

## Segment 4 · Coverage Summary（1 min · Liu Weixin 30 s → Leader 30 s）

1. 〔Liu 30 s〕覆盖总结：21 个用户故事、36 条 FR 全部有用例承接（引用 [00-overview.md](00-overview.md) 覆盖检查表：需求覆盖 36 / 36、无主故事 0）。
2. 〔Liu〕Test Plan / Build：写明所用 plan 与 build 名称（整组没有则由 Leader 建后用，禁止新建 project）；已执行用例数与 Passed / Failed / Blocked 分布。
3. 〔Liu〕遇到的挑战：线上共享数据无法回滚 → 用 `VACUUM INTO` 快照 + 群内预告时间窗隔离破坏性用例；后台分页 8 条 vs 前台 100 条同页渲染，断言位置必须写清。
4. 〔Leader 30 s〕一句结论（核心功能全部通过、缺陷及状态），致谢并结束。

---

## 录制备注 / Recording Notes

**硬性规范**

- 时长 7 – 10 分钟，按 1 / 2 / 4 / 1 分段推进，**段序不得调换**；每段结束用一句转场语衔接。
- 每人发言 1 – 2 分钟；录前各段自测秒表，超 2 分钟的段必须删句而不是加速。
- **全程画中画摄像头开启**（window box），人脸始终可见；屏幕共享时小窗不得被遮挡或裁掉。
- 交付形态：MP4 文件，或 YouTube unlisted / Google Drive 链接（组外可访问）；随 PR1 report 交 e-learning，截止 11/22。

**统一环境**

- 演示一律走线上：前台 `https://nav.ptyilingjiu.cn/index.php`，后台 `https://nav.ptyilingjiu.cn/admin/login.php`；画面中不得出现 `127.0.0.1` / `localhost` / `8001`。
- 录屏分辨率统一 1080p、同一浏览器同一缩放；每段独立录制后期拼接（不用一条过）。
- 录前由 Liu Weixin 负责把演示环境恢复到 Demo 状态（US-19「恢复演示」），确认 4 分类各 25 条 = 100 条、点击统计已清零、登录限流计数器已过窗口期。
- 英文口播逐字稿：骨架定了之后逐段补全（每分钟约 130 – 150 词）。
