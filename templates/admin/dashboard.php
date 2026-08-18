<?= $this->render('admin/layout-nav') ?>

<h2>Přehled</h2>

<table style="width:100%;border-collapse:collapse">
    <tr><td style="padding:.4rem 0">Děl v kánonu</td><td style="text-align:right"><?= $this->e($stats['works']) ?></td></tr>
    <tr><td style="padding:.4rem 0">Autorů</td><td style="text-align:right"><?= $this->e($stats['authors']) ?></td></tr>
    <tr><td style="padding:.4rem 0">Značek celkem</td><td style="text-align:right"><?= $this->e($stats['tags_total']) ?></td></tr>
    <tr><td style="padding:.4rem 0"><strong>Značek ke kontrole</strong></td>
        <td style="text-align:right"><strong><?= $this->e($stats['tags_unverified']) ?></strong></td></tr>
    <tr><td style="padding:.4rem 0">Studentů</td><td style="text-align:right"><?= $this->e($stats['students']) ?></td></tr>
    <tr><td style="padding:.4rem 0">Sestavených seznamů</td><td style="text-align:right"><?= $this->e($stats['lists']) ?></td></tr>
</table>

<?php if ($stats['tags_unverified'] > 0): ?>
    <p style="margin-top:1.5rem">
        <a class="btn btn--block" href="/kontrola">Zkontrolovat značky (<?= $this->e($stats['tags_unverified']) ?>)</a>
    </p>
<?php else: ?>
    <p class="flash flash--ok" style="margin-top:1.5rem">Všechny značky jsou potvrzené.</p>
<?php endif; ?>
