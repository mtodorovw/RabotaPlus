<?php
require_once __DIR__ . '/../includes/functions.php';
$user = requireAuth();
$pageTitle = 'Съобщения — ' . SITE_NAME;

$st = db()->prepare('
    SELECT c.id, c.listing_id, c.employer_id, c.applicant_id,
        l.title AS listing_title,
        other.name AS other_name, other.avatar AS other_avatar,
        (SELECT m.body FROM messages m WHERE m.chat_id = c.id ORDER BY m.created_at DESC LIMIT 1) AS last_msg,
        (SELECT m.created_at FROM messages m WHERE m.chat_id = c.id ORDER BY m.created_at DESC LIMIT 1) AS last_at,
        (SELECT COUNT(*) FROM messages m LEFT JOIN chat_reads cr ON cr.chat_id = m.chat_id AND cr.user_id = ? WHERE m.chat_id = c.id AND m.sender_id != ? AND m.id > COALESCE(cr.last_read_id, 0)) AS unread_count
    FROM chats c
    JOIN listings l ON c.listing_id = l.id
    JOIN users other ON other.id = IF(c.employer_id = ?, c.applicant_id, c.employer_id)
    WHERE c.employer_id = ? OR c.applicant_id = ?
    ORDER BY last_at DESC, c.created_at DESC
');
$st->execute([$user['id'], $user['id'], $user['id'], $user['id'], $user['id']]);
$chats = $st->fetchAll();

// Get selected chat
$activeChatId = (int)($_GET['chat'] ?? 0);

require_once __DIR__ . '/../includes/header.php';
?>
<div class="fade-in" style="max-width:1000px;margin:0 auto;">
    <div class="page-header">
        <h1>Съобщения</h1>
    </div>

    <?php if (empty($chats)): ?>
        <div class="empty-state">
            <span class="empty-state-icon">💬</span>
            <h3>Нямаш активни чатове</h3>
            <p>Кандидатствай за обява или публикувай своя, за да започнеш разговор</p>
            <a href="<?= url('index.php') ?>" class="btn btn-primary mt-2">Разгледай обявите</a>
        </div>
    <?php else: ?>
    <div class="chat-layout">
        <!-- Sidebar -->
        <div class="chat-sidebar">
            <div class="chat-sidebar-header">Разговори (<?= count($chats) ?>)</div>
            <?php foreach ($chats as $chat): ?>
            <a href="<?= url('messages/chat.php?id=' . $chat['id']) ?>"
               class="chat-item<?= $activeChatId === $chat['id'] ? ' active' : '' ?>">
                <img src="<?= avatarUrl($chat['other_avatar'], $chat['other_name']) ?>" alt="">
                <div class="chat-item-info">
                    <div class="chat-item-name"><?= h($chat['other_name']) ?></div>
                    <div class="chat-item-last"><?= $chat['last_msg'] ? h(mb_strimwidth($chat['last_msg'], 0, 40, '...', 'UTF-8')) : h(mb_strimwidth($chat['listing_title'], 0, 40, '...', 'UTF-8')) ?></div>
                </div>
                <div class="chat-item-meta">
                    <span class="chat-item-time"><?= $chat['last_at'] ? timeAgo($chat['last_at']) : '' ?></span>
                    <?php if ($chat['unread_count'] > 0): ?>
                        <span class="chat-unread"><?= $chat['unread_count'] ?></span>
                    <?php endif; ?>
                </div>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- Empty state if no chat selected -->
        <div class="chat-main">
            <div class="chat-empty">
                <span class="chat-empty-icon">💬</span>
                <p>Избери разговор от лявото меню</p>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if (!empty($chats)): ?>
<script>
const ACTIVE_CHAT_ID = <?= $activeChatId ?: 0 ?>;

function renderSidebar(chats) {
    const sidebar = document.querySelector('.chat-sidebar');
    if (!sidebar) return;

    // Keep scroll position
    const scrollTop = sidebar.scrollTop;

    // Update header count
    const hdr = sidebar.querySelector('.chat-sidebar-header');
    if (hdr) hdr.textContent = 'Разговори (' + chats.length + ')';

    // Build new items
    const newItems = chats.map(c => {
        const isActive = c.id === ACTIVE_CHAT_ID;
        const lastText = c.last_msg
            ? c.last_msg.substring(0, 40) + (c.last_msg.length > 40 ? '...' : '')
            : c.listing_title.substring(0, 40);

        const unreadBadge = c.unread_count > 0
            ? `<span class="chat-unread">${c.unread_count}</span>`
            : '';

        return `<a href="${c.url}" class="chat-item${isActive ? ' active' : ''}" data-chat-id="${c.id}">
            <img src="${c.other_avatar}" alt="" style="width:40px;height:40px;border-radius:50%;object-fit:cover;flex-shrink:0;">
            <div class="chat-item-info">
                <div class="chat-item-name">${escHtml(c.other_name)}</div>
                <div class="chat-item-last">${escHtml(lastText)}</div>
            </div>
            <div class="chat-item-meta">
                <span class="chat-item-time">${c.last_at}</span>
                ${unreadBadge}
            </div>
        </a>`;
    }).join('');

    // Replace all chat items (keep header)
    const existingItems = sidebar.querySelectorAll('.chat-item');
    existingItems.forEach(el => el.remove());
    sidebar.insertAdjacentHTML('beforeend', newItems);

    // Restore scroll
    sidebar.scrollTop = scrollTop;
}

function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = str || '';
    return d.innerHTML;
}

function pollSidebar() {
    fetch(`${BASE_URL}/api/poll.php?type=sidebar`)
        .then(r => r.json())
        .then(data => {
            if (data.chats) renderSidebar(data.chats);
        })
        .catch(() => {});
}

// Poll every 4 seconds
setInterval(pollSidebar, 4000);
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
