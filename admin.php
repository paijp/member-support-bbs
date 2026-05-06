<?php
require_once __DIR__ . '/admin_auth.php';

$is_admin = admin_auth();

if (!$is_admin) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><title>アクセスエラー</title>
    <style>body{font-family:sans-serif;text-align:center;padding:80px;color:#666;background:#f4f5f7}
    .box{background:#fff;border:1px solid #e0e0e0;border-radius:12px;padding:40px;display:inline-block;max-width:360px}
    h2{margin-bottom:12px;color:#333}p{font-size:14px;line-height:1.7}</style></head>
    <body><div class="box"><h2>🔒 アクセスできません</h2>
    <p>セッションが無効です。<br>SSH または Claude との会話で<br>「主催者アクセスURLを発行して」とお伝えください。</p></div></body></html>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>管理画面 — サポートサイト</title>
<link rel="stylesheet" href="style.css">
</head>
<body>

<div class="topbar">
  <div class="logo">📚 サポートサイト — 管理画面</div>
  <div style="display:flex;gap:8px">
    <a href="index.php" class="btn">サイトを見る</a>
  </div>
</div>

<div class="admin-wrap">

  <div class="admin-section">
    <h2>会員管理</h2>
    <div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap">
      <input type="text" id="new-name" placeholder="氏名" style="border:1px solid #ddd;border-radius:8px;padding:8px 12px;font-size:14px;flex:1;min-width:120px">
      <input type="email" id="new-email" placeholder="メールアドレス" style="border:1px solid #ddd;border-radius:8px;padding:8px 12px;font-size:14px;flex:2;min-width:180px">
      <button class="btn btn-primary" onclick="addMember()">＋ 追加して招待メール送信</button>
    </div>
    <div id="member-msg"></div>
    <table>
      <thead><tr><th>氏名</th><th>メールアドレス</th><th>登録日</th><th>アクセスURL</th><th></th></tr></thead>
      <tbody id="member-table"></tbody>
    </table>
  </div>

  <div class="admin-section">
    <h2>記事管理</h2>
    <table>
      <thead><tr><th>日時</th><th>チャンネル</th><th>タイトル</th><th>投稿者</th><th></th></tr></thead>
      <tbody id="article-table"></tbody>
    </table>
  </div>

</div>

<script>
const API = 'api.php';
async function api(action, data={}, method='GET') {
  if (method === 'GET') {
    const r = await fetch(API + '?' + new URLSearchParams({action, ...data}));
    return r.json();
  }
  const r = await fetch(API + '?action=' + action, {
    method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(data)
  });
  return r.json();
}
function esc(s){return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;')}

async function loadMembers() {
  const members = await api('list_members');
  const origin = location.origin + '/support/';
  document.getElementById('member-table').innerHTML = members.map(m => `
    <tr id="mrow-${m.id}">
      <td>${esc(m.name)}</td>
      <td>${esc(m.email)}</td>
      <td>${m.created_at?.slice(0,10)??''}</td>
      <td><button class="btn" style="padding:4px 10px;font-size:12px" onclick="copyUrl('${origin}index.php?token=${encodeURIComponent(m.token)}')">URLをコピー</button></td>
      <td><button class="btn btn-danger" style="padding:4px 10px;font-size:12px" onclick="deleteMember(${m.id})">削除</button></td>
    </tr>`).join('') || '<tr><td colspan="5" style="color:#aaa;text-align:center;padding:20px">会員はいません</td></tr>';
}

function copyUrl(url) {
  navigator.clipboard.writeText(url).then(() => alert('URLをコピーしました'));
}

async function addMember() {
  const name  = document.getElementById('new-name').value.trim();
  const email = document.getElementById('new-email').value.trim();
  if (!name || !email) return alert('氏名とメールアドレスを入力してください');
  const r = await api('add_member', {name, email}, 'POST');
  const msg = document.getElementById('member-msg');
  if (r.ok) {
    msg.innerHTML = '<div class="msg msg-ok">招待メールを送信しました</div>';
    document.getElementById('new-name').value = '';
    document.getElementById('new-email').value = '';
    await loadMembers();
    setTimeout(() => msg.innerHTML = '', 4000);
  } else {
    msg.innerHTML = '<div class="msg msg-err">' + esc(r.error) + '</div>';
  }
}

async function deleteMember(id) {
  if (!confirm('この会員を削除しますか？')) return;
  await api('delete_member', {id}, 'POST');
  document.getElementById('mrow-' + id)?.remove();
}

async function loadArticles() {
  const articles = await api('articles', {});
  const CHANNELS = <?= json_encode(CHANNELS) ?>;
  document.getElementById('article-table').innerHTML = articles.map(a => `
    <tr id="arow-${a.id}">
      <td style="white-space:nowrap">${a.created_at?.slice(0,10)??''}</td>
      <td>${esc(CHANNELS[a.channel]?.name ?? a.channel)}</td>
      <td>${esc(a.title)}</td>
      <td>${a.author_member_id ? esc(a.author_name||'会員') : '主催者'}</td>
      <td><button class="btn btn-danger" style="padding:4px 10px;font-size:12px" onclick="delArticle(${a.id})">削除</button></td>
    </tr>`).join('') || '<tr><td colspan="5" style="color:#aaa;text-align:center;padding:20px">記事はありません</td></tr>';
}

async function delArticle(id) {
  if (!confirm('この記事を削除しますか？')) return;
  await api('delete_article', {id}, 'POST');
  document.getElementById('arow-' + id)?.remove();
}

(async () => {
  await loadMembers();
  await loadArticles();
})();
</script>
</body>
</html>
