<?php
require_once __DIR__ . '/db.php';

function admin_auth(): bool {
    $otoken = $_GET['otoken'] ?? '';
    if ($otoken) {
        $s = db()->prepare('SELECT * FROM admin_tokens WHERE onetime_token=:t AND onetime_used=0');
        $s->bindValue(':t', $otoken);
        $row = $s->execute()->fetchArray(SQLITE3_ASSOC);
        if ($row) {
            $cookie_token = bin2hex(random_bytes(32));
            $s2 = db()->prepare('UPDATE admin_tokens SET onetime_used=1, cookie_token=:ct WHERE id=:id');
            $s2->bindValue(':ct', $cookie_token);
            $s2->bindValue(':id', $row['id'], SQLITE3_INTEGER);
            $s2->execute();
            setcookie('admin_session', $cookie_token, [
                'expires'  => time() + 60*60*24*365*10,
                'path'     => '/support/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => true,
            ]);
            $_COOKIE['admin_session'] = $cookie_token;
            header('Location: ' . SITE_URL . '/admin.php');
            exit;
        }
        return false;
    }
    $cookie_token = $_COOKIE['admin_session'] ?? '';
    if (!$cookie_token) return false;
    $s = db()->prepare('SELECT id FROM admin_tokens WHERE cookie_token=:ct AND onetime_used=1');
    $s->bindValue(':ct', $cookie_token);
    return (bool)$s->execute()->fetchArray(SQLITE3_ASSOC);
}

function is_admin(): bool {
    $cookie_token = $_COOKIE['admin_session'] ?? '';
    if (!$cookie_token) return false;
    $s = db()->prepare('SELECT id FROM admin_tokens WHERE cookie_token=:ct AND onetime_used=1');
    $s->bindValue(':ct', $cookie_token);
    return (bool)$s->execute()->fetchArray(SQLITE3_ASSOC);
}
