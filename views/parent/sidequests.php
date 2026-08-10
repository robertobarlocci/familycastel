<h2 class="page-title">🗡️ <?= e(t('quests.title')) ?></h2>

<details class="create-panel" <?= empty($quests) ? 'open' : '' ?>>
    <summary class="btn-primary btn-inline">＋ <?= e(t('quests.add')) ?></summary>
    <form method="post" action="<?= e(url('/parent/sidequests/create')) ?>" class="form-card">
        <?= \FamilyCastel\Core\Csrf::field() ?>
        <div class="field">
            <label for="q-title"><?= e(t('quests.name')) ?></label>
            <input id="q-title" name="title" type="text" required maxlength="190"
                   placeholder="<?= eattr(t('quests.name_placeholder')) ?>">
        </div>
        <div class="field">
            <label for="q-desc"><?= e(t('quests.description')) ?> <span class="optional">(<?= e(t('install.optional')) ?>)</span></label>
            <input id="q-desc" name="description" type="text" maxlength="500">
        </div>
        <div class="field-row">
            <div class="field grow">
                <label for="q-coins"><?= e(t('award.coins')) ?></label>
                <input id="q-coins" name="coins_reward" type="number" min="0" max="999" value="10" required>
            </div>
            <div class="field grow">
                <label for="q-xp"><?= e(t('award.xp')) ?></label>
                <input id="q-xp" name="xp_reward" type="number" min="0" max="999" value="10">
            </div>
        </div>
        <div class="field-row">
            <div class="field grow">
                <label for="q-type"><?= e(t('quests.type')) ?></label>
                <select id="q-type" name="type">
                    <option value="once"><?= e(t('quests.type_once')) ?></option>
                    <option value="daily"><?= e(t('quests.type_daily')) ?></option>
                    <option value="weekly"><?= e(t('quests.type_weekly')) ?></option>
                    <option value="repeating"><?= e(t('quests.type_repeating')) ?></option>
                </select>
            </div>
            <div class="field grow">
                <label for="q-ownership"><?= e(t('quests.ownership')) ?></label>
                <select id="q-ownership" name="ownership">
                    <option value="first_come"><?= e(t('quests.ownership_first_come')) ?></option>
                    <option value="per_child"><?= e(t('quests.ownership_per_child')) ?></option>
                    <option value="assigned"><?= e(t('quests.ownership_assigned')) ?></option>
                </select>
            </div>
        </div>
        <fieldset class="field checkbox-list">
            <legend><?= e(t('quests.assigned_children')) ?></legend>
            <?php foreach ($children as $child): ?>
                <label class="checkbox">
                    <input type="checkbox" name="assigned_child_ids[]" value="<?= eattr((string) $child['id']) ?>">
                    <?= e($child['name']) ?>
                </label>
            <?php endforeach; ?>
        </fieldset>
        <div class="field">
            <label for="q-expires"><?= e(t('quests.expires')) ?> <span class="optional">(<?= e(t('install.optional')) ?>)</span></label>
            <input id="q-expires" name="expires_at" type="datetime-local">
        </div>
        <button type="submit" class="btn-primary"><?= e(t('quests.create')) ?></button>
    </form>
</details>

<?php if (empty($quests)): ?>
    <section class="empty-state"><p>🗡️ <?= e(t('quests.empty')) ?></p></section>
<?php else: ?>
    <section class="list-cards">
        <?php foreach ($quests as $quest): ?>
            <div class="list-card">
                <span class="list-card-emoji" aria-hidden="true">🗡️</span>
                <div class="list-card-body">
                    <strong><?= e($quest['title']) ?></strong>
                    <span class="muted">
                        +<?= e((string) $quest['coins_reward']) ?> 🪙<?php if ((int) $quest['xp_reward'] > 0): ?> · +<?= e((string) $quest['xp_reward']) ?> ✨<?php endif; ?>
                        · <?= e(t('quests.type_' . $quest['type'])) ?>
                        · <?= e(t('quests.ownership_' . $quest['ownership'])) ?>
                        <?php if ($quest['expires_at'] !== null): ?> · ⏳ <?= e(substr((string) $quest['expires_at'], 0, 16)) ?><?php endif; ?>
                    </span>
                </div>
                <form method="post" action="<?= e(url('/parent/sidequests/' . eurl((string) $quest['id']) . '/archive')) ?>">
                    <?= \FamilyCastel\Core\Csrf::field() ?>
                    <button type="submit" class="btn-ghost-danger"><?= e(t('common.archive')) ?></button>
                </form>
            </div>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
