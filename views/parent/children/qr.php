<h2 class="page-title">🗝️ <?= e(t('children.qr_title', ['name' => $child['name']])) ?></h2>

<section class="form-card qr-card">
    <?php if ($freshToken !== null && $qrUrl !== null): ?>
        <p class="lead"><?= e(t('children.qr_fresh_lead')) ?></p>
        <div id="qr-canvas" class="qr-canvas" data-url="<?= eattr($qrUrl) ?>" role="img"
             aria-label="<?= eattr(t('children.qr_alt', ['name' => $child['name']])) ?>"></div>
        <p class="qr-url"><code><?= e($qrUrl) ?></code></p>
        <p class="muted"><?= e(t('children.qr_once_warning')) ?></p>
        <script src="<?= e(asset('/public-assets/vendor/qrcode.js')) ?>"></script>
        <script src="<?= e(asset('/public-assets/js/qr-render.js')) ?>"></script>
    <?php else: ?>
        <p class="lead"><?= e($hasToken ? t('children.qr_active_lead') : t('children.qr_none_lead')) ?></p>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/parent/children/' . eurl((string) $child['id']) . '/qr/regenerate')) ?>"
          <?= $hasToken ? 'data-confirm="' . eattr(t('children.qr_regen_confirm')) . '"' : '' ?>>
        <?= \FamilyCastel\Core\Csrf::field() ?>
        <button type="submit" class="btn-primary"><?= e($hasToken ? t('children.qr_regenerate') : t('children.qr_create')) ?></button>
    </form>
    <a class="btn-secondary" href="<?= e(url('/parent/children')) ?>"><?= e(t('common.back')) ?></a>
</section>
<script src="<?= e(asset('/public-assets/js/confirm.js')) ?>"></script>
