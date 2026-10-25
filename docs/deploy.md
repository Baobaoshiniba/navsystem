# 部署配置说明

本项目为原生 PHP + SQLite，运行时只有一个数据文件，没有外部依赖。但正因为「简单」，有两条部署安全底线必须在**上线前**落实，否则管理员密码哈希会直接暴露给公网。

---

## 一、必须做的两件事

### 1. 禁止 `data/` 目录被 HTTP 访问

`data/company_nav.sqlite` 内含：

- `admin_users` 表的管理员密码哈希（含角色字段）
- `vault_items` / `vault_meta` 密码保险箱数据（密文与密钥派生盐）
- `settings`、`login_throttle`、`click_throttle` 站点配置与限流摘要（IP 与用户名均为 sha256 摘要，不落明文，但泄露后仍可用于关联分析）

该项目通过 `start.sh` 启动时，已由 `router.php` 在本地拦住这条路径。**正式 Web 服务器不会读取 `router.php`，必须另行配置。**

### 2. 保护安装向导（远程部署必读）

系统**没有默认账号**：全新库不播种任何管理员，首次访问后台会跳到 `admin/install.php`，由你当场设置第一位站长的用户名与密码。

由此带来一个公网部署特有的风险：**谁先打开安装页，谁就是站长**。为此，非本机（非 `127.0.0.1` / `::1`）访问安装页时必须额外输入安装密钥，密钥在首次启动后自动生成：

```bash
cat data/.install_key
```

