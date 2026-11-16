# User Stories — Member 1 · Chuyanqiao（初彦乔）

**Test Module / 测试模块:** 资源浏览与搜索（Resource Browsing & Search）
**Primary Role / 主要角色:** Visitor（访客）
**Original User Stories:** US-01, US-02, US-03, US-04, US-07
**Video slot / 视频时段:** Segment 1（1 min）——负责 40 秒「软件是什么 / 解决什么问题 / 成员与角色」；Leader 开场 20 秒另计。段序不得调换，全程画中画摄像头

**环境前提 / Environment:**
- 前台入口：`https://nav.ptyilingjiu.cn/index.php`（分类筛选走 `?cat=`，如 `index.php?cat=all`）
- 数据基线：4 个分类（小组协作 / 开发工具 / 测试与质量 / 学习与报告）各 25 条、共 100 条；**前台不分页**，100 条同页渲染
- 本模块全部用例**无需登录**，直接以访客身份操作前台
- 测试数据一律使用带「测试」字样的虚构内容

---

## US-01 — Browse the resource grid（浏览资源网格）

**As a** visitor, **I want** to see all shared resources grouped by category on the home page, **so that** I can quickly find the tools my team uses.
**As a** 访客，**I want** 在首页看到按分类分组的全部共享资源，**so that** 我能快速找到团队在用的工具。

**Acceptance criteria / 验收条件:** 卡片按分类分组、`sort_order` 升序；仅显示 `is_active = 1`；分类筛选与「全部」恢复；空分类显示空状态。（FR-01, FR-02）

**测试操作指引 / How to Test:**

| # | 操作步骤 | 预期结果 | AC |
|---|---|---|---|
| 1 | 打开 `https://nav.ptyilingjiu.cn/index.php` | 按分类分组展示 100 条资源卡片（4 分类 × 25）；卡片含图标、描述、点击次数 | 1 |
| 2 | 点击分类筛选标签「开发工具」（按钮文字带条数，如「开发工具25」） | URL 变为 `index.php?cat=开发工具`；网格仅显示该分类 25 条，标签高亮 | 2 |
| 3 | 点击「全部100」标签 | URL 变为 `index.php?cat=all`；恢复 100 条完整列表 | 2 |
| 4 | 由后台停用某分类下全部链接后回到前台点该分类 | 显示空状态提示「没有找到匹配的导航资源」，无报错 | 3 |

---

## US-02 — Instant keyword filtering（关键词即时筛选）

**As a** visitor, **I want** to filter resource cards instantly by typing a keyword, **so that** I can locate a specific team tool without browsing category by category.
**As a** 访客，**I want** 通过输入关键词即时过滤资源卡片，**so that** 我无需逐个分类浏览就能定位到特定工具。

**Acceptance criteria / 验收条件:** 匹配名称/关键词/描述/URL；不区分大小写；清空恢复；无结果提示。（FR-03）

**测试操作指引 / How to Test:**

| # | 操作步骤 | 预期结果 | AC |
|---|---|---|---|
| 1 | 在搜索框（占位符「搜索资源名称、网址或分类」）输入某工具名称的一部分，如 `git` | 网格即时过滤，无需回车或刷新 | 1 |
| 2 | 分别用 `GITHUB`（大写）与 `github`（小写）搜索 | 两次结果一致 | 1 |
| 3 | 输入一个 URL 片段（如 `zotero`）或描述中的词 | 命中对应卡片 | 1 |
| 4 | 输入不存在的词如 `zzzz测试` | 显示「没有找到匹配的导航资源」 | 2 |
| 5 | 清空输入框 | 立刻恢复完整网格 | 2 |

---

## US-03 — Search with an external engine（聚合搜索）

**As a** visitor, **I want** to submit my query to a selectable external search engine, **so that** I can search the web without leaving the portal.
**As a** 访客，**I want** 把关键词提交到可选的外部搜索引擎，**so that** 不用离开门户就能搜索网络。

**Acceptance criteria / 验收条件:** 引擎清单来自站点设置；默认引擎预选；`{q}` URL 编码替换。（FR-04）

**测试操作指引 / How to Test:**

| # | 操作步骤 | 预期结果 | AC |
|---|---|---|---|
| 1 | 观察搜索引擎条（engine strip） | 列出后台「站点设置」配置的全部引擎；默认引擎高亮 | 1 |
| 2 | 点击切换另一引擎 | 高亮切换，输入框提示语中 `{engine}` 随之变化 | 1 |
| 3 | 输入 `软件 测试`（含空格），点「网页搜索」或回车 | 新标签打开所选引擎，URL 中关键词被编码（空格为 `+` 或 `%20`） | 2 |
| 4 | 输入中文关键词如 `知网` 再搜索 | 跳转后引擎能正确检索该词（编码无误） | 2 |

---

## US-04 — See a daily quote（每日一言）

**As a** visitor, **I want** to see a daily quote on the home page, **so that** the portal feels alive each day.
**As a** 访客，**I want** 在首页看到每日一言，**so that** 门户每天都有更新感。

**Acceptance criteria / 验收条件:** 语言与界面一致；按日缓存；失败显示本地兜底。（FR-05）

**测试操作指引 / How to Test:**

| # | 操作步骤 | 预期结果 | AC |
|---|---|---|---|
| 1 | 中文界面打开首页，查看「每日一言」区块 | 显示中文寄语，来源标注（一言 / ZenQuotes / 小组寄语） | 1 |
| 2 | 切换到英文界面（`?lang=en`）再查看 | 寄语为英文来源 | 1 |
| 3 | 刷新页面数次 | 寄语内容不变（当日缓存），DevTools Network 中无重复 API 请求 | 2 |
| 4 | DevTools → Network 设为 Offline 后刷新 | 显示本地双语兜底寄语，页面其余部分正常 | 3 |

---

## US-07 — Count resource clicks（点击计数）

**As a** visitor, **I want** my clicks on a resource to be counted silently, **so that** the team can see which tools are popular.
**As a** 访客，**I want** 点击资源时静默计入统计，**so that** 团队能看出哪些工具更常用。

**Acceptance criteria / 验收条件:** 无刷新上报（1×1 GIF / 204）；同客户端同链接 24h 一次；每小时 200 链接上限；静默失败。（FR-08, FR-28）

**测试操作指引 / How to Test:**

| # | 操作步骤 | 预期结果 | AC |
|---|---|---|---|
| 1 | 记录某卡片当前点击数，点击该卡片 | 页面不刷新；DevTools Network 出现 `track_click.php` 请求，状态 204 | 1 |
| 2 | 刷新首页，查看该卡片点击数 | 数字 +1 | 1 |
| 3 | 24 小时内再次点击同一卡片 | 计数不再增加（去重生效） | 2 |
| 4 | 断网后点击任意卡片 | 无报错、无弹窗，页面行为正常（静默失败） | 4 |
| 5 | 后台「统计」页查看该链接 | 排行/总数与前台计数一致 | — |
