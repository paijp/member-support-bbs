<?php
require_once __DIR__ . '/admin_auth.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

if ($action === 'whoami') {
    $token = $input['token'] ?? '';
    $m = member_from_token($token);
    if ($m) json_out(['ok' => true, 'name' => $m['name'], 'id' => $m['id']]);
    json_out(['ok' => false], 401);
}

if ($action === 'articles') {
    $ch = $_GET['channel'] ?? '';
    $where = $ch ? "WHERE a.channel='" . SQLite3::escapeString($ch) . "'" : '';
    $order = $ch
        ? "ORDER BY a.id DESC"
        : "ORDER BY COALESCE((SELECT MAX(c2.created_at) FROM comments c2 WHERE c2.article_id=a.id), a.created_at) DESC";
    $rows = [];
    $res = db()->query("
        SELECT a.*, m.name as author_name,
               (SELECT COUNT(*) FROM comments c WHERE c.article_id=a.id) as comment_count
        FROM articles a LEFT JOIN members m ON m.id = a.author_member_id
        $where $order
    ");
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;
    json_out($rows);
}

if ($action === 'comments') {
    $aid = (int)($_GET['article_id'] ?? 0);
    $rows = [];
    $res = db()->query("
        SELECT c.*, m.name as author_name FROM comments c
        LEFT JOIN members m ON m.id = c.author_member_id
        WHERE c.article_id = $aid ORDER BY c.id ASC
    ");
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;
    json_out($rows);
}

if ($action === 'post_article' && $method === 'POST') {
    $channel   = $input['channel'] ?? '';
    $title     = trim($input['title'] ?? '');
    $body      = trim($input['body'] ?? '');
    $token     = $input['token'] ?? '';
    $send_mail = !empty($input['send_mail']);

    if (!isset(CHANNELS[$channel]) || !$title || !$body) json_out(['error' => 'invalid'], 400);

    $ch_info = CHANNELS[$channel];
    $member  = $token ? member_from_token($token) : null;
    $admin   = is_admin();

    if (!$admin && !$ch_info['member_can_post']) json_out(['error' => '投稿権限がありません'], 403);
    if (!$admin && !$member) json_out(['error' => '認証エラー'], 401);

    $author_id = $member['id'] ?? null;
    $s = db()->prepare('INSERT INTO articles (channel,title,body,author_member_id,created_at) VALUES (:ch,:ti,:bo,:au,:ts)');
    $s->bindValue(':ch', $channel);
    $s->bindValue(':ti', $title);
    $s->bindValue(':bo', $body);
    $s->bindValue(':au', $author_id, $author_id ? SQLITE3_INTEGER : SQLITE3_NULL);
    $s->bindValue(':ts', ts());
    $s->execute();
    $new_id = db()->lastInsertRowID();

    if ($admin && $send_mail) {
        mail_to_all("【{$ch_info['name']}】{$title}", "{NAME} さん\n\n新しい記事が投稿されました。\n\n■ {$title}\n{$body}");
    }
    json_out(['ok' => true, 'id' => $new_id]);
}

if ($action === 'post_comment' && $method === 'POST') {
    $aid       = (int)($input['article_id'] ?? 0);
    $body      = trim($input['body'] ?? '');
    $token     = $input['token'] ?? '';
    $send_mail = !empty($input['send_mail']);

    if (!$aid || !$body) json_out(['error' => 'invalid'], 400);

    $member = $token ? member_from_token($token) : null;
    $admin  = is_admin();
    if (!$admin && !$member) json_out(['error' => '認証エラー'], 401);

    $author_id = $member['id'] ?? null;
    $s = db()->prepare('INSERT INTO comments (article_id,body,author_member_id,created_at) VALUES (:ai,:bo,:au,:ts)');
    $s->bindValue(':ai', $aid, SQLITE3_INTEGER);
    $s->bindValue(':bo', $body);
    $s->bindValue(':au', $author_id, $author_id ? SQLITE3_INTEGER : SQLITE3_NULL);
    $s->bindValue(':ts', ts());
    $s->execute();

    if ($admin && $send_mail) {
        $art = db()->querySingle("SELECT * FROM articles WHERE id=$aid", true);
        if ($art && $art['author_member_id']) {
            $ch_name = CHANNELS[$art['channel']]['name'] ?? '';
            mail_to_member((int)$art['author_member_id'],
                "【{$ch_name}】コメントが届きました",
                "{NAME} さん\n\n「{$art['title']}」にコメントが届きました。\n\n{$body}");
        }
    }
    json_out(['ok' => true]);
}

if (!is_admin()) json_out(['error' => '認証エラー'], 401);

if ($action === 'list_members') { json_out(all_members()); }

if ($action === 'add_member' && $method === 'POST') {
    $name  = trim($input['name'] ?? '');
    $email = trim($input['email'] ?? '');
    if (!$name || !$email) json_out(['error' => 'invalid'], 400);
    $token = bin2hex(random_bytes(16));
    try {
        $s = db()->prepare('INSERT INTO members (name,email,token,created_at) VALUES (:n,:e,:t,:ts)');
        $s->bindValue(':n', $name); $s->bindValue(':e', $email);
        $s->bindValue(':t', $token); $s->bindValue(':ts', ts());
        $s->execute();
        $id = db()->lastInsertRowID();
        $url = SITE_URL . '/index.php?token=' . urlencode($token);
        $subj = 'サポートサイトへご招待';
        $body = "{$name} さん\n\nサポートサイトにご招待します。\n以下のURLからアクセスしてください。\n\n{$url}\n\n※このURLはあなた専用です。";
        mail($email, '=?UTF-8?B?' . base64_encode($subj) . '?=', $body,
             "From: " . MAIL_FROM . "\r\nContent-Type: text/plain; charset=UTF-8\r\n");
        json_out(['ok' => true, 'id' => $id, 'token' => $token]);
    } catch (Exception $e) {
        json_out(['error' => 'メールアドレスが重複しています'], 409);
    }
}

if ($action === 'delete_member' && $method === 'POST') {
    db()->exec("UPDATE members SET active=0 WHERE id=" . (int)($input['id']??0));
    json_out(['ok' => true]);
}

if ($action === 'delete_article' && $method === 'POST') {
    db()->exec("DELETE FROM articles WHERE id=" . (int)($input['id']??0));
    json_out(['ok' => true]);
}

if ($action === 'delete_comment' && $method === 'POST') {
    db()->exec("DELETE FROM comments WHERE id=" . (int)($input['id']??0));
    json_out(['ok' => true]);
}

json_out(['error' => 'not found'], 404);
