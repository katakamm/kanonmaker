<h2>Účet</h2>

<p class="muted" style="margin-top:0">
    <?= $this->e($user['display_name']) ?> · <?= $this->e($user['email']) ?>
    <?php if ($user['role'] === 'admin'): ?>
        · <span class="chip chip--special">správce</span>
    <?php endif; ?>
</p>

<h3 style="margin-top:1.75rem">Moje seznamy</h3>

<?php if ($lists === []): ?>
    <p class="muted">Zatím sis žádný seznam nesestavil/a.</p>
<?php else: ?>
    <ul class="works">
        <?php foreach ($lists as $list): ?>
            <li class="work">
                <div class="work__body">
                    <div class="work__title">
                        <?php if ($list['canon_id'] === $currentCanonId): ?>
                            <a href="/" class="plain">Školní rok <?= $this->e($list['school_year']) ?></a>
                        <?php else: ?>
                            Školní rok <?= $this->e($list['school_year']) ?>
                        <?php endif; ?>
                    </div>
                    <div class="work__author">
                        <?= $this->e($list['works']) ?> děl ·
                        naposledy upraveno <?= $this->e(substr($list['updated_at'], 0, 10)) ?>
                        <?php if ($list['canon_id'] !== $currentCanonId): ?>
                            · starší ročník, jen k nahlédnutí
                        <?php endif; ?>
                    </div>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<h3 style="margin-top:1.75rem">Heslo</h3>
<p><a class="btn btn--quiet btn--block" href="/heslo">Změnit heslo</a></p>

<h3 style="margin-top:1.75rem">Odhlášení</h3>
<form method="post" action="/odhlasit">
    <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
    <button class="btn btn--quiet btn--block">Odhlásit se</button>
</form>
