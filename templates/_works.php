<?php /** @var list<array> $works */ ?>
<?php if ($works === []): ?>
    <p class="empty"><?= $this->e($emptyText ?? 'Nic tu není.') ?></p>
<?php else: ?>
    <ul class="works">
        <?php foreach ($works as $i => $work): ?>
            <?= $this->render('_work', [
                'work'      => $work,
                'number'    => ($numbered ?? false) ? $i + 1 : null,
                'inList'    => $inList,
                'user'      => $user,
                'csrfToken' => $csrfToken,
                'back'      => $back,
            ]) ?>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
