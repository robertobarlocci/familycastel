<?php /** @var list<string> $steps @var list<string> $completed @var string $step */ ?>
<ol class="install-progress" aria-label="<?= eattr(t('install.progress_label')) ?>">
    <?php foreach ($steps as $i => $name): ?>
        <?php
        $stateClass = in_array($name, $completed, true) ? 'done' : ($name === $step ? 'current' : 'todo');
        ?>
        <li class="install-progress-dot <?= e($stateClass) ?>" title="<?= eattr(t('install.step_' . $name)) ?>">
            <span><?= e((string) ($i + 1)) ?></span>
        </li>
    <?php endforeach; ?>
</ol>
<?php if (!empty($error)): ?>
    <div class="install-error" role="alert">⚠️ <?= e($error) ?></div>
<?php endif; ?>
