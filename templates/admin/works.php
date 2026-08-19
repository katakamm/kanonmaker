<?= $this->render('admin/layout-nav') ?>

<h2>Díla</h2>

<form method="get" action="/dila">
    <label class="field">
        <span>Hledat</span>
        <input type="search" name="q" value="<?= $this->e($q) ?>" placeholder="autor nebo název">
    </label>
</form>

<p class="muted"><?= $this->e(count($works)) ?> děl</p>

<ul class="works">
    <?php foreach ($works as $work): ?>
        <li class="work">
            <div class="work__body">
                <?php if ($work['authors'] !== ''): ?>
                    <div class="work__author"><?= $this->e($work['authors']) ?></div>
                <?php endif; ?>
                <div class="work__title">
                    <a href="/dila/<?= $this->e($work['id']) ?>" class="plain"><?= $this->e($work['title']) ?></a>
                </div>
                <ul class="chips">
                    <?php if (($work['chapter_chip'] ?? '') !== ''): ?>
                        <li class="chip chip--kapitola" title="kapitola kánonu"><?= $this->e($work['chapter_chip']) ?></li>
                    <?php endif; ?>
                    <?php foreach (\Kanon\App\Ui::orderedTags($work['tags']) as $tag): ?>
                        <li class="chip chip--<?= $this->e($tag['group']) ?><?= $tag['verified'] ? '' : ' chip--unverified' ?>">
                            <?= $this->e($tag['label']) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </li>
    <?php endforeach; ?>
</ul>
