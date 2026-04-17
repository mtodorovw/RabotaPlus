<?php
require_once __DIR__ . '/../includes/functions.php';
$pageTitle = 'Условия за договор — ' . SITE_NAME;
require_once __DIR__ . '/../includes/header.php';
?>
<div class="fade-in" style="max-width:800px;margin:0 auto;">
<div class="page-header"><h1>🤝 Условия за сключване на договор между потребители</h1></div>
<div class="card"><div class="card-body legal-content">

<h2>1. Сключване на договор</h2>
<p>Когато потребител публикува задача и друг потребител приеме нейното изпълнение, между страните възниква договор за извършване на услуга.</p>

<h2>2. Страни по договора</h2>
<p>Договорът се сключва единствено между:</p>
<ul>
    <li>възложител на услугата</li>
    <li>изпълнител на услугата</li>
</ul>
<p>Платформата <strong><?= SITE_NAME ?></strong> не е страна по този договор.</p>

<h2>3. Отговорност</h2>
<p>Всички права и задължения, свързани с изпълнението на услугата, възникват между страните по договора.</p>
<p>Платформата не носи отговорност за:</p>
<ul>
    <li>качество на услугата</li>
    <li>щети върху имущество</li>
    <li>телесни наранявания</li>
    <li>финансови загуби</li>
</ul>

<h2>4. Самостоятелност на изпълнителите</h2>
<p>Изпълнителите действат като независими лица и не са служители на платформата.</p>

<h2>5. Риск при изпълнение</h2>
<p>Извършването на физически дейности се извършва на собствен риск на страните по договора.</p>

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
