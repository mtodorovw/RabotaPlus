<?php
// includes/functions.php
date_default_timezone_set('Europe/Sofia'); // All timestamps in Sofia time
mb_internal_encoding('UTF-8');             // Ensure multibyte emoji handled correctly
ini_set('default_charset', 'UTF-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/stripe.php';

// ── Auto-detect base URL ──────────────────────────────────────
if (!defined('BASE_URL')) {
    $docRoot  = rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'])), '/');
    $appRoot  = rtrim(str_replace('\\', '/', realpath(__DIR__ . '/..')), '/');
    $basePath = str_replace($docRoot, '', $appRoot);
    define('BASE_URL', $basePath);
}

define('SITE_NAME',   'Работа+');
define('UPLOAD_DIR',  __DIR__ . '/../assets/uploads/');
define('UPLOAD_URL',  BASE_URL . '/assets/uploads/');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Auth helpers ──────────────────────────────────────────────
function auth(): ?array {
    if (!isset($_SESSION['user_id'])) return null;
    static $user = null;
    if ($user === null) {
        $st = db()->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([$_SESSION['user_id']]);
        $user = $st->fetch() ?: null;
    }
    return $user;
}

function requireAuth(): array {
    $u = auth();
    if (!$u) { redirect(url('auth/login.php')); }
    return $u;
}

function requireAdmin(): array {
    $u = requireAuth();
    if ($u['role'] !== 'admin') { redirect(url('index.php')); }
    return $u;
}

function login(int $id): void {
    $_SESSION['user_id'] = $id;
}

function logout(): void {
    session_destroy();
}

// ── Routing ───────────────────────────────────────────────────
function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

function url(string $path = ''): string {
    return BASE_URL . '/' . ltrim($path, '/');
}

// ── Security ──────────────────────────────────────────────────
function h(mixed $s): string {
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}

function csrf(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function verifyCsrf(): void {
    if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        die('CSRF mismatch');
    }
}

// ── Flash messages ────────────────────────────────────────────
function flash(string $msg, string $type = 'success'): void {
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
}

function getFlash(): ?array {
    if (isset($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

// ── Finances ──────────────────────────────────────────────────
function logTransaction(int $userId, string $type, float $amount, string $desc, ?int $contractId = null): void {
    $st = db()->prepare('INSERT INTO transactions (user_id, type, amount, description, contract_id) VALUES (?,?,?,?,?)');
    $st->execute([$userId, $type, $amount, $desc, $contractId]);
}

// ── Notifications ─────────────────────────────────────────────
function addNotification(int $userId, string $type, string $message, string $link = ''): void {
    try {
        $st = db()->prepare('INSERT INTO notifications (user_id, type, message, link) VALUES (?,?,?,?)');
        $st->execute([$userId, $type, $message, $link]);
    } catch (Exception $e) {
        // silently fail if table doesn't exist yet
    }
}

function unreadNotifCount(int $userId): int {
    try {
        $st = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $st->execute([$userId]);
        return (int)$st->fetchColumn();
    } catch (Exception $e) { return 0; }
}

// ── Unread messages ───────────────────────────────────────────
function unreadCount(int $userId): int {
    // Count messages newer than the user's last_read_id per chat (per-user read tracking)
    $st = db()->prepare('
        SELECT COUNT(*) FROM messages m
        JOIN chats c ON m.chat_id = c.id
        LEFT JOIN chat_reads cr ON cr.chat_id = m.chat_id AND cr.user_id = ?
        WHERE m.sender_id != ?
        AND (c.employer_id = ? OR c.applicant_id = ?)
        AND m.id > COALESCE(cr.last_read_id, 0)
    ');
    $st->execute([$userId, $userId, $userId, $userId]);
    return (int)$st->fetchColumn();
}

// ── Bulgarian cities ──────────────────────────────────────────
function bgCities(): array {
    return ['София','Пловдив','Варна','Бургас','Русе','Стара Загора','Плевен','Сливен','Добрич','Шумен',
            'Перник','Хасково','Ямбол','Пазарджик','Благоевград','Велико Търново','Враца','Габрово',
            'Видин','Монтана','Кърджали','Кюстендил','Ловеч','Търговище','Силистра'];
}

// ── Date formatting ───────────────────────────────────────────
function timeAgo(string $datetime): string {
    $ts   = strtotime($datetime);
    $diff = time() - $ts;
    // Guard against clock skew (NTP or timezone mismatch)
    if ($diff < 0) $diff = 0;
    if ($diff < 45)     return 'току-що';
    if ($diff < 90)     return 'преди 1 мин.';
    if ($diff < 3600)   return 'преди ' . round($diff/60) . ' мин.';
    if ($diff < 5400)   return 'преди 1 час';
    if ($diff < 86400)  return 'преди ' . round($diff/3600) . ' часа';
    if ($diff < 172800) return 'вчера';
    if ($diff < 604800) return 'преди ' . round($diff/86400) . ' дни';
    return date('d.m.Y', $ts);
}

function formatMoney(float $amount): string {
    return number_format($amount, 2, ',', ' ') . ' €';
}

// ── Avatar ────────────────────────────────────────────────────
function avatarUrl(?string $avatar, string $name): string {
    if ($avatar && file_exists(UPLOAD_DIR . $avatar)) {
        return UPLOAD_URL . rawurlencode($avatar);
    }
    return 'https://ui-avatars.com/api/?name=' . urlencode($name)
         . '&background=C9A84C&color=1a1a2e&bold=true&size=128&font-size=0.5';
}
