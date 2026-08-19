<?php /** @var array $work */ ?>
<li class="work">
    <?php if (($number ?? null) !== null): ?><span class="work__num"><?= $this->e($number) ?>.</span><?php endif; ?>
    <div class="work__body">
        <?php if ($work['authors'] !== ''): ?>
            <div class="work__author"><?= $this->e($work['authors']) ?></div>
        <?php endif; ?>
        <div class="work__title">
            <a href="/dilo/<?= $this->e($work['id']) ?>" class="plain"><?= $this->e($work['title']) ?></a>
        </div>
        <?php if (($work['note'] ?? null) !== null && $work['note'] !== ''): ?>
            <div class="work__note"><?= $this->e($work['note']) ?></div>
        <?php endif; ?>
        <ul class="chips">
            <?php if (($work['chapter_chip'] ?? '') !== ''): ?>
                <li class="chip chip--kapitola" title="kapitola kánonu"><?= $this->e($work['chapter_chip']) ?></li>
            <?php endif; ?>
            <?php foreach (\Kanon\App\Ui::orderedTags($work['tags']) as $tag): ?>
                <li class="chip chip--<?= $this->e($tag['group']) ?><?= $tag['verified'] ? '' : ' chip--unverified' ?>"
                    title="<?= $this->e(\Kanon\App\Ui::groupLabel($tag['group'])) ?><?= $tag['verified'] ? '' : ' — zatím nepotvrzeno' ?>">
                    <?= $this->e($tag['label']) ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php if ($user !== null): ?>
        <div>
            <?php if (in_array($work['id'], $inList, true)): ?>
                <form method="post" action="/seznam/odebrat">
                    <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                    <input type="hidden" name="work_id" value="<?= $this->e($work['id']) ?>">
                    <input type="hidden" name="zpet" value="<?= $this->e($back) ?>">
                    <button class="btn btn--quiet" title="Odebrat ze seznamu">✓</button>
                </form>
            <?php else: ?>
                <form method="post" action="/seznam/pridat">
                    <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                    <input type="hidden" name="work_id" value="<?= $this->e($work['id']) ?>">
                    <input type="hidden" name="zpet" value="<?= $this->e($back) ?>">
                    <button class="btn" title="Přidat do seznamu">+</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</li>
