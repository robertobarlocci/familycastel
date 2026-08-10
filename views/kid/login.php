<section class="install-card kid-login">
    <?php if (!empty($error)): ?>
        <div class="install-error" role="alert">⚠️ <?= e($error) ?></div>
    <?php endif; ?>
    <h2><?= e(t('kid.login_title')) ?></h2>
    <p class="lead"><?= e(t('kid.login_lead')) ?></p>

    <?php if (empty($children)): ?>
        <p class="lead"><?= e(t('kid.no_children')) ?></p>
    <?php else: ?>
        <div class="kid-picker" role="list">
            <?php foreach ($children as $child): ?>
                <form method="post" action="<?= e(url('/kid/login')) ?>" class="kid-pick" role="listitem">
                    <?= \FamilyCastel\Core\Csrf::field() ?>
                    <input type="hidden" name="child_id" value="<?= eattr((string) $child['id']) ?>">
                    <button type="button" class="kid-avatar" data-childid="<?= eattr((string) $child['id']) ?>"
                            data-haspin="<?= eattr((string) $child['has_pin']) ?>">
                        <span class="kid-avatar-emoji" aria-hidden="true"><?= $child['theme'] === 'football' ? '⚽' : '🛡️' ?></span>
                        <span class="kid-avatar-name"><?= e($child['name']) ?></span>
                        <span class="kid-avatar-level"><?= e(t('kid.level', ['level' => (string) $child['level']])) ?></span>
                    </button>
                    <div class="kid-pin <?= isset($selected) && (int) $selected === (int) $child['id'] ? 'open' : '' ?>">
                        <label for="pin-<?= eattr((string) $child['id']) ?>"><?= e(t('kid.pin_label')) ?></label>
                        <input id="pin-<?= eattr((string) $child['id']) ?>" name="pin" type="password"
                               inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="off"
                               class="input-code">
                        <button type="submit" class="btn-primary"><?= e(t('kid.enter')) ?></button>
                    </div>
                </form>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <p class="auth-switch"><a href="<?= e(url('/login')) ?>">🧑‍🦱 <?= e(t('kid.switch_to_parent')) ?></a></p>
</section>
<script src="<?= e(asset('/public-assets/js/kid-login.js')) ?>"></script>
