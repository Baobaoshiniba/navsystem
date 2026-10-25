<?php
/**
 * 公司导航页 - 后台站点设置
 *
 * 功能：修改前台展示文案（公司名 / Logo 文字 / 副标题 / 搜索占位 / 页脚），保存后立即生效；
 *       留空字段会回退为 config.php 中的系统默认文案。
 */

declare(strict_types=1);

require __DIR__ . '/_init.php';
require_login();
secure_session_start();

/* 展示字段配置：[label, type, hint]（同时作为保存白名单，避免误写无关键） */
$fieldMeta = [
    'site_name'          => [t('站点名称'), 'text', t('显示在页面标题与后台品牌区；留空使用默认值')],
    'logo_text'          => [('Logo ' . t('文字')), 'text', t('顶栏 Logo 显示文字，可与站点名称不同；留空使用默认值')],
    'site_slogan'        => [t('副标题 / 欢迎语'), 'text', t('顶栏品牌区下方与主视觉区的一句话简介')],
    'search_placeholder' => [t('聚合搜索提示语'), 'text', (t('前台聚合搜索栏输入框的提示语，') . '{engine} ' . t('会替换为当前选中的引擎名；留空使用默认值'))],
    'footer_text'        => [t('页脚 / 项目信息'), 'text', t('页面底部展示的项目说明；留空使用默认值')],
];

foreach ($fieldMeta as $key => $meta) {
    $fieldMeta[$key . '_en'] = [$meta[0] . ' · English', $meta[1], t('英文留空时显示中文内容')];
}

if (is_post()) {
    guard_csrf();
    foreach (array_keys($fieldMeta) as $k) {
        save_setting($k, post($k, ''));
    }

    // 聚合搜索引擎清单：每行「名称|URL模板」，格式非法 / 重复 / 非 http(s) 的行直接忽略
    $engineRaw = (string)post('search_engines', '');
    save_setting('search_engines', $engineRaw);

    $validEngines = $engineRaw === '' ? default_search_engines() : parse_search_engines($engineRaw);
    if (!$validEngines) {
        $validEngines = default_search_engines();
    }

    // 默认引擎：仅接受存在于有效清单中的名称；不匹配时写入空值（前台回退首个）
    $defaultName = (string)post('search_engine_default', '');
    $matched     = false;
    foreach ($validEngines as $eng) {
        if ($eng['name'] === $defaultName) {
            $matched = true;
            break;
        }
    }
    save_setting('search_engine_default', $matched ? $defaultName : '');

    // 统计被忽略的配置行，便于管理员定位格式问题
    $ignored = 0;
    if ($engineRaw !== '') {
        $nonEmpty = 0;
        foreach (preg_split('/\r\n|\r|\n/', $engineRaw) ?: [] as $line) {
            if (trim($line) !== '') {
                $nonEmpty++;
            }
        }
        $ignored = max(0, $nonEmpty - count(parse_search_engines($engineRaw)));
    }

    // 「开放注册」开关：勾选写入 '1'，未勾选写入 '0'（键缺失即默认关闭），保存后登录 / 注册页即时生效
    save_setting('register_enabled', post('register_enabled') === '1' ? '1' : '0');
    flash($ignored > 0
        ? (t('站点设置已保存，前台已生效；搜索引擎配置有') . ' ') . $ignored . (' ' . t('行格式无效或重复，已自动忽略'))
        : t('站点设置已保存，前台已生效'));
    redirect('settings.php');
}

$rows     = db()->query('SELECT key, value FROM settings')->fetchAll();
$current  = [];
foreach ($rows as $r) {
    $current[$r['key']] = $r['value'];
}

$defaults   = site_settings_defaults();
$registerOn = register_enabled();

admin_header(t('站点设置'), 'settings');

