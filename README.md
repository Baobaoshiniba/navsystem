# 公司导航页（Company Nav）

科技感公司内部/对外导航门户：PHP 原生 + SQLite（PDO）实现，无 Composer、无框架，开箱即用。支持深/浅色主题一键切换、分类分组导航、搜索即时过滤、点击统计与独立管理后台。

## 功能特性

- **前台导航页**
  - 科技感 UI：渐变光效、玻璃拟态卡片、网格背景、卡片 hover 光标光斑
  - 深色 / 浅色双主题一键切换，自动记住用户选择（cookie 保存 365 天）
  - 按分类分组展示导航卡片：分类标题 + 链接列表（emoji 图标、描述、点击次数）
  - 搜索框按名称 / 关键词 / 描述 / URL 即时过滤，附分类快捷标签
  - 响应式布局：桌面多列网格、手机双列/单列自适应
  - 底部公司信息区
- **管理后台**（`admin/`）
  - Session 登录鉴权，默认账号 `admin` / `admin123`（可在 `config.php` 修改）
  - 分类管理：新增 / 编辑 / 删除 / 上下移排序 / 置顶 / 启停
  - 链接管理：新增 / 编辑 / 删除 / 排序 / 启停，字段含标题、URL、描述、图标、所属分类
  - 点击统计：汇总卡片、分类点击分布条形图、链接 TOP 排行
  - 一键重置演示数据（带二次确认）
  - 后台界面同样具备科技感 + 深浅色切换
- **安全与健壮性**
  - PDO 预处理语句防 SQL 注入
  - 全部输出经 HTML 转义防 XSS
  - 所有 POST 表单带 CSRF Token 校验
  - 删除分类提示并级联删除其下链接；删除 / 重置等敏感操作二次确认
  - 登录成功后重新生成 Session ID

## 项目结构

```
company-nav/
├── index.php            # 前台导航首页
├── track_click.php      # 点击计数接口（无刷新 +1）
├── config.php           # 全局配置：站点信息 / 后台账号密码 / 数据库 / CSRF / 主题
├── README.md
├── start.sh             # 本地启动脚本（PHP_CLI_SERVER_WORKERS=4 多 worker 托管）
├── data/                # SQLite 数据库目录（首次运行自动创建，含演示数据）
│   └── company_nav.sqlite
├── assets/
│   ├── css/style.css    # 全站样式（深浅色双主题变量 + 科技感组件）
│   └── js/theme.js      # 主题切换逻辑（cookie 记忆 + 系统偏好兜底）
└── admin/
    ├── _init.php        # 后台公共函数：鉴权 / 布局 / 提示
    ├── login.php        # 登录页
    ├── logout.php       # 退出登录
    ├── index.php        # 导航链接管理
    ├── categories.php   # 分类管理
    ├── stats.php        # 点击统计
    └── reset.php        # 一键重置演示数据
```

## 本地启动

环境要求：PHP 8.x（含 `pdo_sqlite` 扩展），macOS / Linux / Windows 通用。

推荐用项目自带的启动脚本（多 worker 托管，单个耗时请求不会拖垮整站）：

```bash
cd company-nav
./start.sh                                   # 默认 127.0.0.1:8000，PHP_CLI_SERVER_WORKERS=4
HOST=0.0.0.0 PORT=8080 WORKERS=8 ./start.sh   # 可自定义监听地址 / 端口 / worker 数
```

也可以手动指定 worker 数：

```bash
cd company-nav
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8000
```

> - `PHP_CLI_SERVER_WORKERS` 由 PHP 内置服务器（macOS / Linux）支持；Windows 下该环境变量不生效，会回退为单进程。
>   单进程模式下，后台批量抓取图标期间同浏览器其它后台请求可能排队等待，建议按上面的方式开启多 worker。
> - 数据库并发：SQLite 已启用 WAL 日志模式并设置 `busy_timeout=60000ms`（见 `config.php`），多 worker 并发读写不会直接抛 `SQLITE_BUSY`。

浏览器打开：

| 页面 | 地址 |
|---|---|
| 前台导航页 | http://127.0.0.1:8000/index.php |
| 管理后台 | http://127.0.0.1:8000/admin/login.php |

> 首次访问任意页面时会自动创建 `data/company_nav.sqlite` 并写入演示数据（5 个分类、17 条链接）。

## 默认后台账号

| 项 | 值 |
|---|---|
| 登录地址 | http://127.0.0.1:8000/admin/login.php |
| 默认账号 | `admin` |
| 默认密码 | `admin123` |

登录页会展示默认账号提示；正式使用请在 `config.php` 中修改：

```php
$admin_config = [
    'username' => 'admin',      // ← 改为你的账号
    'password' => 'admin123',   // ← 改为强密码
];
```

## 常用配置（config.php）

| 配置 | 说明 |
|---|---|
| `$config['site_name']` | 公司名称（前台 Logo 区 / 页面标题） |
| `$config['site_slogan']` | 副标题 / Slogan |
| `$config['site_footer']` | 底部公司信息 |
| `$admin_config` | 后台登录账号密码 |
| `$db_config['file']` | SQLite 数据库路径 |

## 目录权限说明

- `data/`：SQLite 数据库目录，需 Web 进程可读写（PHP 内置服务器运行时可忽略）；
- 代码不做任何硬编码绝对路径，全部相对项目根目录定位，整目录拷贝即可迁移。

## 备注

- 演示数据重置：登录后台 →「♻️ 重置演示」→ 确认后即恢复初始状态；
- 主题偏好通过 `theme` cookie 记录，与浏览器系统深色模式无冲突；
- 图标批量刷新设有防僵死保护：单次请求 15s 超时、整批 90s 总时限、单条失败最多重试 1 次，任何情况下进度条都会结束并给出统计；
- 后台在耗时抓取前会调用 `session_write_close()` 释放 session 写锁，因此抓取期间同一浏览器仍可正常打开其它后台页面。
- 本项目为纯静态 PHP + SQLite，无任何外部依赖。
*（内容由AI生成，仅供参考）*
*（内容由AI生成，仅供参考）*
