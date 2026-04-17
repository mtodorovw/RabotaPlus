<?php
require_once __DIR__ . '/../includes/functions.php';
$pageTitle = 'Политика за бисквитки — ' . SITE_NAME;
require_once __DIR__ . '/../includes/header.php';
?>
<div class="fade-in" style="max-width:800px;margin:0 auto;">
<div class="page-header"><h1>🍪 Политика за бисквитки</h1></div>
<div class="card"><div class="card-body legal-content">

<p>Платформата <strong><?= SITE_NAME ?></strong> използва бисквитки с цел подобряване на потребителското изживяване.</p>

<h2>Какво са бисквитки?</h2>
<p>Бисквитките представляват малки текстови файлове, които се съхраняват на устройството на потребителя при посещение на уебсайт.</p>

<h2>За какво ги използваме?</h2>
<ul>
    <li><strong>Сесии</strong> — запазване на влизането в профила ти</li>
    <li><strong>Анализ</strong> — разбиране как се използва платформата</li>
    <li><strong>Функционалност</strong> — запомняне на предпочитания</li>
</ul>

<h2>Как да управляваш бисквитките?</h2>
<p>Потребителите могат да ограничат или изключат бисквитките чрез настройките на своя браузър. Обърни внимание, че деактивирането на бисквитките може да повлияе на функционирането на платформата.</p>

</div></div>
</div>
<style>
@media (max-width: 768px) {
    .legal-content { font-size: 0.9rem; }
    .legal-content h2 { font-size: 1.15rem; }
    .legal-content h3 { font-size: 1rem; }
    [style*="display:grid"] { grid-template-columns: 1fr !important; }
}
</style>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
