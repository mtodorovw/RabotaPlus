<?php
require_once __DIR__ . '/../includes/functions.php';
$pageTitle = 'Контакти — ' . SITE_NAME;
require_once __DIR__ . '/../includes/header.php';
?>
<div class="fade-in" style="max-width:800px;margin:0 auto;">
<div class="page-header"><h1>📬 Контакти</h1></div>
<div class="card"><div class="card-body legal-content">

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1.5rem;margin-bottom:2rem;">
    <div style="background:var(--navy-3);border:1px solid var(--border-dim);border-radius:var(--radius-lg);padding:1.5rem;text-align:center;">
        <div style="font-size:2rem;margin-bottom:0.5rem;">🏢</div>
        <div style="font-size:0.75rem;color:var(--text-dim);margin-bottom:0.25rem;">Оператор</div>
        <div style="font-weight:600;">Работа+ ЕООД</div>
    </div>
    <div style="background:var(--navy-3);border:1px solid var(--border-dim);border-radius:var(--radius-lg);padding:1.5rem;text-align:center;">
        <div style="font-size:2rem;margin-bottom:0.5rem;">✉️</div>
        <div style="font-size:0.75rem;color:var(--text-dim);margin-bottom:0.25rem;">Имейл</div>
        <div style="font-weight:600;"><a href="mailto:support@rabotaplus.bg" style="color:var(--gold);">support@rabotaplus.bg</a></div>
    </div>
    <div style="background:var(--navy-3);border:1px solid var(--border-dim);border-radius:var(--radius-lg);padding:1.5rem;text-align:center;">
        <div style="font-size:2rem;margin-bottom:0.5rem;">🇧🇬</div>
        <div style="font-size:0.75rem;color:var(--text-dim);margin-bottom:0.25rem;">Държава</div>
        <div style="font-weight:600;">Република България</div>
    </div>
</div>

<p style="color:var(--text-muted);">Потребителите могат да се свържат с администратора на платформата чрез посочения имейл за въпроси, свързани с използването на услугата.</p>

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
