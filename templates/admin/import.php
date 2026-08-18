<?= $this->render('admin/layout-nav') ?>

<h2>Import kánonu</h2>

<p class="muted">
    Import čte dva soubory z repozitáře: <code>data/canon-2025-2026.html</code>
    (kopie školního dokumentu) a <code>data/tags-2025-2026.csv</code> (ručně
    doplněné značky). Nesahá na internet a nikdy nepřepíše značku, kterou
    potvrdil člověk.
</p>

<form method="post" action="/import">
    <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
    <label class="checkline">
        <input type="checkbox" name="prune" value="1"> Smazat díla, která už v dokumentu nejsou
    </label>
    <button class="btn btn--block" name="mode" value="dry">Nanečisto (nic se nezapíše)</button>
    <button class="btn btn--quiet btn--block" name="mode" value="apply" style="margin-top:.5rem">Provést import</button>
</form>

<?php if ($report !== null): ?>
    <h3 style="margin-top:1.5rem"><?= $this->e($dryRun ? 'Nanečisto — nic se nezapsalo' : 'Import proveden') ?></h3>
    <pre style="overflow-x:auto;font-size:.85rem"><?php foreach ($report->lines() as $line): ?><?= $this->e($line) ?>

<?php endforeach; ?></pre>

    <?php if ($report->unusedFixes !== []): ?>
        <p class="flash flash--warn">Některé opravy vstupu už nesedí — dokument se nejspíš změnil.</p>
    <?php endif; ?>

    <?php if ($report->orphans !== []): ?>
        <h3>Díla, která už v dokumentu nejsou</h3>
        <ul>
            <?php foreach ($report->orphans as $orphan): ?>
                <li>
                    <?= $this->e($orphan['title']) ?>
                    <?php if ($orphan['in_lists'] > 0): ?>
                        <strong>— je v <?= $this->e($orphan['in_lists']) ?> seznamech studentů</strong>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
<?php endif; ?>