echo ('<div class="page-title-row">
    <h1 class="page-title">' . t('站点设置') . '</h1>
    <p class="page-sub">' . t('管理前台展示文案，保存后立即生效；留空字段将回退为系统默认值。') . '</p>
    <a class="btn-ghost btn-sm" href="../index.php" target="_blank" rel="noopener">' . t('预览前台') . ' ↗</a>
</div>');

echo '<form method="post" action="settings.php" class="panel glass">';
echo csrf_field();
echo '<div class="form-stack">';
foreach ($fieldMeta as $key => [$label, $type, $hint]) {
    $value = e($current[$key] ?? $defaults[$key] ?? '');
    echo '<div class="form-field">
        <label class="field-label" for="f-' . e($key) . '">' . e($label) . '</label>
        <input class="form-input" type="' . $type . '" id="f-' . e($key) . '" name="' . e($key) . '"
               value="' . $value . '" placeholder="' . e($defaults[$key] ?? '') . '" maxlength="200">
        <div class="field-hint">' . e($hint) . '</div>
    </div>';
}

// 聚合搜索引擎：清单（每行「名称|URL模板」）+ 默认引擎
$engines       = search_engines();
$enginesRaw    = setting('search_engines');
$defaultEngine = default_search_engine_name();
$engineNames   = array_map(static function (array $eng): string { return $eng['name']; }, $engines);

echo ('<div class="form-field">
    <label class="field-label" for="f-search_engines">' . t('聚合搜索引擎清单') . '</label>
    <textarea class="form-input form-textarea" id="f-search_engines" name="search_engines" rows="8"
              spellcheck="false" placeholder="') . e(default_search_engines_text()) . '">' . e($enginesRaw) . ('</textarea>
    <div class="field-hint">' . t('每行一个引擎，格式「名称') . '|URL' . t('模板」；模板必须以 http') . ':// ' . t('或 https') . ':// ' . t('开头并包含') . ' {q} ' . t('占位符（关键词会经 encodeURIComponent 编码后替换') . ' {q}），' . t('例如：必应') . '|https://www.bing.com/search?q={q}。</div>
    <div class="field-hint">' . t('不符合格式的行会被自动忽略；整个清单留空时使用系统内置的 4 个引擎。') . '</div>
    <div class="field-hint">' . t('当前生效：')) . e(implode(' / ', $engineNames)) . ('（' . t('共') . ' ') . count($engines) . (' ' . t('个）') . '</div>
</div>');

echo ('<div class="form-field">
    <label class="field-label" for="f-search_engine_default">' . t('默认搜索引擎') . '</label>
    <select class="form-input" id="f-search_engine_default" name="search_engine_default">');
foreach ($engines as $eng) {
    echo '<option value="' . e($eng['name']) . '"' . ($eng['name'] === $defaultEngine ? ' selected' : '') . '>' . e($eng['name']) . '</option>';
}
echo ('</select>
    <div class="field-hint">' . t('前台聚合搜索栏默认选中的引擎；若刚修改了上方清单，请先保存、回到本页后再调整默认项。') . '</div>
</div>');

echo '</div>';

// 「开放注册」开关：与文字项同表单提交，勾选即写入 '1'，取消勾选写回 '0'
echo ('<div class="switch-field">
    <div class="switch-main">
        <span class="field-label" id="lbl-register">' . t('开放注册') . '</span>
        <div class="field-hint">' . t('开启后，后台登录页显示「立即注册」入口，访客可自助创建管理账号；关闭时注册页一律不可提交。') . '</div>
        <div class="field-hint">' . t('当前状态：')) . ($registerOn ? t('已开启') : t('已关闭（默认）')) . '</div>
    </div>
    <label class="switch">
        <input type="checkbox" name="register_enabled" value="1" aria-labelledby="lbl-register"' . ($registerOn ? ' checked' : '') . '>
        <span class="switch-track" aria-hidden="true"></span>
        <span class="switch-state" aria-hidden="true">' . ($registerOn ? t('开启') : t('关闭')) . '</span>
    </label>
</div>';

echo ('<div class="form-actions">
    <button type="submit" class="btn-primary">' . t('保存设置') . '</button>
</div>');
echo '</form>';

admin_footer();