- 本机访问免验密钥 —— 本地开发与课堂演示不受影响。
- 装好之后 `admin/install.php` 立即失效，再访问会跳转登录页，不存在「再建一个 owner」的可能。
- `data/.install_key` 受 [`data/` 目录的三层封堵](#1-拦住-data-目录) 保护，且 `data/*` 已被 `.gitignore` 排除，不会进仓库。

从旧版本升级的站点，存量账号会被自动标记为「需要改密」，登录后在后台顶部看到提醒横幅。改密码请走后台「修改密码」页 `admin/password.php`（**不会清空任何数据**），或在服务器上直接执行：

```bash
php -r '
require "config.php";
admin_save_account("你的用户名", "新密码至少8位");
echo "账号已更新\n";
'
```

---

## 二、nginx 配置样例

```nginx
server {
    listen 80;
    listen 443 ssl http2;
    server_name your-domain.example;

    root /var/www/navsystem;
    index index.php index.html;

    ssl_certificate     /path/to/fullchain.pem;
    ssl_certificate_key /path/to/privkey.pem;

    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options SAMEORIGIN always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;

    charset utf-8;
    client_max_body_size 8m;

    # ---- 关键 - 阻止下载运行时数据 ----
    location ^~ /data/  { deny all; return 404; }
    location ^~ /data   { deny all; return 404; }
    location ^~ /.git/  { deny all; return 404; }
    location ~* \.(sqlite|sqlite3|db)$ { deny all; return 404; }

    # 图标缓存目录允许读取静态图片，但要挡住非图片执行
    location ^~ /assets/icons/ {
        location ~ \.php$ { deny all; return 404; }
    }

    # ---- PHP-FPM ----
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_read_timeout 120;   # 后台批量抓取图标需要较长时间
    }

    location ~* \.(css|js|svg|png|jpg|jpeg|gif|ico|webp|ttf|woff2)$ {
        expires 7d;
        add_header Cache-Control "public, immutable";
        try_files $uri =404;
    }
}
```

> `deny all` 返回的是 403，这里统一 `return 404`，避免向探测者确认「该路径确实存在」。

---

## 三、Apache / LiteSpeed

仓库已自带 `data/.htaccess`（`Require all denied`），部署时**不要删除**。若服务器禁用了 `.htaccess`，需在虚拟主机配置里显式加：

```apache
<Directory "/var/www/navsystem/data">
    Require all denied
</Directory>
```

---

## 四、目录权限

| 路径 | 建议权限 | 说明 |
| --- | --- | --- |
| `data/` | `0770`，属主为 Web 进程用户 | SQLite 主库与 `-wal` / `-shm` 需要写权限 |
| `data/company_nav.sqlite` | `0660` | 同上 |
| `data/backups/` | `0770` | 备份目录 |
| `assets/icons/` | `0775` | 图标落盘需要写权限 |
| 其余代码文件 | `0644`，只读 | 不应被 Web 进程修改 |

不要把 `data/` 与 `assets/icons/` 设为 `0777`；只需要它们对运行 PHP-FPM 的那个用户可写。

---

## 五、SQLite 并发

`config.php` 的 `db()` 中已开启（不写死行号，避免改代码后文档失效）：

```sql
PRAGMA busy_timeout = 60000;
PRAGMA journal_mode = WAL;
```

WAL 模式下会在 `data/` 生成 `-wal` 与 `-shm` 两个附属文件，**这两个文件也必须有写权限**，否则落库时会报错。备份数据库时请连同 `-wal` 一起处理，或直接先执行：

```bash
sqlite3 data/company_nav.sqlite "PRAGMA wal_checkpoint(TRUNCATE);"
```

让数据完全合并回主库后再复制。

---

## 六、上线自检清单

```bash
# 1. 数据库不可下载（最重要）
curl -s -o /dev/null -w "%{http_code}\n" https://your-domain/data/company_nav.sqlite
#    必须是 404 或 403，返回 200 请立即停站整改

# 2. 备份同样不可下载
curl -s -o /dev/null -w "%{http_code}\n" https://your-domain/data/backups/
#    同上

# 3. 首页与后台可访问
curl -s -o /dev/null -w "%{http_code}\n" https://your-domain/index.php
curl -s -o /dev/null -w "%{http_code}\n" https://your-domain/admin/login.php

# 4. 安全响应头
curl -s -D - -o /dev/null https://your-domain/index.php | grep -iE "x-frame|nosniff|referrer"
```

若第 1 项返回 200，说明 `data/` 完全暴露，请按第二节补齐 nginx 规则后重试。

---

## 七、上线前的应用层配置

前六节是「不配就会泄密」的部分，这一节是「不配则防护没有真正生效」的部分。

### 1. CSP 当前为 enforce

`config.php` 的 `$config['csp_mode']` 当前是 **`'enforce'`** —— 违规脚本会被浏览器直接拦下。

它的前置条件（内联脚本带 nonce、无内联事件属性、无 `eval`）已由 `tests/structure.php` 第 8 节自动守住，CI 会跑，所以正常改动代码不会悄悄破坏它。

**上线后请在浏览器里确认这几处仍然正常**：主题切换、语言切换、后台的二次确认弹窗、验证码换图——它们依赖 `assets/js/ui-wiring.js` 的事件委托，是 CSP 下最容易出问题的地方。

若确有功能失灵，改回 `'report'` 即可立刻恢复（只上报不拦截），再排查具体指令：

```php
'csp_mode' => 'report',
```

### 2. 注册开关默认关闭，公网建议保持关闭

后台「站点设置」里的注册开关默认是关的。开启后**新注册的账号一律是 `editor`**（只能维护链接与分类，进不了站点设置 / 重置 / 保险箱），但仍建议仅在可信内网环境下开启。

### 3. 角色分级

| 角色 | 权限 | 来源 |
| --- | --- | --- |
| `owner` | 全部 | 首次安装由 `$admin_config` 播种的账号 |
| `editor` | 仅链接与分类 | 注册页创建的所有账号 |

若需要给同学开一个只能维护内容的账号，让其自行注册即可，无需你交出 owner。

### 4. 保险箱主密码不可恢复

密码保险箱的加密密钥由主密码经 PBKDF2 派生，**只存在于 PHP 会话中**，服务端不保存可用于解密的材料。忘记主密码 = 该保险箱内所有条目永久无法解密，只能清空重建。若打算存放真实凭据，请先确认这一点可以接受。
