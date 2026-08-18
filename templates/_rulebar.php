<?php /** @var array $summary @var list<\Kanon\Rules\RuleResult> $results */ ?>
<div class="rulebar">
    <button class="rulebar__summary" type="button" aria-expanded="false" aria-controls="rulebar-panel" id="rulebar-toggle">
        <span class="rulebar__count"><?= $this->e($summary['count']) ?>/<?= $this->e($summary['required']) ?></span>
        <span class="rulebar__bar"><span class="rulebar__fill" style="width:<?= $this->e($summary['percent']) ?>%"></span></span>
        <span class="rulebar__state--<?= $summary['complete'] ? 'ok' : 'warn' ?>">
            <?= $summary['complete']
                ? 'hotovo'
                : 'chybí ' . $this->e($summary['unmet']) . ' ' . ($summary['unmet'] === 1 ? 'pravidlo' : ($summary['unmet'] < 5 ? 'pravidla' : 'pravidel')) ?>
        </span>
        <span aria-hidden="true">▲</span>
    </button>

    <div class="rulebar__panel" id="rulebar-panel" hidden>
        <?php foreach ($results as $result): ?>
            <?php $link = \Kanon\App\RuleBar::fixLink($result); ?>
            <div class="rule rule--<?= $result->satisfied ? 'ok' : 'warn' ?>">
                <span class="rule__mark" aria-hidden="true"><?= $result->satisfied ? '✓' : '!' ?></span>
                <span class="rule__label">
                    <?php if ($link !== null): ?>
                        <a href="<?= $this->e($link) ?>"><?= $this->e($result->label) ?></a>
                    <?php else: ?>
                        <?= $this->e($result->label) ?>
                    <?php endif; ?>
                    <?php if ($result->detail !== null): ?>
                        <span class="rule__detail"><?= $this->e($result->detail) ?></span>
                    <?php endif; ?>
                </span>
                <span class="rule__count"><?= $this->e($result->current) ?>/<?= $this->e($result->required) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
</div>
