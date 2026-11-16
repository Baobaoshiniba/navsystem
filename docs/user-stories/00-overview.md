# 用户故事分工总表 / User Story Assignment Overview

**Project:** Team Resource Hub · MCSP 1163 Software Testing · Group G3
**Total:** 5 members · 5 modules · 21 user stories · 36 functional requirements

---

## 分工总览 / Assignment Table

| 成员 / Member | 测试模块 / Test Module | 原始 User Stories | 主要角色 / Primary Role | 需求覆盖 / Covers |
|---|---|---|---|---|
| Member 1 · Chuyanqiao | 资源浏览与搜索<br>Resource Browsing & Search | US-01 ~ 04, 07 | Visitor | FR-01 – FR-05, FR-08, FR-28 |
| Member 2 · Zhoushun | 界面设置与用户体验<br>Interface Settings & UX | US-05, 06, 08, 09 | Visitor | FR-06, FR-07, FR-09, FR-10 |
| Member 3 · Chen Canhui | 身份认证与账户管理<br>Authentication & Accounts | US-10, 13, 15, 17 | Visitor / Editor / Owner | FR-11 – FR-16 |
| Member 4 · Li Xinxian | 资源与分类管理<br>Resource & Category Management | US-11, 12, 14, 21 | Editor | FR-18 – FR-27, FR-36 |
| Member 5 · Liu Weixin | 系统配置与安全管理<br>System Configuration & Security | US-16, 18, 19, 20 | Owner | FR-17, FR-29 – FR-35 |

---

## 故事清单 / Story Index

| ID | 故事 / Story | 模块 | 负责人 | 需求 |
|---|---|---|---|---|
| US-01 | Browse the resource grid 浏览资源网格 | M1 | Chuyanqiao | FR-01, FR-02 |
| US-02 | Instant keyword filtering 关键词即时筛选 | M1 | Chuyanqiao | FR-03 |
| US-03 | Search with an external engine 聚合搜索 | M1 | Chuyanqiao | FR-04 |
| US-04 | See a daily quote 每日一言 | M1 | Chuyanqiao | FR-05 |
| US-07 | Count resource clicks 点击计数 | M1 | Chuyanqiao | FR-08, FR-28 |
| US-05 | Switch colour theme 主题切换 | M2 | Zhoushun | FR-06 |
| US-06 | Switch interface language 语言切换 | M2 | Zhoushun | FR-07 |
| US-08 | Use the portal on mobile 响应式布局 | M2 | Zhoushun | FR-09 |
| US-09 | See the contact address 联系邮箱 | M2 | Zhoushun | FR-10 |
| US-10 | Log in and log out 登录与退出 | M3 | Chen Canhui | FR-12, FR-13, FR-16 |
| US-13 | Change my own password 修改密码 | M3 | Chen Canhui | FR-15 |
| US-15 | Set up the first account 安装向导 | M3 | Chen Canhui | FR-11 |
| US-17 | Enable self-registration 开放注册 | M3 | Chen Canhui | FR-14 |
| US-11 | Manage links 链接管理 | M4 | Li Xinxian | FR-18 – FR-23 |
| US-12 | Manage categories 分类管理 | M4 | Li Xinxian | FR-24 – FR-26 |
| US-14 | View click statistics 查看统计 | M4 | Li Xinxian | FR-27 |
| US-21 | Refresh site icons 图标抓取 | M4 | Li Xinxian | FR-36 |
| US-16 | Configure site settings 站点设置 | M5 | Liu Weixin | FR-33, FR-34 |
| US-18 | Use the password vault 密码保险箱 | M5 | Liu Weixin | FR-29 – FR-32 |
| US-19 | Reset the demo environment 重置演示 | M5 | Liu Weixin | FR-35 |
| US-20 | Trust the role boundary 角色隔离 | M5 | Liu Weixin | FR-17 |

---

## Project 1 交付要求对照 / PR1 Deliverable Mapping

> 依据课程 `Project 1 Instruction`：Task 板块为 1 条能力目标句 + 4 条交付动作（T1 – T4），Instruction 板块为 3 条约束（I-1 – I-3）。责任人与截止为组内约定。

### 一、Task 交付动作

| 编号 | 原文要求 | 本组对应交付物 | 责任人 | 截止 |
|---|---|---|---|---|
| T-目标 | Able to derive and prepare test cases in the Testlink tool for a selected software | 用例必须"推导"出来（EP / BVA / 负向），并**全部落在 TestLink 内**，不得只写在 Word / Excel 里 | 全员 | 11/22 |
| T1 | Refer TestLink user manual to prepare the test cases | 按手册要求填 Summary / Preconditions / Steps / Expected Results；每条用例写明对应 AC 与单条 FR 编号 | 全员（Liu Weixin 复核字段规范） | 11/20 |
| T2 | Submit the test case report (generated from the TestLink) in pdf format | 由 TestLink 导出 test case report 再转 **PDF**，不得自制 Word 版顶替 | Liu Weixin | 11/22 |
| T3 | Submit your respective test basis | 本组 test basis = `functional-requirements.md`（FR-01 – FR-36）+ 本目录 5 份个人故事文件（21 个故事及其 AC） | Chuyanqiao（汇总） | 11/22 |
| T4 | Submit a group video presentation that explains and demonstrates your test design | 见 `video-script.md`：官方四段 1 / 2 / 4 / 1 分钟合计 8 分钟，**全组只录一个** | 全员 | 11/22 |

