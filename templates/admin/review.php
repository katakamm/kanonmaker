<?= $this->render('admin/layout-nav') ?>

<h2>Ke kontrole</h2>

<?php if ($works === []): ?>
    <p class="flash flash--ok">Všechny značky jsou potvrzené. Není co kontrolovat.</p>
<?php else: ?>
    <p class="muted">
        Zbývá <?= $this->e($remainingWorks) ?> děl (<?= $this->e($remainingTags) ?> značek).
        Díla jsou v pořadí školního kánonu. Značky s otazníkem doplnil import —
        klikni na ni a tím ji potvrdíš. Špatnou značku oprav v <a href="/dila/">detailu díla</a>.
    </p>

    <p><a class="btn btn--quiet btn--block" href="/kontrola/skupiny">Potvrzovat po skupinách (rychlejší)</a></p>

    <?php $lastChapter = null; ?>
    <ul class="works">
        <?php foreach ($works as $work): ?>
            <?php if ($work['chapter'] !== $lastChapter): ?>
                <?php $lastChapter = $work['chapter']; ?>
                <li style="list-style:none;padding:1.2rem 0 .3rem">
                    <strong class="muted"><?= $this->e($work['chapter']) ?></strong>
                </li>
            <?php endif; ?>

            <li class="work" id="w<?= $this->e($work['id']) ?>">
                <div class="work__body">
                    <?php if ($work['authors'] !== ''): ?>
                        <div class="work__author"><?= $this->e($work['authors']) ?></div>
                    <?php endif; ?>
                    <div class="work__title">
                        <a href="/dila/<?= $this->e($work['id']) ?>" class="plain"><?= $this->e($work['title']) ?></a>
                    </div>

                    <ul class="chips">
                        <?php foreach ($work['tags'] as $tag): ?>
                            <?php if ($tag['verified']): ?>
                                <li class="chip chip--<?= $this->e($tag['group']) ?>"><?= $this->e($tag['label']) ?></li>
                            <?php else: ?>
                                <li>
                                    <form method="post" action="/kontrola/potvrdit-znacku" style="display:inline">
                                        <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                                        <input type="hidden" name="work_id" value="<?= $this->e($work['id']) ?>">
                                        <input type="hidden" name="tag_id" value="<?= $this->e($tag['tag_id']) ?>">
                                        <input type="hidden" name="od" value="<?= $this->e($offset) ?>">
                                        <button class="chip chip--<?= $this->e($tag['group']) ?> chip--unverified chip--button"
                                                title="Potvrdit značku <?= $this->e($tag['label']) ?>">
                                            <?= $this->e($tag['label']) ?>
                                        </button>
                                    </form>
                                </li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <div>
                    <form method="post" action="/kontrola/potvrdit-dilo">
                        <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                        <input type="hidden" name="work_id" value="<?= $this->e($work['id']) ?>">
                        <input type="hidden" name="od" value="<?= $this->e($offset) ?>">
                        <button class="btn" title="Potvrdit všechny značky tohoto díla">✓</button>
                    </form>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>

    <p style="display:flex;gap:.5rem;margin:1.5rem 0">
        <?php if ($offset > 0): ?>
            <a class="btn btn--quiet" href="/kontrola?od=<?= $this->e(max(0, $offset - $perPage)) ?>">← předchozí</a>
        <?php endif; ?>
        <?php if ($offset + $perPage < $remainingWorks): ?>
            <a class="btn btn--quiet" href="/kontrola?od=<?= $this->e($offset + $perPage) ?>">další →</a>
        <?php endif; ?>
    </p>
<?php endif; ?>
