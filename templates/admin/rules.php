<?= $this->render('admin/layout-nav') ?>

<h2>Pravidla</h2>
<p class="muted">
    Měnit lze jen čísla a zapnutí. Typ pravidla je v kódu — díky tomu nejde
    uložit pravidlo, které by kontrola neuměla vyhodnotit.
</p>

<?php foreach ($rules as $rule): ?>
    <form method="post" action="/pravidla/<?= $this->e($rule['id']) ?>"
          style="margin:1.2rem 0;padding-bottom:1rem;border-bottom:1px solid var(--line)">
        <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">

        <p style="margin:0 0 .4rem"><strong><?= $this->e($rule['label']) ?></strong></p>
        <p class="muted" style="margin:0 0 .5rem"><?= $this->e($rule['type']) ?></p>

        <?php foreach ($rule['params'] as $key => $value): ?>
            <?php if (is_int($value)): ?>
                <label class="field">
                    <span><?= $this->e($key) ?></span>
                    <input type="number" name="params[<?= $this->e($key) ?>]" value="<?= $this->e($value) ?>" min="0">
                </label>
            <?php else: ?>
                <input type="hidden" name="params[<?= $this->e($key) ?>]" value="<?= $this->e(is_bool($value) ? ($value ? '1' : '0') : $value) ?>">
                <p class="muted" style="margin:.1rem 0"><?= $this->e($key) ?>: <?= $this->e(is_bool($value) ? ($value ? 'ano' : 'ne') : $value) ?></p>
            <?php endif; ?>
        <?php endforeach; ?>

        <label class="checkline">
            <input type="checkbox" name="enabled" value="1" <?= $rule['enabled'] ? 'checked' : '' ?>> Zapnuto
        </label>

        <button class="btn btn--quiet">Uložit</button>
    </form>
<?php endforeach; ?>
