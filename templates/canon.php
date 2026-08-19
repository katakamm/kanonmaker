<h2>Školní kánon</h2>

<form method="get" action="/hledat" style="margin:1rem 0">
    <label class="field">
        <span>Hledej podle autora nebo názvu — diakritika nevadí</span>
        <input type="search" name="q" placeholder="např. capek" autocomplete="off">
    </label>
</form>

<details class="filters">
    <summary>Filtrovat</summary>

    <p class="muted" style="margin:.75rem 0 .25rem">Kapitola</p>
    <ul class="chips">
        <li><a class="chip chip--kapitola" href="/kanon">vše (<?= $this->e($total) ?>)</a></li>
        <?php foreach ($chapters as $chapter): ?>
            <li><a class="chip chip--kapitola" href="/kanon?kapitola=<?= $this->e($chapter['id']) ?>">
                <?= $this->e($chapter['name']) ?> (<?= $this->e($chapter['works']) ?>)
            </a></li>
        <?php endforeach; ?>
    </ul>

    <?php foreach ($tagGroups as $group => $tags): ?>
        <p class="muted" style="margin:.75rem 0 .25rem"><?= $this->e(\Kanon\App\Ui::groupLabel($group)) ?></p>
        <ul class="chips">
            <?php foreach ($tags as $tag): ?>
                <li><a class="chip chip--<?= $this->e($group) ?>"
                       href="/kanon?skupina=<?= $this->e($group) ?>&amp;znacka=<?= $this->e($tag['code']) ?>">
                    <?= $this->e($tag['label']) ?>
                </a></li>
            <?php endforeach; ?>
        </ul>
    <?php endforeach; ?>
</details>

<p class="muted"><?= $this->e($heading) ?> — <?= $this->e(count($works)) ?> děl</p>

<?= $this->render('_works', [
    'works' => $works, 'inList' => $inList, 'user' => $user,
    'csrfToken' => $csrfToken, 'back' => $back, 'emptyText' => 'Tomuto filtru neodpovídá žádné dílo.',
]) ?>
