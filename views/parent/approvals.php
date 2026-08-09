<h2 class="page-title"><?= e(t('approvals.title')) ?></h2>

<?php
$sections = [
    ['kind' => 'sidequest', 'title' => t('approvals.sidequests'), 'items' => $claims, 'emoji' => '🗡️'],
    ['kind' => 'suggestion', 'title' => t('approvals.suggestions'), 'items' => $suggestions, 'emoji' => '💡'],
    ['kind' => 'reward', 'title' => t('approvals.rewards'), 'items' => $rewardRequests, 'emoji' => '🎁'],
    ['kind' => 'wish', 'title' => t('approvals.wishes'), 'items' => $wishes, 'emoji' => '🏆'],
];
$totalPending = array_sum(array_map(fn ($s) => count($s['items']), $sections));
?>

<?php if ($totalPending === 0): ?>
    <section class="empty-state"><p>🎉 <?= e(t('approvals.empty')) ?></p></section>
<?php endif; ?>

<?php foreach ($sections as $section): ?>
    <?php if (empty($section['items'])) { continue; } ?>
    <h3 class="section-title"><?= e($section['emoji'] . ' ' . $section['title']) ?> (<?= count($section['items']) ?>)</h3>
    <section class="list-cards">
        <?php foreach ($section['items'] as $item): ?>
            <div class="list-card approval-card">
                <div class="list-card-body">
                    <strong><?= e($item['child_name']) ?>: <?= e($item['title']) ?></strong>
                    <span class="muted">
                        <?php if ($section['kind'] === 'sidequest'): ?>
                            +<?= e((string) $item['coins_reward']) ?> 🪙<?php if ((int) $item['xp_reward'] > 0): ?> · +<?= e((string) $item['xp_reward']) ?> ✨<?php endif; ?>
                        <?php elseif ($section['kind'] === 'suggestion'): ?>
                            <?= e(t('approvals.suggested', ['coins' => (string) $item['suggested_coins']])) ?>
                            <?php if (!empty($item['comment'])): ?> · 💬 <?= e($item['comment']) ?><?php endif; ?>
                        <?php elseif ($section['kind'] === 'reward'): ?>
                            🪙 <?= e((string) $item['cost_coins']) ?><?php if ($item['duration_minutes'] !== null): ?> · ⏱️ <?= e((string) $item['duration_minutes']) ?>min<?php endif; ?>
                        <?php else: ?>
                            <?= e(t('approvals.suggested', ['coins' => (string) $item['suggested_coins']])) ?>
                        <?php endif; ?>
                    </span>
                </div>
                <details class="approval-edit">
                    <summary><?= e(t('approvals.edit')) ?></summary>
                    <form method="post" action="<?= e(url('/parent/approvals/decide')) ?>" class="approval-edit-form">
                        <?= \FamilyCastel\Core\Csrf::field() ?>
                        <input type="hidden" name="kind" value="<?= eattr($section['kind']) ?>">
                        <input type="hidden" name="id" value="<?= eattr((string) $item['id']) ?>">
                        <input type="hidden" name="action" value="approve">
                        <label><?= e($section['kind'] === 'reward' || $section['kind'] === 'wish' ? t('award.coins') : t('approvals.coins_override')) ?>
                            <input name="coins" type="number" min="0" max="100000"
                                   value="<?= eattr((string) ($item['coins_reward'] ?? $item['suggested_coins'] ?? $item['cost_coins'] ?? 0)) ?>">
                        </label>
                        <?php if (in_array($section['kind'], ['sidequest', 'suggestion'], true)): ?>
                            <label><?= e(t('award.xp')) ?>
                                <input name="xp" type="number" min="0" max="100000"
                                       value="<?= eattr((string) ($item['xp_reward'] ?? $item['suggested_coins'] ?? 0)) ?>">
                            </label>
                        <?php endif; ?>
                        <label><?= e(t('award.comment')) ?>
                            <input name="comment" type="text" maxlength="500">
                        </label>
                        <button type="submit" class="btn-primary btn-inline"><?= e(t('approvals.approve_edited')) ?></button>
                    </form>
                </details>
                <div class="list-card-actions">
                    <form method="post" action="<?= e(url('/parent/approvals/decide')) ?>">
                        <?= \FamilyCastel\Core\Csrf::field() ?>
                        <input type="hidden" name="kind" value="<?= eattr($section['kind']) ?>">
                        <input type="hidden" name="id" value="<?= eattr((string) $item['id']) ?>">
                        <input type="hidden" name="action" value="approve">
                        <button type="submit" class="btn-primary btn-inline">✅ <?= e(t('approvals.approve')) ?></button>
                    </form>
                    <form method="post" action="<?= e(url('/parent/approvals/decide')) ?>">
                        <?= \FamilyCastel\Core\Csrf::field() ?>
                        <input type="hidden" name="kind" value="<?= eattr($section['kind']) ?>">
                        <input type="hidden" name="id" value="<?= eattr((string) $item['id']) ?>">
                        <input type="hidden" name="action" value="reject">
                        <button type="submit" class="btn-ghost-danger"><?= e(t('approvals.reject')) ?></button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </section>
<?php endforeach; ?>
