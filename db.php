<?php
define('DB_PATH',  '/var/lib/support/support.db'); // ★ 環境に合わせて変更
define('SITE_URL', 'https://example.com/support'); // ★ 環境に合わせて変更
define('MAIL_FROM','info@example.com');             // ★ 差出人メールアドレス
define('MAIL_STOP_THRESHOLD', 4);                  // 何回連続未アクセスでメール停止か

const CHANNELS = [
    'qa'      => ['name' => '勉強会の質問',   'member_can_post' => true],
    'news'    => ['name' => '新着情報',       'member_can_post' => false],
    'members' => ['name' => '参加者のご紹介', 'member_can_post' => false],
];

function db(): SQLite3 {
    static $db = null;
    if ($db) return $db;
    if (!is_dir(dirname(DB_PATH))) mkdir(dirname(DB_PATH), 0755, true);
    $db = new SQLite3(DB_PATH);
    $db->enableExceptions(true);
    $db->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;');
    $db->exec('
        CREATE TABLE IF NOT EXISTS members (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            name            TEXT    NOT NULL,
            email           TEXT    NOT NULL UNIQUE,
            token           TEXT    NOT NULL UNIQUE,
            active          INTEGER NOT NULL DEFAULT 1,
            no_access_count INTEGER NOT NULL DEFAULT 0,
            created_at      TEXT    NOT NULL DEFAULT ""
        );
        CREATE TABLE IF NOT EXISTS articles (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            channel          TEXT    NOT NULL,
            title            TEXT    NOT NULL,
            body             TEXT    NOT NULL,
            author_member_id INTEGER,
            created_at       TEXT    NOT NULL DEFAULT ""
        );
        CREATE TABLE IF NOT EXISTS comments (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            article_id       INTEGER NOT NULL REFERENCES articles(id) ON DELETE CASCADE,
            body             TEXT    NOT NULL,
            author_member_id INTEGER,
            created_at       TEXT    NOT NULL DEFAULT ""
        );
        CREATE TABLE IF NOT EXISTS admin_tokens (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            onetime_token TEXT UNIQUE,
            cookie_token  TEXT,
            onetime_used  INTEGER DEFAULT 0,
            created_at    TEXT
        );
    ');
    try { $db->exec('ALTER TABLE members ADD COLUMN no_access_count INTEGER NOT NULL DEFAULT 0'); } catch (Exception $e) {}
    return $db;
}

function ts(): string { return date('Y-m-d H:i:s'); }

function json_out(mixed $d, int $s = 200): never {
    http_response_code($s);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function member_from_token(string $token): ?array {
    if (!$token) return null;
    $s = db()->prepare('SELECT * FROM members WHERE token=:t AND active=1');
    $s->bindValue(':t', $token);
    $r = $s->execute()->fetchArray(SQLITE3_ASSOC);
    return $r ?: null;
}

function member_accessed(int $id): void {
    $s = db()->prepare('UPDATE members SET no_access_count=0 WHERE id=:id');
    $s->bindValue(':id', $id, SQLITE3_INTEGER);
    $s->execute();
}

function all_members(): array {
    $r = db()->query('SELECT * FROM members WHERE active=1 ORDER BY id');
    $out = [];
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $out[] = $row;
    return $out;
}

function mail_to_all(string $subject, string $body_tpl): void {
    foreach (all_members() as $m) {
        $count = (int)$m['no_access_count'];
        if ($count >= MAIL_STOP_THRESHOLD) continue;
        $extra = match($count) {
            MAIL_STOP_THRESHOLD - 2 =>
                "\n\nあと2回、リンクを開かないでおくと、このメールが送られなくなります。",
            MAIL_STOP_THRESHOLD - 1 =>
                "\n\n【最後のお知らせです】リンクを開かないでおくと、もうこのメールは送られません。",
            default => '',
        };
        send_one($m, $subject, $body_tpl, $extra);
        $s = db()->prepare('UPDATE members SET no_access_count=no_access_count+1 WHERE id=:id');
        $s->bindValue(':id', (int)$m['id'], SQLITE3_INTEGER);
        $s->execute();
    }
}

function mail_to_member(int $id, string $subject, string $body_tpl): void {
    $s = db()->prepare('SELECT * FROM members WHERE id=:id AND active=1');
    $s->bindValue(':id', $id, SQLITE3_INTEGER);
    $m = $s->execute()->fetchArray(SQLITE3_ASSOC);
    if ($m) send_one($m, $subject, $body_tpl);
}

function send_one(array $m, string $subject, string $body_tpl, string $extra = ''): void {
    $url  = SITE_URL . '/index.php?token=' . urlencode($m['token']) . '&';
    $body = $m['name'] . " さん\n\n"
          . ltrim(preg_replace('/^\{NAME\}\s*さん\s*/u', '', $body_tpl))
          . "\n\nこちらのリンクからご覧ください。\nサポートサイト: " . $url
          . $extra
          . "\n\n---\n一般論ですが、メールで届いたリンクは十分注意し、うかつに重要な情報を入力しないでください。";
    $enc = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $hdr = "From: " . MAIL_FROM . "\r\n"
         . "Content-Type: text/plain; charset=UTF-8\r\n";
    mail($m['email'], $enc, $body, $hdr);
}
