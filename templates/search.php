<h2>Hledání</h2>

<form method="get" action="/hledat" id="search-form">
    <label class="field">
        <span>Autor nebo název — diakritika nevadí</span>
        <input type="search" name="q" id="search-input" value="<?= $this->e($q) ?>"
               placeholder="např. capek" autocomplete="off" autofocus>
    </label>
</form>

<div id="search-results">
    <?php if ($q !== ''): ?>
        <p class="muted"><?= $this->e(count($works)) ?> výsledků pro „<?= $this->e($q) ?>“</p>
    <?php endif; ?>

    <?= $this->render('_works', [
        'works' => $works, 'inList' => $inList, 'user' => $user,
        'csrfToken' => $csrfToken, 'back' => $back,
        'emptyText' => $q === '' ? 'Napiš, co hledáš.' : 'Nic jsme nenašli.',
    ]) ?>
</div>