### 二、Instruction 三条约束

| 编号 | 原文要求 | 本组落地口径 |
|---|---|---|
| I-1 | 每组已有 project；**DO NOT create a new project in the TestLink**；由 Project 1 leader 把 test case 分配给组员 | 只操作讲师已建的 G3 project；套件按 `G3_M1` – `G3_M5` 命名并与本文件模块编号一一对齐；分配动作由 Liu Weixin 完成并留痕 |
| I-2 | Tester 各自建自己的 test suite 与 test case，针对各自的 use case | 每人只在自己模块套件下建用例；跨模块改动先与归属人确认，不得替他人建用例 |
| I-3 | 全组只录一个视频，且符合视频要求 | 统一环境与录制规范、后期拼接；四段顺序不得调换；全程画中画摄像头 |

### 三、提交与视频硬要求

| 项 | 要求 |
|---|---|
| 提交入口 | e-learning → MCSP1163 → Project 1 Submission |
| 提交内容 | 视频 + PR1 report（含由 TestLink 导出的 test case report PDF）+ test basis |
| 视频载体 | MP4 文件，或 YouTube **unlisted** / Google Drive 链接 |
| 视频时长 | 7 – 10 分钟；官方四段 = 1 / 2 / 4 / 1 分钟 |
| 发言要求 | 每人发言 1 – 2 分钟；Test Leader 负责开场与收尾 |
| 画面要求 | **全程开画中画摄像头（window box）**，不得只录屏不露脸 |

---

## 覆盖检查 / Coverage Check

| 检查项 | 结果 |
|---|---|
| 故事总数 | 21（US-01 – US-21，无重复、无遗漏） |
| 需求覆盖 | 36 / 36（FR-01 – FR-36 全部至少被一个故事承接） |
| 角色覆盖 | Visitor · Editor · Owner 三角色均有对应成员负责 |
| 无主故事 | 0 |
| 模块归属 | 每个故事仅属一个模块，边界不重叠 |

---

## 环境与归属口径 / Environment & Ownership Basis（8 个文件统一以此为准）

### 运行环境（全组统一口径，禁止再写本地地址）

| 项 | 取值 |
|---|---|
| 前台首页 | `https://nav.ptyilingjiu.cn/index.php` |
| 分类筛选 | `https://nav.ptyilingjiu.cn/index.php?cat=all`（或 `?cat=<分类名>`，点「全部」后地址为 `index.php?cat=all`） |
| 后台登录 | `https://nav.ptyilingjiu.cn/admin/login.php`（四字段：`username` / `password` / `captcha` / 隐藏 `csrf_token`） |
| 演示账号 | owner `admin / admin123.` · editor `editor / editor123.` |
| 数据基线 | 4 个分类（小组协作 / 开发工具 / 测试与质量 / 学习与报告）各 25 条、共 100 条；**前台不分页**（100 条同页渲染），`PAGE_SIZE = 8` 只作用于**后台列表** |
| 角色可见范围 | owner 全部 7 个入口；editor 仅「链接管理 / 分类管理 / 点击统计 / 修改密码」，直连站点设置、重置演示、密码保险箱均 403 |

> ⚠️ 旧口径 `./start.sh`、`http://127.0.0.1:8000`、`8001`、「私有副本/单独端口」**一律作废**。
> 唯一例外：`04-li-xinxian-resources-categories.md` 中作为 **SSRF 测试输入数据**出现的 `http://127.0.0.1` 与 `http://169.254.169.254` 必须原样保留，它们不是运行环境地址。
> 破坏性 / 限流类用例（重置演示、登录锁定、改密失败、保险箱、分类级联删除）执行前必须备份快照并在群内预告时间窗，且排在最后一批。

### 归属口径（与 TestLink 已建套件对齐；与 by-module 冲突处以本节为准）

| 成员 | TestLink 套件 | 负责 FR |
|---|---|---|
| Chuyanqiao | `G3_M1` | FR-01 – FR-05, FR-08, **FR-28** |
| Zhoushun | `G3_M2` | FR-06, FR-07, FR-09, FR-10 |
| Chen Canhui | `G3_M3` | FR-11 – FR-16 |
| Li Xinxian | `G3_M4` | FR-18 – FR-27, **FR-36** |
| Liu Weixin | `G3_M5` | **FR-17**, FR-29 – FR-35 |

> `by-module.md` 的模块编号与 FR 归属属较早版本（把 M2 写成认证、把 FR-33 – FR-36 派给无材料成员、FR-24 – FR-28 留空），与本表冲突处**一律不采用**，详见该文件顶部的作废声明。

> 每份个人故事文件位于同目录（每个故事均附「测试操作指引」表：步骤 → 操作 → 预期结果 → 对应 AC）：
> `01-chuyanqiao-browse-search.md`、`02-zhoushun-ui-experience.md`、`03-chen-canhui-authentication.md`、`04-li-xinxian-resources-categories.md`、`05-liu-weixin-system-security.md`。
> 需求原文见 [functional-requirements.md](../functional-requirements.md)；全量故事与验收标准见 [user-stories.md](../user-stories.md)；`by-module.md` 为早期模块整理稿，**归属口径已作废、仅供追溯**，冲突处以上一节「归属口径」为准；视频讲稿见 [video-script.md](../video-script.md)。
> ⚠️ 限流、锁定、改密、重置、保险箱类用例会影响共享状态，执行顺序与隔离要求见各文件「环境前提」。
