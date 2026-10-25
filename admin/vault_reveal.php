<?php
/**
 * 后台 - 密码保险箱：明文按需读取接口
 *
 * 只有在「已登录 + 保险箱已解锁 + CSRF 校验通过」三个条件同时满足时，才返回单条记录的
 * 账号 / 密码明文；明文经 JSON 一次性返回，不写入页面源码、不落盘、不记日志。
 *
 * 请求：POST action=reveal & id=<条目 id> & field=username|password & csrf_token=<token>
 * 响应：{"ok":true,"value":"..."} 或 {"ok":false,"message":"..."}
 */

declare(strict_types=1);

require __DIR__ . '/_init.php';
require __DIR__ . '/_vault.php';

require_login();
secure_session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

/**
 * 输出 JSON 并结束
 */
function vault_json(bool $ok, string $message = '', string $value = ''): void
{
    echo json_encode(
        ['ok' => $ok, 'message' => $message, 'value' => $value],
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

if (!is_post()) {
    vault_json(false, t('请求方式不正确。'));
}

if (!csrf_verify()) {
    vault_json(false, ('CSRF ' . t('校验失败，请刷新页面后重试。')));
}

if (post('action') !== 'reveal') {
    vault_json(false, t('不支持的操作。'));
}

if (!vault_is_unlocked()) {
    vault_json(false, t('保险箱已锁定（') . (int)(VAULT_IDLE_TIMEOUT / 60) . (' ' . t('分钟无操作），请重新输入主密码解锁。')));
}

$id    = (int)post('id', '0');
$field = post('field');

if (!in_array($field, ['username', 'password'], true)) {
    vault_json(false, t('不支持的字段。'));
}

$row = $id > 0 ? vault_get($id) : null;
if ($row === null) {
    vault_json(false, t('条目不存在，可能已被删除。'));
}

// 本次读取同样刷新活动时间
vault_touch();

if ($field === 'username') {
    vault_json(true, '', (string)$row['username']);
}

// 密码：从密文实时解密
$enc = (string)$row['password_enc'];
if ($enc === '') {
    vault_json(true, '', '');
}

$key = vault_session_key();
if ($key === '') {
    vault_json(false, t('保险箱已锁定，请重新解锁。'));
}

$plain = vault_decrypt($enc, $key);
if ($plain === null) {
    vault_json(false, t('密文解密失败，数据可能已损坏。'));
}

vault_json(true, '', $plain);
