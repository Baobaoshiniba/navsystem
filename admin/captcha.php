<?php
/**
 * 后台 - 图形验证码
 *
 * 输出 4 位验证码 PNG，答案写入 Session（一次性、300 秒有效）。
 * 由 admin/login.php 内 <img> 直接引用；点击图片重新请求即换一张。
 */

declare(strict_types=1);

// 输出缓冲：任何杂散输出都会被 captcha_png() 丢弃，避免破坏 PNG 数据流
ob_start();
@ini_set('display_errors', '0');

require __DIR__ . '/../config.php';

secure_session_start();
captcha_png();
