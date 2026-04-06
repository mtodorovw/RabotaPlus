<?php
require_once __DIR__ . '/../includes/functions.php';
$user   = requireAuth();
$chatId = (int)($_GET['id'] ?? 0);

$st = db()->prepare('SELECT c.*, l.title AS listing_title, l.id AS listing_id,
    e.id AS emp_id, e.name AS emp_name, e.avatar AS emp_avatar,
    a.id AS app_id, a.name AS app_name, a.avatar AS app_avatar
    FROM chats c
    JOIN listings l ON c.listing_id = l.id
    JOIN users e ON c.employer_id = e.id
    JOIN users a ON c.applicant_id = a.id
    WHERE c.id = ? AND (c.employer_id = ? OR c.applicant_id = ?)');
$st->execute([$chatId, $user['id'], $user['id']]);
$chat = $st->fetch();
if (!$chat) { flash('Чатът не е намерен.', 'error'); redirect(url('messages/index.php')); }

$isEmployer  = $user['id'] === (int)$chat['employer_id'];
$otherName   = $isEmployer ? $chat['app_name']   : $chat['emp_name'];
$otherAvatar = $isEmployer ? $chat['app_avatar']  : $chat['emp_avatar'];

db()->prepare('UPDATE messages SET is_read=1 WHERE chat_id=? AND sender_id!=? AND is_read=0')
   ->execute([$chatId, $user['id']]);

// AJAX send
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax'])) {
    header('Content-Type: application/json');
    verifyCsrf();
    $body           = trim($_POST['body'] ?? '');
    $attachmentPath = trim($_POST['attachment'] ?? '');
    $attachmentType = $_POST['attachment_type'] ?? null;
    if ($body || $attachmentPath) {
        db()->prepare('INSERT INTO messages (chat_id, sender_id, body, attachment, attachment_type) VALUES (?,?,?,?,?)')
           ->execute([$chatId, $user['id'], $body ?: null, $attachmentPath ?: null, $attachmentType ?: null]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

// Load messages
$st = db()->prepare('SELECT m.*, u.name AS sender_name, u.avatar AS sender_avatar, u.role AS sender_role
    FROM messages m JOIN users u ON m.sender_id = u.id
    WHERE m.chat_id = ? ORDER BY m.created_at ASC');
$st->execute([$chatId]);
$messages = $st->fetchAll();

// Sidebar
$stSide = db()->prepare('SELECT c.id, other.name AS other_name, other.avatar AS other_avatar,
    (SELECT body FROM messages WHERE chat_id=c.id ORDER BY created_at DESC LIMIT 1) AS last_msg,
    (SELECT COUNT(*) FROM messages m LEFT JOIN chat_reads cr ON cr.chat_id=m.chat_id AND cr.user_id=? WHERE m.chat_id=c.id AND m.sender_id!=? AND m.id>COALESCE(cr.last_read_id,0)) AS unread_count
    FROM chats c
    JOIN users other ON other.id = IF(c.employer_id=?, c.applicant_id, c.employer_id)
    WHERE c.employer_id=? OR c.applicant_id=?
    ORDER BY (SELECT created_at FROM messages WHERE chat_id=c.id ORDER BY created_at DESC LIMIT 1) DESC, c.created_at DESC');
$stSide->execute([$user['id'], $user['id'], $user['id'], $user['id'], $user['id']]);
$sideChats = $stSide->fetchAll();

$lastMsgId = empty($messages) ? 0 : (int)end($messages)['id'];
$pageTitle  = 'Чат с ' . h($otherName) . ' — ' . SITE_NAME;
require_once __DIR__ . '/../includes/header.php';

function renderAttachment(string $path, string $type, string $name = ''): string {
    $url  = url($path);
    $n    = $name ?: basename($path);
    if ($type === 'image') return '<a href="'.$url.'" target="_blank"><img src="'.$url.'" style="max-width:220px;max-height:200px;border-radius:8px;display:block;cursor:zoom-in;" loading="lazy"></a>';
    if ($type === 'video') return '<video src="'.$url.'" controls style="max-width:260px;border-radius:8px;"></video>';
    return '<a href="'.$url.'" target="_blank" style="display:inline-flex;align-items:center;gap:0.4rem;background:rgba(255,255,255,0.06);border-radius:8px;padding:0.4rem 0.7rem;font-size:0.82rem;color:var(--gold);text-decoration:none;">📎 '.h($n).'</a>';
}
?>
<div class="fade-in" style="max-width:1000px;margin:0 auto;">
<div class="chat-layout">
    <!-- Sidebar -->
    <div class="chat-sidebar">
        <div class="chat-sidebar-header"><a href="<?= url('messages/index.php') ?>" style="color:var(--gold);font-size:0.85rem;">← Съобщения</a></div>
        <?php foreach ($sideChats as $sc): ?>
        <a href="<?= url('messages/chat.php?id='.$sc['id']) ?>" class="chat-item<?= $sc['id']===$chatId?' active':'' ?>">
            <img src="<?= avatarUrl($sc['other_avatar'],$sc['other_name']) ?>" alt="">
            <div class="chat-item-info">
                <div class="chat-item-name"><?= h($sc['other_name']) ?></div>
                <div class="chat-item-last"><?= $sc['last_msg'] ? h(mb_strimwidth($sc['last_msg'],0,35,'...','UTF-8')) : 'Нов чат' ?></div>
            </div>
            <?php if ($sc['unread_count']>0): ?><span class="chat-unread"><?= $sc['unread_count'] ?></span><?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Main -->
    <div class="chat-main" id="chat-main-wrap" style="position:relative;">
        <div class="chat-main-header">
            <img src="<?= avatarUrl($otherAvatar,$otherName) ?>" alt="">
            <div>
                <h3><?= h($otherName) ?></h3>
                <small><a href="<?= url('listings/view.php?id='.$chat['listing_id']) ?>" style="color:var(--gold);"><?= h(mb_strimwidth($chat['listing_title'],0,60,'...','UTF-8')) ?></a></small>
            </div>
        </div>

        <div class="chat-messages" id="chat-messages">
            <?php if (empty($messages)): ?>
                <div id="chat-empty-hint" style="text-align:center;color:var(--text-dim);font-size:0.875rem;margin:auto;">Началото на разговора. Напиши нещо!</div>
            <?php endif; ?>
            <?php foreach ($messages as $msg):
                $mine    = (int)$msg['sender_id'] === (int)$user['id'];
                $isAdmin = $msg['sender_role'] === 'admin';
            ?>
            <div class="msg-row<?= $mine?' mine':'' ?>" data-msg-id="<?= $msg['id'] ?>">
                <?php if (!$mine): ?><img class="msg-avatar" src="<?= avatarUrl($msg['sender_avatar'],$msg['sender_name']) ?>" alt=""><?php endif; ?>
                <div>
                    <?php if ($isAdmin && !$mine): ?><div style="font-size:0.68rem;color:var(--gold);font-weight:600;margin-bottom:2px;">⚙️ <?= h($msg['sender_name']) ?></div><?php endif; ?>
                    <div class="msg-bubble<?= ($isAdmin&&!$mine)?' admin-bubble':'' ?>">
                        <?php if ($msg['body']): ?><span class="msg-text"><?= nl2br(h($msg['body'])) ?></span><?php endif; ?>
                        <?php if ($msg['attachment']): ?>
                        <div<?= $msg['body'] ? ' style="margin-top:0.4rem;"' : '' ?>>
                            <?= renderAttachment($msg['attachment'], $msg['attachment_type'] ?? 'file') ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="msg-time time-ago-live" data-created="<?= h($msg['created_at']) ?>"><?= timeAgo($msg['created_at']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Attach preview -->
        <div id="attach-bar" style="display:none;flex-shrink:0;padding:0.4rem 1rem;background:var(--navy-3);border-top:1px solid var(--border-dim);display:none;align-items:center;gap:0.75rem;">
            <span id="attach-preview" style="flex:1;font-size:0.82rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></span>
            <button onclick="clearAttachment()" style="background:none;border:none;color:var(--text-dim);cursor:pointer;font-size:1rem;padding:0;line-height:1;">✕</button>
        </div>

        <!-- Emoji picker (in normal flow so it pushes messages up, not overlaps) -->
        <div id="emoji-picker" style="display:none;background:var(--navy-2);border-top:1px solid var(--border-dim);flex-shrink:0;">
            <div style="display:flex;border-bottom:1px solid var(--border-dim);overflow-x:auto;padding:0.3rem 0.5rem;gap:4px;">
                <?php
                $cats = ['😀'=>'Усмивки','👍'=>'Жестове','❤️'=>'Символи','🐶'=>'Животни','🍕'=>'Храна','⚽'=>'Спорт','✈️'=>'Пътуване','💡'=>'Обекти','🔔'=>'Символи 2'];
                foreach ($cats as $ico => $name):
                ?>
                <button class="emoji-cat-btn" onclick="showEmojiCat('<?= $name ?>')" title="<?= $name ?>"
                    style="background:none;border:none;font-size:1.2rem;cursor:pointer;padding:0.2rem 0.4rem;border-radius:4px;flex-shrink:0;"><?= $ico ?></button>
                <?php endforeach; ?>
            </div>
            <div id="emoji-grid" style="padding:0.5rem;max-height:180px;overflow-y:auto;display:flex;flex-wrap:wrap;gap:2px;"></div>
        </div>

        <!-- Input -->
        <div class="chat-input-area" style="flex-shrink:0;">
            <div style="display:flex;gap:0.4rem;align-items:flex-end;width:100%;">
                <button id="emoji-btn" onclick="toggleEmoji()" title="Емоджита"
                    style="background:none;border:none;font-size:1.3rem;cursor:pointer;color:var(--text-dim);padding:0.3rem;flex-shrink:0;align-self:flex-end;margin-bottom:2px;">😊</button>
                <button onclick="document.getElementById('file-input').click()" title="Прикачи файл"
                    style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--text-dim);padding:0.3rem;flex-shrink:0;align-self:flex-end;margin-bottom:2px;">📎</button>
                <input type="file" id="file-input" accept="image/*,video/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip,.rar" style="display:none;" onchange="handleFileSelect(this)">
                <textarea id="msg-input" class="form-control chat-input" placeholder="Напиши съобщение... (Ctrl+V за поставяне на снимка)" rows="1"
                    onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();sendMsg();}"></textarea>
                <button class="btn btn-primary" id="send-btn" onclick="sendMsg()" style="flex-shrink:0;align-self:flex-end;">→</button>
            </div>
        </div>
    </div>
</div>
</div>

<style>
.emoji-cat-btn:hover { background: var(--navy-3) !important; }
.emoji-item { background:none;border:none;font-size:1.3rem;cursor:pointer;padding:3px;border-radius:4px;line-height:1.2; }
.emoji-item:hover { background: var(--navy-3); }
</style>

<script>
const CHAT_ID   = <?= $chatId ?>;
const USER_ID   = <?= (int)$user['id'] ?>;
const CSRF_TOK  = '<?= csrf() ?>';
let lastMsgId   = <?= $lastMsgId ?>;
let isPolling   = false;
let pendingAtt  = null;

// ── Emoji data ────────────────────────────────────────────
const EMOJI_CATS = {
  'Усмивки': ['😀','😁','😂','🤣','😃','😄','😅','😆','😉','😊','😋','😎','😍','🥰','😘','🤩','😏','😒','😞','😔','😟','😕','🙁','☹️','😣','😖','😫','😩','🥺','😢','😭','😤','😠','😡','🤬','😈','👿','😨','😰','😥','😓','🤗','🤔','🤭','🤫','🤥','😶','😐','😑','😬','🙄','😯','😦','😧','😮','😲','😴','🤤','😪','😵','🤐','🥴','🤢','🤮','🤧','🥵','🥶','😷','🤒','🤕','🤑','🤠','😈','💀','☠️','💩','🤡','👹','👺','👻','👽','👾','🤖','😺','😸','😹','😻','😼','😽','🙀','😿','😾'],
  'Жестове': ['👋','🤚','🖐','✋','🖖','👌','🤌','🤏','✌️','🤞','🤟','🤘','🤙','👈','👉','👆','👇','☝️','👍','👎','✊','👊','🤛','🤜','👏','🙌','🤲','🤝','🙏','✍️','💪','🦾','🦵','🦶','👂','🦻','👃','👀','👁️','👅','👄','🫀','🫁','🧠','🦷','🦴'],
  'Символи': ['❤️','🧡','💛','💚','💙','💜','🖤','🤍','🤎','💔','❣️','💕','💞','💓','💗','💖','💘','💝','💟','☮️','✝️','☯️','✡️','🔯','🕎','☦️','⛎','♈','♉','♊','♋','♌','♍','♎','♏','♐','♑','♒','♓','🆔','⚛️','🉑','☢️','☣️','📴','📳','🈶','🈚','🈸','🈺','🈷️','✴️','🆚','💮','🉐','㊙️','㊗️','🈴','🈵','🈹','🈲','🅰️','🅱️','🆎','🆑','🅾️','🆘','❌','⭕','🛑','⛔','📛','🚫','💯','💢','♨️','🔰','✅','☑️','✔️','❎','🔱','⚜️','🔲','🔳','▪️','▫️','◾','◽','◼️','◻️','🟥','🟧','🟨','🟩','🟦','🟪','⬛','⬜','🔶','🔷','🔸','🔹','🔺','🔻','💠','🔘','🔵','🟠','🟡','🟢','🔴','⚫','⚪','🟤','⭐','🌟','💫','✨','🎇','🎆','🌈','🔥','💥','❄️','🌊','💧','🌬️','🌪️','🌈','🌤️','⛅','🌦️','🌧️','⛈️','🌩️','❓','❔','❕','❗','‼️','⁉️','🔅','🔆','📶','🎵','🎶','➕','➖','➗','✖️','♾️','💲','💱','™️','©️','®️','〰️','➰','➿','🔚','🔙','🔛','🔝','🔜'],
  'Животни': ['🐶','🐱','🐭','🐹','🐰','🦊','🐻','🐼','🐨','🐯','🦁','🐮','🐷','🐸','🐵','🙈','🙉','🙊','🐔','🐧','🐦','🐤','🦆','🦅','🦉','🦇','🐺','🐗','🐴','🦄','🐝','🐛','🦋','🐌','🐞','🐜','🦟','🦗','🕷','🦂','🐢','🐍','🦎','🦖','🦕','🐙','🦑','🦐','🦞','🦀','🐡','🐠','🐟','🐬','🐳','🐋','🦈','🐊','🐅','🐆','🦓','🦍','🦧','🐘','🦛','🦏','🐪','🐫','🦒','🦘','🐃','🐂','🐄','🐎','🐖','🐏','🐑','🦙','🐐','🦌','🐕','🐩','🦮','🐕‍🦺','🐈','🐈‍⬛','🐓','🦃','🦚','🦜','🦢','🦩','🕊','🐇','🦝','🦨','🦡','🦫','🦦','🦥','🐁','🐀','🐿','🦔'],
  'Храна': ['🍎','🍊','🍋','🍇','🍓','🫐','🍈','🍒','🍑','🥭','🍍','🥥','🥝','🍅','🍆','🥑','🥦','🥬','🥒','🌶','🫑','🥕','🧄','🧅','🥔','🌽','🍠','🥐','🥯','🍞','🥖','🥨','🧀','🥚','🍳','🧈','🥞','🧇','🥓','🥩','🍗','🍖','🦴','🌭','🍔','🍟','🍕','🫓','🥪','🥙','🧆','🌮','🌯','🫔','🥗','🥘','🫕','🍝','🍜','🍲','🍛','🍣','🍱','🥟','🦪','🍤','🍙','🍚','🍘','🍥','🥮','🍢','🧁','🍰','🎂','🍮','🍭','🍬','🍫','🍿','🍩','🍪','🌰','🥜','🫘','🍯','🧃','🥤','🧋','☕','🫖','🍵','🧉','🍺','🍻','🥂','🍷','🥃','🍸','🍹','🧊'],
  'Спорт': ['⚽','🏀','🏈','⚾','🥎','🎾','🏐','🏉','🥏','🎱','🏓','🏸','🏒','🥍','🏑','🏏','🪃','🥅','⛳','🪁','🎣','🤿','🎽','🎿','🛷','🥌','🎯','🪀','🪆','🎱','🔮','🎮','🕹','🎲','♟','🃏','🀄','🎴','🎭','🎨','🖼','🎪','🎤','🎧','🎼','🎹','🥁','🪘','🎷','🎺','🎸','🪕','🎻','🎬','🎥','📽','🎞','📹','📺','🎙','📻','🎚','🎛','📡'],
  'Пътуване': ['🚗','🚕','🚙','🚌','🚎','🏎','🚓','🚑','🚒','🚐','🛻','🚚','🚛','🚜','🏍','🛵','🚲','🛴','🛺','🚨','🚔','🚍','🚘','🚖','🚡','🚠','🚟','🚃','🚋','🚞','🚝','🚄','🚅','🚈','🚂','🚆','🚇','🚊','🚉','✈️','🛫','🛬','🛩','💺','🛸','🚀','🛶','⛵','🚤','🛥','🛳','⛴','🚢','⚓','⛽','🪝','🚧','🏗','🛑','🚦','🚥','🗺','🗾','🧭','🏔','⛰','🌋','🗻','🏕','🏖','🏜','🏝','🏞','🏟','🏛','🏗','🧱','🪨','🪵','🛖','🏘','🏚','🏠','🏡','🏢','🏣','🏤','🏥','🏦','🏨','🏩','🏪','🏫','🏬','🏭','🏯','🏰','💒','🗼','🗽','⛪','🕌','🛕','🕍','⛩','🕋','⛲','⛺','🌁','🌃','🏙','🌄','🌅','🌆','🌇','🌉','♾','🎠','🎡','🎢'],
  'Обекти': ['💡','🔦','🕯','🪔','💰','💴','💵','💶','💷','💸','💳','🪙','💹','📈','📉','📊','📋','📌','📍','📎','🖇','📏','📐','✂️','🗃','🗄','🗑','🔒','🔓','🔏','🔐','🔑','🗝','🔨','🪓','⛏','⚒','🛠','🗡','⚔️','🛡','🪚','🔧','🪛','🔩','⚙️','🗜','⚖️','🦯','🔗','⛓','🪝','🧲','🪜','🧪','🧫','🧬','🔬','🔭','📡','🛁','🪣','🧴','🪥','🧹','🧺','🧻','🪣','🧼','🫧','🪒','🧽','🧯','🛒','🚪','🪞','🪟','🛏','🛋','🪑','🚽','🪠','🚿','🧴','🪤','🪣','📦','📬','📮','🗳','✏️','✒️','🖊','🖋','📝','📓','📔','📒','📕','📗','📘','📙','📚','📖','🔖','🏷','💰','🔮'],
  'Символи 2': ['🔔','🔕','🔈','🔉','🔊','📢','📣','🔇','🛎','⏰','⌚','⏱','⏲','🕰','⏳','⌛','📅','📆','🗒','🗓','📇','📋','🗂','📁','📂','🗳','🖇','📌','📍','🗺','🌐','🗾','🧭','📷','📸','📹','🎥','📽','🎞','📞','☎️','📟','📠','📺','📻','🧭','⏱','⌚','⏰','📡','🔋','🔌','💻','🖥','🖨','⌨️','🖱','🖲','💾','💿','📀','🧮','📱','☎️','📲','📳','📴','📵','📶','📳','🔇','🔈','🔉','🔊','📢','📣','🔔','🔕','🎵','🎶','📯','🎙','🎚','🎛','📻','🎤','🎧','📻'],
};

let currentEmojiCat = 'Усмивки';

function buildEmojiGrid(cat) {
    const grid = document.getElementById('emoji-grid');
    const emojis = EMOJI_CATS[cat] || [];
    grid.innerHTML = '';
    emojis.forEach(e => {
        const btn = document.createElement('button');
        btn.className = 'emoji-item';
        btn.textContent = e;
        btn.title = e;
        btn.onclick = () => insertEmoji(e);
        grid.appendChild(btn);
    });
}

function showEmojiCat(cat) {
    currentEmojiCat = cat;
    document.querySelectorAll('.emoji-cat-btn').forEach(b => b.style.background = 'none');
    buildEmojiGrid(cat);
}

function toggleEmoji() {
    const p = document.getElementById('emoji-picker');
    const isVisible = p.style.display !== 'none';
    p.style.display = isVisible ? 'none' : 'block';
    if (!isVisible) buildEmojiGrid(currentEmojiCat);
}

function insertEmoji(e) {
    const ta = document.getElementById('msg-input');
    const s = ta.selectionStart, end = ta.selectionEnd;
    ta.value = ta.value.slice(0, s) + e + ta.value.slice(end);
    ta.selectionStart = ta.selectionEnd = s + e.length; // e.length = UTF-16 units, correct for selectionStart
    ta.focus();
}

document.addEventListener('click', ev => {
    const picker = document.getElementById('emoji-picker');
    const btn    = document.getElementById('emoji-btn');
    if (!picker.contains(ev.target) && !btn.contains(ev.target)) picker.style.display = 'none';
});

// ── Attachment ─────────────────────────────────────────────
function handleFileSelect(input) {
    const file = input.files[0]; if (!file) return;
    uploadFile(file);
    input.value = '';
}

// ── Clipboard paste (Ctrl+V image) ────────────────────────
document.addEventListener('paste', ev => {
    const items = ev.clipboardData?.items;
    if (!items) return;
    for (const item of items) {
        if (item.type.startsWith('image/')) {
            ev.preventDefault();
            const file = item.getAsFile();
            if (file) uploadFile(file, 'clipboard_image.png');
            break;
        }
    }
});

function uploadFile(file, overrideName) {
    const bar  = document.getElementById('attach-bar');
    const prev = document.getElementById('attach-preview');
    const name = overrideName || file.name;

    prev.textContent = '⏳ ' + name;
    bar.style.display = 'flex';

    const fd = new FormData();
    fd.append('file', file, name);
    fd.append('chat_id', CHAT_ID);
    fd.append('csrf', CSRF_TOK);

    fetch('<?= url("api/upload_attachment.php") ?>', { method:'POST', body:fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                pendingAtt = { path: data.path, type: data.type, name: name, url: data.url };
                prev.innerHTML = (data.type==='image' ? '🖼️' : data.type==='video' ? '🎬' : '📎') + ' ' + name
                    + (data.type==='image' ? ` <img src="${data.url}" style="height:36px;border-radius:4px;margin-left:6px;vertical-align:middle;">` : '');
            } else {
                prev.textContent = '❌ ' + (data.err || 'Грешка');
                setTimeout(clearAttachment, 3000);
            }
        })
        .catch(() => { prev.textContent = '❌ Грешка'; setTimeout(clearAttachment, 3000); });
}

function clearAttachment() {
    pendingAtt = null;
    const bar = document.getElementById('attach-bar');
    bar.style.display = 'none';
}

// ── Scroll ─────────────────────────────────────────────────
function scrollBottom(force) {
    const box = document.getElementById('chat-messages');
    if (!box) return;
    if (force || box.scrollHeight - box.clientHeight <= box.scrollTop + 80) box.scrollTop = box.scrollHeight;
}
scrollBottom(true);

// ── Build message row ───────────────────────────────────────
function buildMsgRow(m) {
    const mine = (parseInt(m.sender_id) === USER_ID);
    const row  = document.createElement('div');
    row.className = 'msg-row' + (mine ? ' mine' : '');
    row.dataset.msgId = m.id;

    if (!mine) {
        const img = document.createElement('img');
        img.className = 'msg-avatar'; img.src = m.avatar; img.alt = m.sender_name || '';
        row.appendChild(img);
    }

    const wrap   = document.createElement('div');
    const bubble = document.createElement('div');
    bubble.className = 'msg-bubble' + (m.is_admin && !mine ? ' admin-bubble' : '');

    if (m.body) {
        const span = document.createElement('span');
        span.className = 'msg-text';
        const lines = m.body.split('\n');
        lines.forEach((ln, i) => {
            span.appendChild(document.createTextNode(ln));
            if (i < lines.length - 1) span.appendChild(document.createElement('br'));
        });
        bubble.appendChild(span);
    }

    if (m.attachment) {
        const attWrap = document.createElement('div');
        if (m.body) attWrap.style.marginTop = '0.4rem';
        if (m.attachment_type === 'image') {
            attWrap.innerHTML = `<a href="${m.attachment}" target="_blank"><img src="${m.attachment}" style="max-width:220px;max-height:200px;border-radius:8px;display:block;cursor:zoom-in;" loading="lazy"></a>`;
        } else if (m.attachment_type === 'video') {
            attWrap.innerHTML = `<video src="${m.attachment}" controls style="max-width:260px;border-radius:8px;"></video>`;
        } else {
            const fname = m.attachment.split('/').pop();
            attWrap.innerHTML = `<a href="${m.attachment}" target="_blank" style="display:inline-flex;align-items:center;gap:0.4rem;background:rgba(255,255,255,0.06);border-radius:8px;padding:0.4rem 0.7rem;font-size:0.82rem;color:var(--gold);text-decoration:none;">📎 ${fname}</a>`;
        }
        bubble.appendChild(attWrap);
    }

    wrap.appendChild(bubble);

    const time = document.createElement('div');
    time.className = 'msg-time time-ago-live';
    time.dataset.created = m.created_at;
    time.textContent = m.time_ago;
    wrap.appendChild(time);

    row.appendChild(wrap);
    return row;
}

// ── Send ───────────────────────────────────────────────────
function sendMsg() {
    const input = document.getElementById('msg-input');
    const btn   = document.getElementById('send-btn');
    const body  = input.value.trim();
    if (!body && !pendingAtt) return;

    input.value = ''; input.style.height = '';
    btn.disabled = true;
    document.getElementById('emoji-picker').style.display = 'none';

    const att = pendingAtt;
    clearAttachment();

    const params = new URLSearchParams({
        csrf: CSRF_TOK, body, ajax: '1',
        attachment: att ? att.path : '',
        attachment_type: att ? att.type : ''
    });

    fetch('<?= url("messages/chat.php?id=".$chatId) ?>', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: params.toString()
    })
    .then(r => r.json())
    .then(() => pollMessages())
    .catch(() => {})
    .finally(() => { btn.disabled = false; input.focus(); });
}

// ── Poll ───────────────────────────────────────────────────
function pollMessages() {
    if (isPolling) return;
    isPolling = true;

    fetch('<?= url("api/poll.php") ?>?type=messages&chat_id=' + CHAT_ID + '&last_id=' + lastMsgId)
        .then(r => r.json())
        .then(data => {
            if (!data.messages || !data.messages.length) return;
            const box  = document.getElementById('chat-messages');
            const hint = document.getElementById('chat-empty-hint');
            if (hint) hint.remove();

            data.messages.forEach(m => {
                if (document.querySelector(`[data-msg-id="${m.id}"]`)) {
                    lastMsgId = Math.max(lastMsgId, parseInt(m.id));
                    return;
                }
                lastMsgId = Math.max(lastMsgId, parseInt(m.id));
                box.appendChild(buildMsgRow(m));
            });
            scrollBottom(false);
        })
        .catch(() => {})
        .finally(() => { isPolling = false; });
}

setInterval(pollMessages, 3000);

// ── Real-time sidebar update ──────────────────────────────
const CURRENT_CHAT_ID = <?= $chatId ?>;

function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = str || '';
    return d.innerHTML;
}

function renderSidebar(chats) {
    const sidebar = document.querySelector('.chat-sidebar');
    if (!sidebar) return;
    const scrollTop = sidebar.scrollTop;

    // Remove existing chat items (keep the header link)
    sidebar.querySelectorAll('.chat-item').forEach(el => el.remove());

    chats.forEach(c => {
        const isActive = c.id === CURRENT_CHAT_ID;
        const lastText = c.last_msg
            ? c.last_msg.substring(0, 35) + (c.last_msg.length > 35 ? '...' : '')
            : 'Нов чат';

        const a = document.createElement('a');
        a.href = c.url;
        a.className = 'chat-item' + (isActive ? ' active' : '');
        a.dataset.chatId = c.id;
        a.innerHTML = `
            <img src="${c.other_avatar}" alt="" style="width:40px;height:40px;border-radius:50%;object-fit:cover;flex-shrink:0;">
            <div class="chat-item-info">
                <div class="chat-item-name">${escHtml(c.other_name)}</div>
                <div class="chat-item-last">${escHtml(lastText)}</div>
            </div>
            ${c.unread_count > 0 ? `<span class="chat-unread">${c.unread_count}</span>` : ''}
        `;
        sidebar.appendChild(a);
    });

    sidebar.scrollTop = scrollTop;
}

function pollSidebar() {
    fetch(`${BASE_URL}/api/poll.php?type=sidebar`)
        .then(r => r.json())
        .then(data => { if (data.chats) renderSidebar(data.chats); })
        .catch(() => {});
}

setInterval(pollSidebar, 4000);

document.getElementById('msg-input').addEventListener('input', function() {
    this.style.height = 'auto';
    this.style.height = Math.min(this.scrollHeight, 120) + 'px';
});

// Init emoji grid
buildEmojiGrid(currentEmojiCat);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
