<?= $this->render('admin/layout-nav') ?>

<h2>Uživatelé</h2>
<p class="muted"><?= $this->e(count($students)) ?> účtů</p>

<?php foreach ($students as $student): ?>
    <div style="padding:.8rem 0;border-bottom:1px solid var(--line)">
        <div><strong><?= $this->e($student['display_name']) ?></strong>
            <?php if ($student['role'] === 'admin'): ?>
                <span class="chip chip--special">správce</span>
            <?php endif; ?>
            <?php if (!$student['active']): ?>
                <span class="chip chip--podobdobi">zablokován</span>
            <?php endif; ?>
        </div>
        <div class="muted"><?= $this->e($student['email']) ?> · <?= $this->e($student['works']) ?> děl v seznamu</div>

        <form method="post" action="/uzivatele/<?= $this->e($student['id']) ?>" style="margin-top:.4rem;display:flex;gap:.4rem;flex-wrap:wrap">
            <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
            <button class="btn btn--quiet btn--small" name="akce"
                    value="<?= $student['role'] === 'admin' ? 'demote' : 'promote' ?>">
                <?= $student['role'] === 'admin' ? 'Odebrat správce' : 'Udělat správcem' ?>
            </button>
            <button class="btn btn--quiet btn--small" name="akce"
                    value="<?= $student['active'] ? 'deactivate' : 'activate' ?>">
                <?= $student['active'] ? 'Zablokovat' : 'Odblokovat' ?>
            </button>
        </form>
    </div>
<?php endforeach; ?>
