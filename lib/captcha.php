<?php
/**
 * 公司导航页 - 后台登录图形验证码
 *
 * 由 config.php 加载。生成 / 校验 / 绘图三个环节都在这里：
 *   captcha_generate() 生成随机码并写入 Session（含过期时间）
 *   captcha_verify()   一次性校验，无论成败都销毁当前答案
 *   captcha_png()      直接输出 PNG 数据流（调用方是 admin/captcha.php）
 *
 * 依赖 GD；GD 不可用时 captcha_png() 会退化输出一段纯文本说明，不会抛异常。
 */

declare(strict_types=1);

// 允许本文件被单独引入；已由 config.php 加载时不会重复执行。
require_once dirname(__DIR__) . '/config.php';

// 验证码有效期（秒）
define('CAPTCHA_TTL', 300);
// 验证码字符集（剔除 0/O/1/I/L 等易混字符）
define('CAPTCHA_CHARSET', '23456789ABCDEFGHJKMNPQRSTUVWXYZ');

/**
 * 生成验证码并写入 Session
 *
 * 调用方须先确保 session 已启动（secure_session_start()），否则写入不生效。
 *
 * @return string 明文验证码（仅用于绘图，不会回传给前端文本）
 */
function captcha_generate(int $length = 4): string
{
    $chars = CAPTCHA_CHARSET;
    $max   = strlen($chars) - 1;
    $code  = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $chars[random_int(0, $max)];
    }
    $_SESSION['login_captcha']     = $code;
    $_SESSION['login_captcha_exp'] = time() + CAPTCHA_TTL;
    return $code;
}

/**
 * 校验验证码（一次性：无论成败都会销毁当前答案）
 */
function captcha_verify(?string $input): bool
{
    $answer = (string)($_SESSION['login_captcha'] ?? '');
    $exp    = (int)($_SESSION['login_captcha_exp'] ?? 0);
    unset($_SESSION['login_captcha'], $_SESSION['login_captcha_exp']);

    if ($answer === '' || $exp < time()) {
        return false;
    }
    $input = strtoupper(trim((string)$input));
    return $input !== '' && strlen($input) === strlen($answer) && hash_equals($answer, $input);
}

/**
 * 查找可用字体；找不到返回 null（回退 GD 内置字体）
 */
function captcha_font_path(): ?string
{
    static $done = false;
    static $path = null;
    if ($done) {
        return $path;
    }
    $done = true;

    // 本文件位于 lib/ 下，字体在项目根 assets/ 中，须走 NAV_ROOT 定位。
    $candidates = [
        NAV_ROOT . '/assets/fonts/captcha.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
        '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
        '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
    ];
    foreach ($candidates as $file) {
        if (is_file($file)) {
            $path = $file;
            return $path;
        }
    }
    return null;
}

/**
 * 直接输出验证码 PNG（生成与绘制一体，调用方无需再处理）
 */
function captcha_png(): void
{
    // 丢弃此前任何杂散输出（warning / notice），确保 PNG 数据流纯净
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    @ini_set('display_errors', '0');

    if (!headers_sent()) {
        header('Content-Type: image/png');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    if (!function_exists('imagecreatetruecolor')) {
        header('Content-Type: text/plain; charset=utf-8');
        echo ('GD ' . t('扩展未启用，无法生成验证码。'));
        return;
    }

    $code = captcha_generate(4);
    $w    = 132;
    $h    = 44;

    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 245, 247, 250));

    // 干扰点
    for ($i = 0; $i < 90; $i++) {
        $c = imagecolorallocate($im, random_int(180, 226), random_int(180, 226), random_int(180, 226));
        imagesetpixel($im, random_int(0, $w - 1), random_int(0, $h - 1), $c);
    }
    // 干扰线
    for ($i = 0; $i < 4; $i++) {
        $c = imagecolorallocate($im, random_int(150, 210), random_int(150, 210), random_int(150, 210));
        imageline($im, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), $c);
    }

    $font  = captcha_font_path();
    $len   = strlen($code);
    $step  = (int)(($w - 24) / $len);
    for ($i = 0; $i < $len; $i++) {
        $ch    = $code[$i];
        $x     = 14 + $i * $step + random_int(-2, 2);
        $y     = random_int(26, 34);
        $color = imagecolorallocate($im, random_int(30, 90), random_int(60, 120), random_int(120, 190));

        if ($font !== null) {
            imagettftext($im, 20, random_int(-14, 14), $x, $y, $color, $font, $ch);
        } else {
            imagestring($im, 5, $x, (int)(($h - 15) / 2), $ch, $color);
        }
    }

    imagerectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, 224, 228, 235));
    imagepng($im);
    imagedestroy($im);
}
