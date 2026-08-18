<?= $this->render('admin/layout-nav') ?>

<h2>Ke kontrole</h2>

<?php if ($groups === []): ?>
    <p class="flash flash--ok">Všechny značky jsou potvrzené. Není co kontrolovat.</p>
<?php else: ?>
    <p class="muted">
        Zbývá <?= $this->e($remaining) ?> značek v <?= $this->e(count($groups)) ?> skupinách.
        Skupiny jsou seřazené podle důležitosti — literární období a česká poezie první.
    </p>

    <?php foreach ($groups as $group): ?>
        <section style="margin:1.5rem 0;padding-bottom:1rem;border-bottom:1px solid var(--line)">
            <p class="muted" style="margin:0"><?= $this->e($group['chapter']) ?></p>

            <h3 style="margin:.2rem 0 .5rem">
                <?= $this->e(\Kanon\App\Ui::groupLabel($group['tag_group'])) ?>
                → <span class="chip chip--<?= $this->e($group['tag_group']) ?>"><?= $this->e($group['label']) ?></span>
                <span class="muted">(<?= $this->e($group['count']) ?>)</span>
            </h3>

            <details>
                <summary class="muted" style="min-height:var(--tap);display:flex;align-items:center;cursor:pointer">
                    Zobrazit díla a opravit jednotlivě
                </summary>
                <ul class="works">
                    <?php foreach ($group['works'] as $work): ?>
                        <li class="work">
                            <div class="work__body">
                                <?php if ($work['authors'] !== ''): ?>
                                    <div class="work__author"><?= $this->e($work['authors']) ?></div>
                                <?php endif; ?>
                                <div class="work__title"><?= $this->e($work['title']) ?></div>
                                <form method="post" action="/kontrola/opravit" style="margin-top:.4rem;display:flex;gap:.4rem">
                                    <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                                    <input type="hidden" name="work_id" value="<?= $this->e($work['id']) ?>">
                                    <input type="hidden" name="skupina" value="<?= $this->e($group['tag_group']) ?>">
                                    <select name="znacka">
                                        <?php foreach ($choices[$group['tag_group']] ?? [] as $choice): ?>
                                            <option value="<?= $this->e($choice['code']) ?>"
                                                <?= $choice['code'] === $group['code'] ? 'selected' : '' ?>>
                                                <?= $this->e($choice['label']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="btn btn--quiet">Uložit</button>
                                </form>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </details>

            <form method="post" action="/kontrola/potvrdit-skupinu" style="margin-top:.75rem">
                <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                <input type="hidden" name="kapitola" value="<?= $this->e($group['chapter_id']) ?>">
                <input type="hidden" name="skupina" value="<?= $this->e($group['tag_group']) ?>">
                <input type="hidden" name="znacka" value="<?= $this->e($group['code']) ?>">
                <button class="btn btn--block">Potvrdit všech <?= $this->e($group['count']) ?></button>
            </form>
        </section>
    <?php endforeach; ?>
<?php endif; ?>
