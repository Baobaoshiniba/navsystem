#!/usr/bin/env bash
#
# 本地自检：语法检查 + 全部测试
#
#   ./tests/run.sh          跑全部
#   ./tests/run.sh --quick  跳过语法检查，只跑测试
#
# 所有 PHP 测试都使用一次性临时数据库，不触碰 data/company_nav.sqlite。
#
set -uo pipefail
cd "$(dirname "$0")/.."

failed=0
quick=false
[ "${1:-}" = "--quick" ] && quick=true

run() {
    local name="$1"; shift
    local out
    if out=$("$@" 2>&1); then
        printf '  \033[32m✓\033[0m %s\n' "$name"
        [ -n "$out" ] && printf '      %s\n' "$out"
    else
        printf '  \033[31m✗\033[0m %s\n' "$name"
        printf '%s\n' "$out" | sed 's/^/      /'
        failed=$((failed + 1))
    fi
}

# ---------- 1. 语法检查 ----------
if [ "$quick" = false ]; then
    echo "语法检查"
    php_syntax_failed=0
    while IFS= read -r file; do
        if ! out=$(php -l "$file" 2>&1); then
            printf '  \033[31m✗\033[0m %s\n' "$file"
            printf '%s\n' "$out" | sed 's/^/      /'
            php_syntax_failed=$((php_syntax_failed + 1))
        fi
    done < <(find . -name '*.php' -not -path './.idea/*' -not -path './.workbuddy/*')
    if [ "$php_syntax_failed" -eq 0 ]; then
        printf '  \033[32m✓\033[0m 全部 PHP 文件语法正确\n'
    else
        failed=$((failed + php_syntax_failed))
    fi

    if command -v node >/dev/null 2>&1; then
        run "JavaScript 语法" bash -c '
            for f in assets/js/*.js; do node --check "$f" || exit 1; done
        '
    fi
fi

# ---------- 2. PHP 测试 ----------
echo
echo "PHP 测试"
run "工程结构守卫"        php tests/structure.php
run "点击去重与限流"      php tests/click_throttle.php
run "后台数据层与鉴权"    php tests/admin_logic.php
run "双语言 · English"    php tests/i18n_smoke.php en
run "双语言 · 中文"       php tests/i18n_smoke.php zh-CN
# --strict：漏了英文词条直接判失败。新增 UI 文案忘了补 lang/en.php 会在这里被拦下。
run "双语言词条覆盖"      php tests/i18n_coverage.php --strict
run "SSRF 与图片白名单"   php tests/icon_security.php
run "密码保险箱加解密"    php tests/vault.php
run "数据库备份与保留"    php tests/backup.php

# ---------- 3. JS 测试 ----------
if command -v node >/dev/null 2>&1; then
    echo
    echo "JavaScript 测试"
    run "语言切换与表单脏检查" node tests/language_switch.test.js
fi

# ---------- 汇总 ----------
echo
if [ "$failed" -eq 0 ]; then
    echo "全部通过 ✓"
else
    echo "有 ${failed} 项失败 ✗"
fi
exit $((failed > 0 ? 1 : 0))
