<?php
require_once __DIR__ . '/admin_auth.php';

$admin = admin_auth();

if (isset($_GET['token'])) {
    $token = $_GET['token'];
    $member = member_from_token($token);
    if ($member) {
        setcookie('member_token', $token, [
            'expires'  => time() + 60*60*24*365*10,
            'path'     => '/support/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => true,
        ]);
        header('Location: ' . SITE_URL . '/index.php');
        exit;
    } else {
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><title>アクセスエラー</title>
        <style>body{font-family:sans-serif;text-align:center;padding:80px;color:#666;background:#f4f5f7}
        .box{background:#fff;border:1px solid #e0e0e0;border-radius:12px;padding:40px;display:inline-block;max-width:360px}
        h2{margin-bottom:12px;color:#333}p{font-size:14px;line-height:1.7}</style></head>
        <body><div class="box"><h2>🔒 URLが無効です</h2>
        <p>招待メールのURLを再度ご確認ください。</p></div></body></html>';
        exit;
    }
}

$token  = $_COOKIE['member_token'] ?? '';
$member = $token ? member_from_token($token) : null;
if ($member) member_accessed((int)$member['id']);

if (!$member && !$admin) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><title>アクセスエラー</title>
    <style>body{font-family:sans-serif;text-align:center;padding:80px;color:#666;background:#f4f5f7}
    .box{background:#fff;border:1px solid #e0e0e0;border-radius:12px;padding:40px;display:inline-block;max-width:360px}
    h2{margin-bottom:12px;color:#333}p{font-size:14px;line-height:1.7}</style></head>
    <body><div class="box"><h2>🔒 アクセスできません</h2>
    <p>招待メールのURLからアクセスしてください。</p></div></body></html>';
    exit;
}

$viewer_name = $admin ? '主催者' : $member['name'];
$viewer_id   = $admin ? 0 : (int)$member['id'];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>サポートサイト</title>
<link rel="stylesheet" href="style.css">
</head>
<body>

<div class="topbar">
  <div class="logo">📚 勉強会サポート</div>
  <div class="user-info">
    <div class="avatar"><?= mb_substr($viewer_name, 0, 1) ?></div>
    <span><?= htmlspecialchars($viewer_name) ?>さん</span>
    <?php if ($admin): ?>
      <a href="admin.php" class="btn" style="padding:4px 10px;font-size:12px">管理</a>
    <?php endif; ?>
  </div>
</div>

<div class="tabs">
  <button class="tab active" onclick="showTab('qa')">勉強会の質問</button>
  <button class="tab" onclick="showTab('news')">新着情報</button>
  <button class="tab" onclick="showTab('members')">参加者のご紹介</button>
  <button class="tab" onclick="showTab('all')">すべての記事</button>
</div>

<div class="content">
  <div id="panel-qa"      class="panel"></div>
  <div id="panel-news"    class="panel" style="display:none"></div>
  <div id="panel-members" class="panel" style="display:none"></div>
  <div id="panel-all"     class="panel" style="display:none"></div>
</div>

<script>
const IS_ADMIN = <?= $admin ? 'true' : 'false' ?>;
const VIEWER_ID = <?= $viewer_id ?>;
const MEMBER_TOKEN = <?= json_encode($token) ?>;
const CHANNELS = <?= json_encode(CHANNELS) ?>;
const API = 'api.php';

async function api(action, data={}, method='GET') {
  if (method === 'GET') {
    const r = await fetch(API + '?' + new URLSearchParams({action, ...data}));
    return r.json();
  }
  const r = await fetch(API + '?action=' + action, {
    method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(data)
  });
  return r.json();
}

function esc(s) { return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function avatarChar(name) { return name ? name.charAt(0) : '？'; }

function renderCommentForm(articleId, articleAuthorId, prefix='a') {
  if (!IS_ADMIN && VIEWER_ID === 0) return '';
  const pid = prefix + articleId;
  const mailCheck = IS_ADMIN && articleAuthorId
    ? `<label class="checkbox-label"><input type="checkbox" id="cmail-${pid}" checked> 記事を書いた人にメールを送る</label>`
    : '<span></span>';
  return `<div class="comment-form">
    <textarea id="ctxt-${pid}" placeholder="コメントを入力…"></textarea>
    <div class="form-actions">
      ${mailCheck}
      <button class="btn btn-primary" onclick="postComment(${articleId},'${pid}')">送信</button>
    </div>
  </div>`;
}

function renderArticle(a, prefix='a') {
  const isHost = !a.author_member_id;
  const authorName = isHost ? '主催者' : (a.author_name || '会員');
  const chName = CHANNELS[a.channel]?.name ?? '';
  const chBadge = prefix === 'all'
    ? `<span class="badge badge-blue" style="font-size:11px">${esc(chName)}</span>` : '';
  const pid = prefix + a.id;
  return `<div class="card" id="article-${pid}">
    <div class="article-meta">
      <div class="avatar" style="width:26px;height:26px;font-size:11px;${isHost?'background:#E1F5EE;color:#0F6E56':'background:#EEEDFE;color:#534AB7'}">${esc(avatarChar(authorName))}</div>
      <span class="author">${esc(authorName)}</span>
      ${isHost ? '<span class="host-tag">主催者</span>' : ''}
      ${chBadge}
      <span class="date">${a.created_at?.slice(0,10)??''}</span>
    </div>
    <div class="article-title" onclick="openArticle('${pid}')">${esc(a.title)}</div>
    <div class="article-body">${esc(a.body)}</div>
    <div class="article-footer">
      <button class="footer-btn" onclick="toggleComments('${pid}')">💬 ${a.comment_count??0}件のコメント</button>
      ${IS_ADMIN ? `<button class="footer-btn btn-danger" onclick="deleteArticle(${a.id},'${pid}')" style="margin-left:auto;font-size:12px">削除</button>` : ''}
    </div>
    <div id="comments-${pid}" style="display:none" class="comments-area">
      <div id="clist-${pid}"></div>
      ${renderCommentForm(a.id, a.author_member_id, prefix)}
    </div>
  </div>`;
}

async function toggleComments(pid) {
  const area = document.getElementById('comments-' + pid);
  if (area.style.display === 'none') { area.style.display = 'block'; await loadComments(pid); }
  else area.style.display = 'none';
}

async function openArticle(pid) {
  document.getElementById('comments-' + pid).style.display = 'block';
  await loadComments(pid);
}

function pidToArticleId(pid) { return parseInt(pid.replace(/^[a-z]+/, '')); }

async function loadComments(pid) {
  const articleId = pidToArticleId(pid);
  const data = await api('comments', {article_id: articleId});
  const list = document.getElementById('clist-' + pid);
  if (!data.length) { list.innerHTML = '<p style="font-size:13px;color:#aaa;margin-bottom:8px">まだコメントはありません</p>'; return; }
  list.innerHTML = data.map(c => {
    const isHost = !c.author_member_id;
    const name = isHost ? '主催者' : (c.author_name || '会員');
    return `<div class="comment" id="comment-${pid}-${c.id}">
      <div class="comment-meta">${esc(name)}${isHost?' <span class="host-tag">主催者</span>':''} · ${c.created_at?.slice(0,10)??''}
        ${IS_ADMIN ? `<button class="footer-btn btn-danger" style="display:inline;margin-left:10px;font-size:11px" onclick="deleteComment(${c.id},'${pid}')">削除</button>` : ''}
      </div>
      <div class="comment-body">${esc(c.body)}</div>
    </div>`;
  }).join('');
}

async function postComment(articleId, pid) {
  const txt = document.getElementById('ctxt-' + pid);
  const body = txt.value.trim();
  if (!body) return;
  const sendMail = IS_ADMIN ? (document.getElementById('cmail-' + pid)?.checked ?? false) : false;
  const r = await api('post_comment', {article_id: articleId, body, token: MEMBER_TOKEN, send_mail: sendMail}, 'POST');
  if (r.ok) { txt.value = ''; await loadComments(pid); }
  else alert(r.error || 'エラーが発生しました');
}

async function deleteArticle(id, pid) {
  if (!confirm('この記事を削除しますか？')) return;
  await api('delete_article', {id}, 'POST');
  document.getElementById('article-' + pid)?.remove();
}

async function deleteComment(id, pid) {
  if (!confirm('このコメントを削除しますか？')) return;
  await api('delete_comment', {id}, 'POST');
  await loadComments(pid);
}

function postFormHtml(channel) {
  const mailCheck = IS_ADMIN
    ? `<label class="checkbox-label"><input type="checkbox" id="pmail-${channel}" checked> 全員にメールを送る</label>`
    : '<span></span>';
  return `<div class="post-form" id="postform-${channel}" style="display:none">
    <input type="text" id="ptitle-${channel}" placeholder="タイトル">
    <textarea id="pbody-${channel}" placeholder="本文"></textarea>
    <div class="form-row">
      ${mailCheck}
      <div style="display:flex;gap:8px">
        <button class="btn" onclick="document.getElementById('postform-${channel}').style.display='none'">キャンセル</button>
        <button class="btn btn-primary" onclick="postArticle('${channel}')">投稿する</button>
      </div>
    </div>
  </div>`;
}

function togglePostForm(channel) {
  const f = document.getElementById('postform-' + channel);
  f.style.display = f.style.display === 'none' ? 'block' : 'none';
}

async function postArticle(channel) {
  const title = document.getElementById('ptitle-' + channel).value.trim();
  const body  = document.getElementById('pbody-' + channel).value.trim();
  if (!title || !body) return alert('タイトルと本文を入力してください');
  const sendMail = IS_ADMIN ? (document.getElementById('pmail-' + channel)?.checked ?? false) : false;
  const r = await api('post_article', {channel, title, body, token: MEMBER_TOKEN, send_mail: sendMail}, 'POST');
  if (r.ok) {
    document.getElementById('postform-' + channel).style.display = 'none';
    document.getElementById('ptitle-' + channel).value = '';
    document.getElementById('pbody-' + channel).value = '';
    await renderChannel(channel);
  } else alert(r.error || 'エラーが発生しました');
}

async function renderChannel(channel) {
  const panel = document.getElementById('panel-' + channel);
  const ch = CHANNELS[channel];
  const canPost = IS_ADMIN || ch.member_can_post;
  const badge = !ch.member_can_post ? '<span class="badge badge-amber">主催者のみ投稿可</span>' : '';
  const articles = await api('articles', {channel});
  const listHtml = articles.length ? articles.map(a => renderArticle(a)).join('') : '<div class="empty">まだ記事はありません</div>';
  panel.innerHTML = `
    <div class="channel-header">
      <div class="channel-title">${esc(ch.name)} ${badge}</div>
      ${canPost ? `<button class="btn btn-primary" onclick="togglePostForm('${channel}')">＋ 投稿する</button>` : ''}
    </div>
    ${canPost ? postFormHtml(channel) : ''}
    <div id="articles-${channel}">${listHtml}</div>`;
}

async function renderMembers() {
  const panel = document.getElementById('panel-members');
  const ch = CHANNELS['members'];
  const articles = await api('articles', {channel: 'members'});
  const cardsHtml = articles.length
    ? '<div class="members-grid">' + articles.map(a => {
        const pid = 'm' + a.id;
        return `
        <div class="card member-card" id="article-${pid}">
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
            <div class="avatar" style="width:38px;height:38px;font-size:15px;background:#EEEDFE;color:#534AB7">${esc(avatarChar(a.title))}</div>
            <div><div style="font-weight:600;font-size:14px">${esc(a.title)}</div><div style="font-size:12px;color:#999">${a.created_at?.slice(0,10)??''}</div></div>
          </div>
          <div class="article-body" style="font-size:13px">${esc(a.body)}</div>
          <div class="article-footer">
            <button class="footer-btn" onclick="toggleComments('${pid}')">💬 ${a.comment_count??0}件のコメント</button>
            ${IS_ADMIN ? `<button class="footer-btn btn-danger" onclick="deleteArticle(${a.id},'${pid}')" style="margin-left:auto;font-size:12px">削除</button>` : ''}
          </div>
          <div id="comments-${pid}" style="display:none" class="comments-area">
            <div id="clist-${pid}"></div>
            ${renderCommentForm(a.id, a.author_member_id, 'm')}
          </div>
        </div>`;
      }).join('') + '</div>'
    : '<div class="empty">まだ紹介記事はありません</div>';
  panel.innerHTML = `
    <div class="channel-header">
      <div class="channel-title">${esc(ch.name)} <span class="badge badge-amber">主催者のみ投稿可</span></div>
      ${IS_ADMIN ? `<button class="btn btn-primary" onclick="togglePostForm('members')">＋ 投稿する</button>` : ''}
    </div>
    ${IS_ADMIN ? postFormHtml('members') : ''}
    ${cardsHtml}`;
}

async function renderAll() {
  const panel = document.getElementById('panel-all');
  const chCards = Object.entries(CHANNELS).map(([key, ch]) => `
    <div class="ch-card" onclick="showTab('${key}')">
      <div class="ch-name">${esc(ch.name)}</div>
      <div class="ch-desc">${ch.member_can_post ? '会員・主催者が投稿できます' : '主催者のみ投稿できます'}</div>
    </div>`).join('');
  const allArticles = await api('articles', {});
  const listHtml = allArticles.length ? allArticles.map(a => renderArticle(a, 'all')).join('') : '<div class="empty">まだ記事はありません</div>';
  panel.innerHTML = `
    <div style="margin-bottom:24px">
      <div class="channel-title" style="margin-bottom:12px">チャンネル一覧</div>
      <div class="channels-grid">${chCards}</div>
    </div>
    <div class="channel-title" style="margin-bottom:12px">すべての記事</div>
    ${listHtml}`;
}

const tabPanels = ['qa','news','members','all'];
let loaded = {};

async function showTab(name, pushState=true) {
  if (!tabPanels.includes(name)) name = 'qa';
  document.querySelectorAll('.tab').forEach((t,i) => t.classList.toggle('active', tabPanels[i] === name));
  tabPanels.forEach(p => document.getElementById('panel-' + p).style.display = p === name ? '' : 'none');
  if (pushState) location.hash = name;
  if (loaded[name]) return;
  loaded[name] = true;
  if (name === 'members') await renderMembers();
  else if (name === 'all') await renderAll();
  else await renderChannel(name);
}

window.addEventListener('hashchange', () => {
  showTab(location.hash.slice(1), false);
});

document.querySelectorAll('.tab').forEach((t, i) => t.onclick = () => showTab(tabPanels[i]));

const initTab = tabPanels.includes(location.hash.slice(1)) ? location.hash.slice(1) : 'qa';
showTab(initTab, false);
</script>
</body>
</html>
