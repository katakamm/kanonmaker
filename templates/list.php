<h2>Můj seznam</h2>

<form method="get" action="/hledat" style="margin:1rem 0">
    <label class="field">
        <span>Přidej dílo — hledej podle autora nebo názvu</span>
        <input type="search" name="q" placeholder="např. capek" autocomplete="off">
    </label>
    <button class="btn btn--block" type="submit">Hledat v kánonu</button>
</form>

<?php if ($works === []): ?>
    <p class="empty">
        Seznam je zatím prázdný.<br>
        <span class="muted">Najdi první dílo, nebo si prolistuj <a href="/kanon">celý kánon</a>.</span>
    </p>
<?php else: ?>
    <?= $this->render('_works', [
        'works' => $works, 'inList' => $inList, 'user' => $user,
        'csrfToken' => $csrfToken, 'back' => '/', 'numbered' => true,
    ]) ?>

    <p style="margin:1.5rem 0"><a class="btn btn--quiet btn--block" href="/export">Export do PDF</a></p>
<?php endif; ?>
