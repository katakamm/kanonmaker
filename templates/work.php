<p class="muted"><a href="/kanon">← zpět na kánon</a></p>

<h2 style="margin-bottom:.2rem"><?= $this->e($work['title']) ?></h2>
<?php if ($work['authors'] !== ''): ?>
    <p class="work__author" style="margin-top:0"><?= $this->e($work['authors']) ?></p>
<?php endif; ?>

<?php if (($work['note'] ?? null) !== null && $work['note'] !== ''): ?>
    <p class="work__note"><?= $this->e($work['note']) ?></p>
<?php endif; ?>

<p class="muted">Kapitola kánonu: <?= $this->e($work['chapter']) ?></p>

<ul class="chips" style="margin:1rem 0">
    <?php foreach (\Kanon\App\Ui::orderedTags($work['tags']) as $tag): ?>
        <li class="chip chip--<?= $this->e($tag['group']) ?><?= $tag['verified'] ? '' : ' chip--unverified' ?>">
            <?= $this->e(\Kanon\App\Ui::groupLabel($tag['group'])) ?>: <?= $this->e($tag['label']) ?>
        </li>
    <?php endforeach; ?>
</ul>

<?php if ($unverified): ?>
    <p class="muted">Značky s otazníkem zatím nepotvrdil člověk.</p>
<?php endif; ?>

<?php if ($user !== null): ?>
    <?php if ($inList): ?>
        <form method="post" action="/seznam/odebrat">
            <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
            <input type="hidden" name="work_id" value="<?= $this->e($work['id']) ?>">
            <input type="hidden" name="zpet" value="/dilo/<?= $this->e($work['id']) ?>">
            <button class="btn btn--quiet btn--block">Odebrat ze seznamu</button>
        </form>
    <?php else: ?>
        <form method="post" action="/seznam/pridat">
            <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
            <input type="hidden" name="work_id" value="<?= $this->e($work['id']) ?>">
            <input type="hidden" name="zpet" value="/dilo/<?= $this->e($work['id']) ?>">
            <button class="btn btn--block">Přidat do seznamu</button>
        </form>
    <?php endif; ?>
<?php endif; ?>
