<?php
// CLI または localhost(127.0.0.1) からのみ実行可能
// nginx 設定で HTTP アクセスは 403 を返すよう設定すること
if (PHP_SAPI !== 'cli') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($ip, ['127.0.0.1', '::1'])) {
        http_response_code(403); exit;
    }
}

require_once __DIR__ . '/db.php';

// 既存トークンを全削除（旧 cookie を無効化）
db()->exec('DELETE FROM admin_tokens');

$onetime = bin2hex(random_bytes(24));
$s = db()->prepare('INSERT INTO admin_tokens (onetime_token, cookie_token, created_at) VALUES (:ot, :ct, :ts)');
$s->bindValue(':ot', $onetime);
$s->bindValue(':ct', '');
$s->bindValue(':ts', date('Y-m-d H:i:s'));
$s->execute();

$url = SITE_URL . '/admin.php?otoken=' . $onetime;
// CLI 実行後に DB オーナーを web サーバーユーザーに戻す
@chown(DB_PATH, "nginx");
@chmod(DB_PATH, 0664);
echo $url . "\n";
