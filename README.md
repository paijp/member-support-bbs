# member-support-bbs

会員サポート掲示板システムです。主催者が会員のメールアドレスを登録し、招待メールを送信します。会員はメール内のURLをクリックしてアクセスします。

> **このコードは Claude Sonnet (Anthropic) によってすべて生成されました。**

## 機能

- 会員をメールアドレスで招待（トークンつきURL）
- チャンネル制の掲示板（勉強会の質問 / 新着情報 / 参加者のご紹介）
- チャンネルごとに投稿権限を設定可能（主催者のみ or 会員も投稿可）
- 記事・コメントの投稿・削除（記事削除時はコメントも一括削除）
- 主催者が記事投稿時に「全員にメールを送る」チェックボックス
- 主催者がコメント投稿時に「記事を書いた人にメールを送る」チェックボックス
- 未アクセスが続く会員へのメール配信を自動停止（デフォルト4回）
- 主催者認証はワンタイムURL + 無期限cookie（パスワードなし）
- URLハッシュによるタブ状態保持（リロードで同タブを維持）

## 技術スタック

- **サーバー**: Linux VPS (Rocky Linux 9 等)
- **バックエンド**: PHP 8 + SQLite3
- **フロントエンド**: Vanilla JS（依存ライブラリなし）
- **Webサーバー**: Nginx + php-fpm
- **メール**: `mail()` 関数 / sendmail

## ファイル構成

```
/var/www/example.com/support/   ← Webルート（nginx公開）
  index.php                     # 会員向けページ（トークン/cookie認証）
  admin.php                     # 主催者管理画面
  api.php                       # JSON API
  style.css                     # スタイルシート

/var/www/scripts/example.com/support/   ← 内部PHP（httpdから見えない）
  db.php                        # DB接続・共通関数
  admin_auth.php                # 主催者認証ロジック
  issue_admin_url.php           # 主催者URLワンタイム発行

/var/www/db/example.com/        ← データベース（httpdから見えない、g+w）
  support.db                    # SQLiteデータベース
```

## セットアップ

### 1. PHP・php-fpm のインストール

```bash
# Rocky Linux / RHEL 系
dnf install -y php php-fpm php-pdo php-sqlite3
systemctl enable --now php-fpm
```

### 2. ディレクトリ作成とファイルの配置

```bash
# Webルート
mkdir -p /var/www/example.com/support
chown -R nginx:nginx /var/www/example.com/support

# 内部スクリプト（root所有、nginxグループ読み取り可）
mkdir -p /var/www/scripts/example.com/support
chown -R root:nginx /var/www/scripts/example.com/support
chmod 750 /var/www/scripts/example.com/support
# ファイルコピー後
chmod 640 /var/www/scripts/example.com/support/*.php

# データベース（nginx書き込み可、グループ書き込み可）
mkdir -p /var/www/db/example.com
chown nginx:nginx /var/www/db/example.com
chmod 775 /var/www/db/example.com
```

### 3. db.php の設定を編集

```php
define('DB_PATH',  '/var/www/db/example.com/support.db'); // DBパス
define('SITE_URL', 'https://example.com/support');         // サイトURL
define('MAIL_FROM','info@example.com');                    // 差出人メールアドレス
define('MAIL_STOP_THRESHOLD', 4);                          // 未アクセス何回でメール停止か
```

### 4. Webルート側PHPのrequire_onceを絶対パスに設定

`index.php` / `admin.php` / `api.php` の先頭：

```php
require_once '/var/www/scripts/example.com/support/admin_auth.php';
```

`admin_auth.php` / `issue_admin_url.php` の先頭：

```php
require_once '/var/www/scripts/example.com/support/db.php';
```

### 5. Nginx 設定

```nginx
location /support/ {
    root /var/www/example.com;
    index index.php;
    try_files $uri $uri/ /support/index.php?$query_string;

    location ~ \.php$ {
        fastcgi_pass unix:/run/php-fpm/www.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

`/var/www/scripts/` および `/var/www/db/` はnginxのWebルート外のため、HTTPから直接アクセスできません。

### 6. php-fpm のユーザー設定

`/etc/php-fpm.d/www.conf` でnginxと同じユーザーを指定します。

```ini
user = nginx
group = nginx
```

セッションディレクトリのオーナーも合わせます。

```bash
chown -R nginx:nginx /var/lib/php/session
```

### 7. DBの初期化

初回アクセス時に自動でテーブルが作成されます。DBファイルのオーナーをnginxに設定してください。

```bash
# 初回アクセス後
chown nginx:nginx /var/www/db/example.com/support.db
chmod 664 /var/www/db/example.com/support.db
```

## 主催者アクセスURLの発行

主催者の管理画面はパスワードレスです。ワンタイムURLを発行してブラウザで開くと、無期限のcookieが発行されます。以降はURLなしで `https://example.com/support/admin.php` に直接アクセスできます。

新しいURLを発行すると、既存のcookieは自動的に無効化されます（同時ログイン1人）。

### SSH で発行する

```bash
ssh your-server 'php /var/www/scripts/example.com/support/issue_admin_url.php'
```

出力されたURLをブラウザで開いてください。

### Claude との会話で発行する（SSH MCP連携）

[Claude MCP SSH connector](https://github.com/modelcontextprotocol/servers) などのSSH MCPコネクタをClaudeに接続している場合、会話で次のように言うだけで発行できます。

> 「主催者アクセスURLを発行して」

ClaudeがSSH経由でVPS上の `php /var/www/scripts/example.com/support/issue_admin_url.php` を実行してURLを返します。

`issue_admin_url.php` はHTTPアクセスを403で拒否するため、Web経由でのURL発行はできません。

## データベース

SQLiteファイルは `/var/www/db/example.com/support.db`（Webルート外）に配置するため、Nginxから直接ダウンロードできません。

| テーブル | 内容 |
|---|---|
| `members` | 会員（名前・メール・トークン・未アクセスカウンタ） |
| `articles` | 記事（チャンネル・タイトル・本文・投稿者） |
| `comments` | コメント（記事ID・本文・投稿者） |
| `admin_tokens` | 主催者ワンタイムトークン・cookieトークン |

## メール自動停止の仕組み

全員宛メール（記事投稿時）を送るたびに、各会員の `no_access_count` が +1 されます。会員がサイトにアクセスするとカウンタは 0 にリセットされます。

| カウンタ | 動作 |
|---|---|
| 0〜1 | 通常送信 |
| 2 | 「あと2回…」の警告文を追加して送信 |
| 3 | 「【最後のお知らせです】…」の警告文を追加して送信 |
| 4以上 | 送信スキップ |

## Credits

This project was entirely written by **Claude Sonnet** (Anthropic).  
https://claude.ai

## License

MIT License — see [LICENSE](LICENSE)
