<?php
require_once __DIR__ . '/../includes/functions.php';
$user = requireAuth();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok'=>false]); exit; }

$chatId = (int)($_POST['chat_id'] ?? 0);
if (!$chatId) { echo json_encode(['ok'=>false,'err'=>'No chat_id']); exit; }

// Admins bypass chat ownership check
if ($user['role'] !== 'admin') {
    $st = db()->prepare('SELECT id FROM chats WHERE id=? AND (employer_id=? OR applicant_id=?)');
    $st->execute([$chatId, $user['id'], $user['id']]);
    if (!$st->fetch()) { echo json_encode(['ok'=>false,'err'=>'Access denied']); exit; }
}

if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $errCodes = [1=>'Файлът е прекалено голям (php.ini)',2=>'Файлът е прекалено голям',3=>'Частично качен',4=>'Не е избран файл'];
    $err = $errCodes[$_FILES['file']['error'] ?? 0] ?? 'Upload error';
    echo json_encode(['ok'=>false,'err'=>$err]); exit;
}

$file     = $_FILES['file'];
$origName = basename($file['name']);
$ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
$maxSize  = 20 * 1024 * 1024; // 20MB

if ($file['size'] > $maxSize) { echo json_encode(['ok'=>false,'err'=>'Файлът е прекалено голям (макс. 20MB)']); exit; }

$imageExts = ['jpg','jpeg','png','gif','webp','bmp'];
$videoExts = ['mp4','webm','mov','avi','mkv'];
$fileExts  = ['pdf','doc','docx','xls','xlsx','txt','zip','rar','7z'];
$allExts   = array_merge($imageExts, $videoExts, $fileExts);

if (!in_array($ext, $allExts)) { echo json_encode(['ok'=>false,'err'=>'Неподдържан тип файл (.'.$ext.')']); exit; }

$type = in_array($ext, $imageExts) ? 'image' : (in_array($ext, $videoExts) ? 'video' : 'file');

// ── Store relative to DOCUMENT_ROOT so URL never breaks ───
// Path: <docroot>/freelance-platform/uploads/chat/filename
$appFolder = trim(str_replace(
    str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'])),
    '',
    str_replace('\\', '/', realpath(__DIR__ . '/..'))
), '/');

$uploadDir  = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . '/' . $appFolder . '/uploads/chat/';
$uploadDir  = str_replace('\\', '/', $uploadDir);

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$newName  = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
$destPath = $uploadDir . $newName;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    echo json_encode(['ok'=>false,'err'=>'Не може да се запише файла. Провери права на папката uploads/chat/']); exit;
}

// Return path relative to app root (same as url() expects)
$relPath = 'uploads/chat/' . $newName;
echo json_encode([
    'ok'   => true,
    'path' => $relPath,
    'type' => $type,
    'name' => $origName,
    'url'  => url($relPath),
]);
