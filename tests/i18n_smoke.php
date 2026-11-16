<?php
/** Run: php tests/i18n_smoke.php en (or zh-CN). Uses a disposable database only. */
declare(strict_types=1);
$_GET['lang'] = $argv[1] ?? 'en';
require __DIR__ . '/../config.php';

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$file = tempnam(sys_get_temp_dir(), 'nav-i18n-');
try {
    $old = new PDO('sqlite:' . $file);
    $old->exec("CREATE TABLE categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, icon TEXT DEFAULT '', sort_order INTEGER DEFAULT 0, is_active INTEGER DEFAULT 1, created_at TEXT DEFAULT '');
        CREATE TABLE links (id INTEGER PRIMARY KEY AUTOINCREMENT, category_id INTEGER NOT NULL, title TEXT NOT NULL, url TEXT NOT NULL, description TEXT DEFAULT '', icon TEXT DEFAULT '', sort_order INTEGER DEFAULT 0, is_active INTEGER DEFAULT 1, click_count INTEGER DEFAULT 0, created_at TEXT DEFAULT '', FOREIGN KEY(category_id) REFERENCES categories(id) ON DELETE CASCADE);
        INSERT INTO categories (name) VALUES ('中文分类');
        INSERT INTO links (category_id,title,url,click_count) VALUES (1,'中文链接','https://example.com',7);");
    $old = null;
    $db_config['file'] = $file;
    $pdo = db();
    init_database($pdo); // Repeat the migration to prove it is idempotent.
    check((int)$pdo->query('SELECT click_count FROM links WHERE id=1')->fetchColumn() === 7, 'Migration changed existing clicks');
    check($pdo->query('SELECT name FROM categories WHERE id=1')->fetchColumn() === '中文分类', 'Migration changed existing content');
    check($pdo->query('SELECT name_en FROM categories WHERE id=1')->fetchColumn() === '', 'English migration default missing');
    check(localized_field(['name' => '中文分类'], 'name') === '中文分类', 'Missing English must fall back');
    $expected = locale() === 'en' ? 'Engineering' : '中文分类';
    check(localized_field(['name' => '中文分类', 'name_en' => 'Engineering'], 'name') === $expected, 'Incorrect localized content');
    check(t('搜索') === (locale() === 'en' ? 'Search' : '搜索'), 'Incorrect interface translation');
    check(t('unknown.key') === 'unknown.key', 'Unknown messages must remain readable');
    check(t('{count}', ['count' => 3]) === '3', 'Parameter substitution failed');
    save_setting('site_name', '中文公司');
    save_setting('site_name_en', 'Example Company');
    check(setting('site_name') === (locale() === 'en' ? 'Example Company' : '中文公司'), 'Incorrect localized site settings');
    check(raw_setting('site_name') === '中文公司', 'Editing must read original content');
    $_GET['cat'] = '3';
    check(strpos(language_switch_html(), 'cat=3') !== false, 'Language switch lost query context');
    check(strpos(jt('</script>'), '</script>') === false, 'JavaScript message escaping failed');
    check(item_count(1) === (locale() === 'en' ? '1 item' : '1 项'), 'Incorrect singular count');
    $pdo->exec('DELETE FROM links; DELETE FROM categories;');
    seed_demo_data($pdo);
    check((int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn() === 4, 'Expected four demo categories');
    check((int)$pdo->query('SELECT COUNT(*) FROM links')->fetchColumn() === 100, 'Expected one hundred demo links');
    check((int)$pdo->query("SELECT COUNT(*) FROM links WHERE title_en = '' OR description_en = ''")->fetchColumn() === 0, 'Demo English content missing');
    check((int)$pdo->query('SELECT SUM(click_count) FROM links')->fetchColumn() === 0, 'Demo clicks must start at zero');
    echo 'PASS: ' . locale() . " migration, content fallback, settings, translation and escaping\n";
} finally {
    $pdo = null;
    foreach (['', '-wal', '-shm'] as $suffix) if (is_file($file . $suffix)) unlink($file . $suffix);
}
