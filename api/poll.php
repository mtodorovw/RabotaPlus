<?php
// api/poll.php
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

$user = auth();
if (!$user) { echo json_encode(['error' => 'unauthorized']); exit; }

$type = $_GET['type'] ?? '';

// ── Unread messages count ──────────────────────────────────
if ($type === 'unread') {
    echo json_encode(['count' => unreadCount($user['id'])]);
    exit;
}

// ── New chat messages ──────────────────────────────────────
if ($type === 'messages') {
    $chatId = (int)($_GET['chat_id'] ?? 0);
    $lastId = (int)($_GET['last_id'] ?? 0);

    $st = db()->prepare('SELECT id FROM chats WHERE id=? AND (employer_id=? OR applicant_id=? OR ?=1)');
    $st->execute([$chatId, $user['id'], $user['id'], (int)($user['role']==='admin')]);
    if (!$st->fetch()) { echo json_encode(['messages' => []]); exit; }

    // Get the latest message ID to mark as read for this user
    $latestSt = db()->prepare('SELECT MAX(id) FROM messages WHERE chat_id=? AND sender_id!=?');
    $latestSt->execute([$chatId, $user['id']]);
    $latestId = (int)$latestSt->fetchColumn();
    if ($latestId > 0) {
        db()->prepare('INSERT INTO chat_reads (chat_id, user_id, last_read_id) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE last_read_id = GREATEST(last_read_id, VALUES(last_read_id))')
           ->execute([$chatId, $user['id'], $latestId]);
    }

    $st = db()->prepare('SELECT m.id, m.body, m.attachment, m.attachment_type, m.sender_id, m.created_at,
        u.name AS sender_name, u.avatar AS sender_avatar, u.role AS sender_role
        FROM messages m JOIN users u ON m.sender_id = u.id
        WHERE m.chat_id = ? AND m.id > ? ORDER BY m.created_at ASC');
    $st->execute([$chatId, $lastId]);
    $msgs = $st->fetchAll();

    $result = array_map(fn($m) => [
        'id'              => $m['id'],
        'body'            => $m['body'],
        'attachment'      => $m['attachment'] ? url($m['attachment']) : null,
        'attachment_type' => $m['attachment_type'],
        'sender_id'       => $m['sender_id'],
        'sender_name'     => $m['sender_name'],
        'sender_role'     => $m['sender_role'] ?? 'user',
        'is_admin'        => ($m['sender_role'] ?? '') === 'admin',
        'avatar'          => avatarUrl($m['sender_avatar'], $m['sender_name']),
        'time_ago'        => timeAgo($m['created_at']),
        'created_at'      => $m['created_at'],
    ], $msgs);

    echo json_encode(['messages' => $result]);
    exit;
}

// ── Notifications list ─────────────────────────────────────
if ($type === 'notifications') {
    $st = db()->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 30');
    $st->execute([$user['id']]);
    $rows = $st->fetchAll();
    foreach ($rows as &$row) {
        $row['time_ago'] = timeAgo($row['created_at']);
    }
    echo json_encode(['notifications' => $rows]);
    exit;
}

// ── Notification count ─────────────────────────────────────
if ($type === 'notif_count') {
    echo json_encode(['count' => unreadNotifCount($user['id'])]);
    exit;
}

// ── Mark one read ──────────────────────────────────────────
if ($type === 'mark_read') {
    $id = (int)($_GET['id'] ?? 0);
    db()->prepare('UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?')
       ->execute([$id, $user['id']]);
    echo json_encode(['ok' => true]);
    exit;
}

// ── Mark all read ──────────────────────────────────────────
if ($type === 'mark_all_read') {
    db()->prepare('UPDATE notifications SET is_read=1 WHERE user_id=?')
       ->execute([$user['id']]);
    echo json_encode(['ok' => true]);
    exit;
}

// ── Sidebar chat list (for real-time updates on messages/index) ───
if ($type === 'sidebar') {
    $st = db()->prepare('
        SELECT c.id, c.listing_id, c.employer_id, c.applicant_id,
            l.title AS listing_title,
            other.name AS other_name, other.avatar AS other_avatar,
            (SELECT m.body FROM messages m WHERE m.chat_id = c.id ORDER BY m.created_at DESC LIMIT 1) AS last_msg,
            (SELECT m.created_at FROM messages m WHERE m.chat_id = c.id ORDER BY m.created_at DESC LIMIT 1) AS last_at,
            (SELECT COUNT(*) FROM messages m LEFT JOIN chat_reads cr ON cr.chat_id = m.chat_id AND cr.user_id = ?
                WHERE m.chat_id = c.id AND m.sender_id != ? AND m.id > COALESCE(cr.last_read_id, 0)) AS unread_count
        FROM chats c
        JOIN listings l ON c.listing_id = l.id
        JOIN users other ON other.id = IF(c.employer_id = ?, c.applicant_id, c.employer_id)
        WHERE c.employer_id = ? OR c.applicant_id = ?
        ORDER BY last_at DESC, c.created_at DESC
    ');
    $st->execute([$user['id'], $user['id'], $user['id'], $user['id'], $user['id']]);
    $chats = $st->fetchAll();
    $result = [];
    foreach ($chats as $c) {
        $result[] = [
            'id'            => (int)$c['id'],
            'other_name'    => $c['other_name'],
            'other_avatar'  => avatarUrl($c['other_avatar'], $c['other_name']),
            'listing_title' => $c['listing_title'],
            'last_msg'      => $c['last_msg'],
            'last_at'       => $c['last_at'] ? timeAgo($c['last_at']) : '',
            'unread_count'  => (int)$c['unread_count'],
            'url'           => url('messages/chat.php?id=' . $c['id']),
        ];
    }
    echo json_encode(['chats' => $result]);
    exit;
}

// ── Contract status for real-time UI ──────────────────────
if ($type === 'contract_status') {
    $cid = (int)($_GET['contract_id'] ?? 0);
    $st = db()->prepare('SELECT employer_confirmed, contractor_confirmed, status FROM contracts WHERE id=? AND (employer_id=? OR contractor_id=?)');
    $st->execute([$cid, $user['id'], $user['id']]);
    echo json_encode($st->fetch() ?: []);
    exit;
}

echo json_encode(['error' => 'unknown type']);
