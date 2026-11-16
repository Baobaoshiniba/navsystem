# User Stories — Member 2 · Zhoushun（周顺）

**Test Module / 测试模块:** 界面设置与用户体验（Interface Settings & User Experience）
**Primary Role / 主要角色:** Visitor（访客）
**Original User Stories:** US-05, US-06, US-08, US-09
**Video slot / 视频时段:** Segment 2 Part 1（1 min）——Test basis 测试依据；段序不得调换，全程画中画摄像头

**环境前提 / Environment:**
- 前台入口：`https://nav.ptyilingjiu.cn/index.php`（主题切换、语言切换均在此页验证）
- US-09 需要后台权限（owner：`admin / admin123.`）修改联系邮箱
- 涉及 cookie 的用例建议使用浏览器无痕窗口或先清除 `theme` / `nav_lang`

---

## US-05 — Switch colour theme（主题切换）

**As a** visitor, **I want** to switch between light and dark themes, **so that** I can read comfortably in my environment.
**As a** 访客，**I want** 在深浅色主题之间切换，**so that** 我在不同环境下都能舒适阅读。

**Acceptance criteria / 验收条件:** 即时生效；cookie 保留 365 天；无 cookie 跟随系统偏好。（FR-06）

**测试操作指引 / How to Test:**

| # | 操作步骤 | 预期结果 | AC |
|---|---|---|---|
| 1 | 点击顶栏右侧的主题切换按钮 | 深浅色即时切换，页面不刷新、不闪白 | 1 |
| 2 | 切换后按 F5 刷新 | 主题保持；DevTools → Application → Cookies 可见 `theme` | 2 |
| 3 | 新开无痕窗口访问 | 主题跟随系统深色模式设置 | 3 |
| 4 | 手动把 `theme` cookie 改为非法值后刷新 | 回退到默认主题，不报错 | 3 |

---

## US-06 — Switch interface language（语言切换）

**As a** visitor, **I want** to switch the interface between Chinese and English, **so that** I can use the portal in my preferred language.
**As a** 访客，**I want** 在中英文界面之间切换，**so that** 我能用自己习惯的语言使用门户。

**Acceptance criteria / 验收条件:** 优先级 `?lang=` → cookie → 默认 `zh-CN`；非法值回退；字段级回退；跨页保持。（FR-07）

**测试操作指引 / How to Test:**

| # | 操作步骤 | 预期结果 | AC |
|---|---|---|---|
| 1 | 访问 `index.php?lang=en` | 整页（含导航、按钮、页脚）切为英文 | 1 |
| 2 | 去掉参数刷新 | 仍是英文（`nav_lang` cookie 已写入） | 1 |
| 3 | 访问 `index.php?lang=xx`（非法值） | 回退默认 `zh-CN`，不报错 | 2 |
| 4 | 后台把某链接的英文标题留空，前台切英文查看 | 该卡片显示中文标题（字段级回退） | 3 |
| 5 | 切换语言后进入任意后台页面 | 后台界面语言与所选语言一致 | 4 |

---

## US-08 — Use the portal on mobile（响应式布局）

**As a** visitor, **I want** the layout to adapt to my screen, **so that** the portal is usable on my phone.
**As a** 访客，**I want** 布局随屏幕自适应，**so that** 我在手机上也能正常使用门户。

**Acceptance criteria / 验收条件:** 桌面多列、手机双列/单列；后台侧栏转横向滚动；无横向溢出。（FR-09）

**测试操作指引 / How to Test:**

| # | 操作步骤 | 预期结果 | AC |
|---|---|---|---|
| 1 | 桌面浏览器最大化打开首页 | 多列网格展示 | 1 |
| 2 | DevTools → Toggle device toolbar，选 iPhone 尺寸 | 网格变双列或单列，文字不截断 | 1 |
| 3 | 同样尺寸打开 `admin/index.php` | 左侧栏变为顶部横向滚动导航 | 2 |
| 4 | 在 320 / 768 / 1440 三档宽度下横向拖动页面 | 除设计的横向滚动区外，页面无横向溢出 | 3 |

---

## US-09 — See the contact address（联系邮箱）

**As a** visitor, **I want** to see the team's contact e-mail in the footer, **so that** I know how to reach the owners.
**As a** 访客，**I want** 在页脚看到团队联系邮箱，**so that** 我知道如何联系站长。

**Acceptance criteria / 验收条件:** 显示配置的邮箱；留空整行隐藏。（FR-10）

**测试操作指引 / How to Test:**

| # | 操作步骤 | 预期结果 | AC |
|---|---|---|---|
| 1 | owner 登录后台 → 站点设置 → 联系邮箱填 `test@example.com` → 点「保存设置」 | 提示「站点设置已保存，前台已生效」（引擎清单有非法/重名行时会追加「搜索引擎配置有 N 行格式无效或重复，已自动忽略」） | 1 |
| 2 | 打开前台页脚 | 显示刚填写的邮箱 | 1 |
| 3 | 回后台清空邮箱字段再保存，刷新前台 | 页脚邮箱整行消失，无空白占位 | 2 |
| 4 | 邮箱字段输入超过 254 字符 | 输入框 maxlength 限制无法继续输入 | — |
