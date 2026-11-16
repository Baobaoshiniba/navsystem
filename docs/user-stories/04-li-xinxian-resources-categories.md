# User Stories — Member 4 · Li Xinxian（李新贤）

**Test Module / 测试模块:** 资源与分类管理（Resource & Category Management）
**Primary Role / 主要角色:** Editor（编辑）
**Original User Stories:** US-11, US-12, US-14, US-21
**Video slot / 视频时段:** Segment 3 Part 1（2 min）——TestLink demo：link & category suites（`G3_M4`）；段序不得调换，全程画中画摄像头

**环境前提 / Environment:**
- 后台入口：`https://nav.ptyilingjiu.cn/admin/login.php`，以 owner `admin / admin123.` 或 editor `editor / editor123.` 登录
- 测试条目统一命名带「测试」前缀，便于用后清理或整体重置
- 删除类操作会影响共享数据，测前阅读 `docs/demo-testing.md`

---

## US-11 — Manage links（链接管理）

**As an** editor, **I want** to create, edit, delete, reorder, pin, enable and disable links, **so that** the portal always reflects the team's current toolset.
**As an** 编辑，**I want** 新增、编辑、删除、排序、置顶与启停链接，**so that** 门户始终反映团队当前的工具集。

**Acceptance criteria / 验收条件:** 必填校验合并提示；双语字段回退；排序回跳；停用隐藏；后台列表每页 8 条（`PAGE_SIZE = 8`，前台不分页、全部同页渲染）；删除三处同步。（FR-18 – FR-23）

**测试操作指引 / How to Test:**

| # | 操作步骤 | 预期结果 | AC |
|---|---|---|---|
| 1 | 链接管理 → 展开面板「新增链接」，三项全空直接提交 | 提示合并列出：标题、URL、分类不能为空 | 1 |
| 2 | 填写「测试链接A」+ URL + 选择分类，点「新增链接」 | 提示「链接已新增」，列表与前台出现该卡片，点击数为 0 | 1 |
| 3 | 点「编辑」，清空英文标题保存，前台切英文查看 | 卡片显示中文标题（字段级回退） | 2 |
| 4 | 对该链接点「上移」「下移」「置顶」 | 位置相应变化；首条的上移按钮禁用；操作后停留在该记录所在页 | 3 |
| 5 | 点「停用」，切到前台查看 | 卡片消失；后台状态徽标变「停用」 | 4 |
| 6 | 造满 9 条链接，看列表分页 | 第 8/9 条跨页；删除末页唯一一条后页码回退 | 5 |
| 7 | 点「删除」并确认 | 后台、前台、统计三处均不再出现 | 6 |

---

## US-12 — Manage categories（分类管理）

**As an** editor, **I want** to create, edit, reorder, enable and delete categories, **so that** the resource grid stays organised as the team grows.
**As an** 编辑，**I want** 新增、编辑、排序、启停与删除分类，**so that** 资源网格随团队扩张保持有序。

**Acceptance criteria / 验收条件:** 名称必填；级联删除并提示条数；排序交换 `sort_order`；停用前台隐藏。（FR-24 – FR-26）

**测试操作指引 / How to Test:**

| # | 操作步骤 | 预期结果 | AC |
|---|---|---|---|
| 1 | 分类管理 → 新增，名称留空提交 | 拒绝并提示名称必填 | 1 |
| 2 | 新增「测试分类」+ 英文名，保存 | 列表与前台分类标签出现 | 1 |
| 3 | 删除一个含 3 条链接的测试分类 | 提示「分类已删除，其下 3 条链接已一并删除」；前台无孤儿卡片 | 2 |
| 4 | 对分类点「上移」「置顶」 | 顺序按 `sort_order` 更新，置顶者排最前 | 3 |
| 5 | 停用该分类，刷新前台 | 分类标签消失，后台列表仍显示（标记停用） | 4 |

---

## US-14 — View click statistics（查看统计）

**As an** editor, **I want** to see total clicks, per-category distribution and top links, **so that** I can judge which resources matter to the team.
**As an** 编辑，**I want** 查看总点击、分类分布与链接排行，**so that** 我能判断哪些资源对团队真正有用。

**Acceptance criteria / 验收条件:** 三块内容齐全；零点击正常渲染；点击后更新。（FR-27）

**测试操作指引 / How to Test:**

| # | 操作步骤 | 预期结果 | AC |
|---|---|---|---|
| 1 | 重置演示后（点击全为 0）打开统计页 | 汇总卡片、分布、排行均正常显示 0，无空表报错 | 2 |
| 2 | 前台点击某链接 2 次（不同小时），回统计页 | 总数 +2，该链接排名上升 | 3 |
| 3 | 对比分类分布图与该分类下链接计数之和 | 数字吻合 | 1 |

---

## US-21 — Refresh site icons（图标抓取）

**As an** editor, **I want** icons fetched automatically for new links, **so that** the grid looks consistent without manual artwork.
**As an** 编辑，**I want** 新链接自动抓取站点图标，**so that** 网格视觉统一且无需手工做图。

**Acceptance criteria / 验收条件:** 单条重抓与批量刷新带统计；四级兜底；SSRF 拦截；超时优雅结束。（FR-36）

**测试操作指引 / How to Test:**

| # | 操作步骤 | 预期结果 | AC |
|---|---|---|---|
| 1 | 新增一条知名站点链接（如 `https://github.com`） | 保存后图标自动抓取并显示 | 1 |
| 2 | 对任一链接点「重抓」 | 单条刷新成功，图标更新 | 1 |
| 3 | 点「刷新全部图标」，观察进度条 | 游标分批推进，结束给出成功/失败统计 | 1 |
| 4 | 新增一条指向 `http://127.0.0.1` 或 `http://169.254.169.254` 的链接并抓取（这两个地址是 **SSRF 测试输入数据**，不是运行环境地址） | 请求被拒绝（`icon_ip_is_public` 拦截回环与内网地址），显示首字母徽章兜底 | 3 |
| 5 | 新增一条不可达域名链接并抓取 | 超时后给出提示，页面不僵死，最终有兜底徽章 | 4 |
