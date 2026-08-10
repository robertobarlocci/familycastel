<h2 class="page-title"><?= e(t('parent.hello', ['name' => $user['name'] ?? ''])) ?></h2>

<?php if (empty($children)): ?>
    <section class="empty-state">
        <p>🧒 <?= e(t('parent.no_children')) ?></p>
    </section>
<?php else: ?>
    <section class="child-grid">
        <?php foreach ($children as $child): ?>
            <a class="child-card" href="<?= e(url('/parent/child/' . eurl((string) $child['id']))) ?>">
                <span class="child-card-emoji" aria-hidden="true"><?= $child['theme'] === 'football' ? '⚽' : '🛡️' ?></span>
                <span class="child-card-name"><?= e($child['name']) ?></span>
                <span class="child-card-coins">🪙 <?= e((string) $child['coin_balance']) ?></span>
                <span class="child-card-level"><?= e(t('kid.level', ['level' => (string) $child['level']])) ?></span>
            </a>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
