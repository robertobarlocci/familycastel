<h1 class="kid-page-title">📖 <?= e(t('kidnav.journal')) ?></h1>

<nav class="journal-filters" aria-label="<?= eattr(t('journal.filters_label')) ?>">
    <?php foreach ($filters as $f): ?>
        <a class="journal-filter <?= $f === $filter ? 'active' : '' ?>"
           href="<?= e(url('/kid/journal?filter=' . $f)) ?>"><?= e(t('journal.filter_' . $f)) ?></a>
    <?php endforeach; ?>
</nav>

<?php if (empty($entries)): ?>
    <section class="empty-state kid-empty"><p><?= e(t('journal.empty')) ?></p></section>
<?php else: ?>
    <section class="journal-list">
        <?php foreach ($entries as $entry): ?>
            <article class="journal-entry status-<?= e($entry['status']) ?>">
                <span class="journal-delta <?= ($entry['coins'] ?? 0) < 0 ? 'negative' : 'positive' ?>">
                    <?php if ($entry['coins'] !== null && $entry['coins'] !== 0): ?>
                        <?= $entry['coins'] > 0 ? '+' : '' ?><?= e((string) $entry['coins']) ?> 🪙
                    <?php elseif (($entry['xp'] ?? 0) > 0): ?>
                        +<?= e((string) $entry['xp']) ?> ✨
                    <?php endif; ?>
                </span>
                <div class="journal-body">
                    <strong><?= e($entry['title']) ?></strong>
                    <span class="muted">
                        <?= e(substr($entry['at'], 0, 16)) ?>
                        <?php if ($entry['status'] === 'pending'): ?> · ⌛ <?= e(t('journal.pending')) ?><?php endif; ?>
                        <?php if ($entry['status'] === 'rejected'): ?> · ❌ <?= e(t('journal.rejected')) ?><?php endif; ?>
                        <?php if (($entry['xp'] ?? 0) > 0 && ($entry['coins'] ?? 0) !== 0): ?> · +<?= e((string) $entry['xp']) ?> ✨<?php endif; ?>
                    </span>
                    <?php if (!empty($entry['comment'])): ?>
                        <span class="journal-comment">💬 <?= e($entry['comment']) ?></span>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
