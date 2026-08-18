<?= $this->render('admin/layout-nav') ?>

<p class="muted"><a href="/dila">← zpět na díla</a></p>

<h2><?= $this->e($work['title']) ?></h2>
<?php if ($work['authors'] !== ''): ?>
    <p class="work__author"><?= $this->e($work['authors']) ?></p>
<?php endif; ?>
<p class="muted"><?= $this->e($work['chapter']) ?></p>

<form method="post" action="/dila/<?= $this->e($work['id']) ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">

    <label class="field">
        <span>Název</span>
        <input type="text" name="title" value="<?= $this->e($work['title']) ?>" required>
    </label>

    <label class="field">
        <span>Poznámka (vydání, rozsah…)</span>
        <input type="text" name="note" value="<?= $this->e($work['note'] ?? '') ?>">
    </label>

    <p style="margin:1rem 0 .3rem">Značky</p>
    <?php foreach ($choices as $group => $options): ?>
        <?php $current = $work['tags'][$group][0]['code'] ?? ''; ?>
        <label class="field">
            <span><?= $this->e(\Kanon\App\Ui::groupLabel($group)) ?></span>
            <select name="tags[<?= $this->e($group) ?>]">
                <option value="">— nic —</option>
                <?php foreach ($options as $option): ?>
                    <option value="<?= $this->e($option['code']) ?>" <?= $option['code'] === $current ? 'selected' : '' ?>>
                        <?= $this->e($option['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    <?php endforeach; ?>

    <button class="btn btn--block">Uložit</button>
</form>
