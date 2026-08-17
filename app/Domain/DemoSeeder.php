<?php

declare(strict_types=1);

namespace FamilyCastel\Domain;

use FamilyCastel\Core\Db;

/**
 * Demo family data (brief §Demo data quality): Emma (Fantasy, Level 7,
 * 245 Coins) and Noah (Football, Level 5, 182 Coins) with a lived-in story —
 * templates, Sidequests in several states, rewards, milestones, pending
 * approvals and weeks of Journal history.
 *
 * Runs ONLY on an empty family (no children) — never pollutes real data.
 * All Coins/XP go through the LedgerService (INV-002); timestamps are then
 * spread over the past weeks (demo-only cosmetic backdating).
 */
final class DemoSeeder
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return bool false when the DB already has children (refused) */
    public function run(int $parentId): bool
    {
        // Advisory lock closes the check-then-act race — two concurrent
        // seed attempts can never both pass the empty-family guard.
        $lock = $this->db->fetchOne('SELECT GET_LOCK(?, 5) AS ok', ['familycastel_demo_seed']);
        if ((int) ($lock['ok'] ?? 0) !== 1) {
            return false;
        }

        try {
            return $this->seed($parentId);
        } finally {
            $this->db->fetchOne('SELECT RELEASE_LOCK(?) AS r', ['familycastel_demo_seed']);
        }
    }

    private function seed(int $parentId): bool
    {
        $hasChildren = $this->db->fetchOne('SELECT id FROM children LIMIT 1') !== null;
        if ($hasChildren) {
            return false;
        }

        // Second parent (multi-parent support showcase).
        $this->db->execute(
            'INSERT INTO users (name, username, password_hash, role, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE name = name',
            ['Sam', 'sam', password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'parent']
        );

        $children = new ChildService($this->db);
        $emma = $children->create(['name' => 'Emma', 'theme' => 'fantasy', 'character_key' => 'knight']);
        $children->setPin($emma, '1234');
        $noah = $children->create(['name' => 'Noah', 'theme' => 'football', 'character_key' => 'striker']);

        $templates = new TemplateService($this->db);
        $templates->create(['title' => 'Geschirr abgeräumt', 'coins_delta' => 5, 'xp_delta' => 5, 'scope' => 'all', 'is_favorite' => true]);
        $templates->create(['title' => 'Hausaufgaben ohne Erinnerung', 'coins_delta' => 8, 'xp_delta' => 8, 'scope' => 'all', 'is_favorite' => true]);
        $templates->create(['title' => 'Zimmer aufgeräumt', 'coins_delta' => 5, 'xp_delta' => 5, 'scope' => 'all']);
        $templates->create(['title' => 'Zimmer nicht aufgeräumt', 'coins_delta' => -5, 'xp_delta' => 0, 'scope' => 'all', 'requires_confirm' => true]);
        $templates->create(['title' => 'Fussballtraining durchgezogen', 'coins_delta' => 8, 'xp_delta' => 8, 'scope' => 'selected', 'child_ids' => [$noah]]);

        $rewards = new RewardService($this->db);
        $gaming30 = $rewards->createReward(['title' => '30 Minuten Gaming', 'cost_coins' => 30, 'duration_minutes' => 30, 'icon' => '🎮']);
        $rewards->createReward(['title' => '60 Minuten Gaming', 'cost_coins' => 60, 'duration_minutes' => 60, 'icon' => '🕹️']);
        $rewards->createReward(['title' => 'Film am Abend auswählen', 'cost_coins' => 80, 'icon' => '🎬']);
        $rewards->createReward(['title' => 'Lieblingsessen wünschen', 'cost_coins' => 50, 'icon' => '🍝']);

        $milestones = new MilestoneService($this->db);
        $milestones->create($emma, 'Nintendo Switch 2', 1000, 'spend');
        $milestones->create($noah, 'Stadionbesuch mit Papa', 500, 'progress_only');

        $quests = new SidequestService($this->db);
        $questDishes = $quests->create([
            'title' => 'Geschirrspüler ausräumen', 'description' => 'Alles an seinen Platz — auch die Besteckschublade!',
            'coins_reward' => 10, 'xp_reward' => 10, 'type' => 'once', 'ownership' => 'first_come',
        ], $parentId);
        $quests->create([
            'title' => 'Laub zusammenrechen', 'description' => 'Der Burghof (Garten) versinkt im Herbstlaub.',
            'coins_reward' => 15, 'xp_reward' => 15, 'type' => 'once', 'ownership' => 'first_come',
            'expires_at' => gmdate('Y-m-d 21:00:00'),
        ], $parentId);
        $quests->create([
            'title' => 'Bett machen', 'coins_reward' => 3, 'xp_reward' => 3,
            'type' => 'daily', 'ownership' => 'per_child',
        ], $parentId);
        $questTable = $quests->create([
            'title' => 'Tisch decken', 'coins_reward' => 5, 'xp_reward' => 5,
            'type' => 'repeating', 'ownership' => 'first_come',
        ], $parentId);

        $ledger = new LedgerService($this->db);

        // Emma's story: XP 900 (level 7), balance 245 = 325 earned − 80 spent.
        $emmaStory = [
            // [coins, xp, type, title, comment]
            [5, 5, 'award', 'Geschirr abgeräumt', null],
            [8, 8, 'award', 'Hausaufgaben ohne Erinnerung', 'Ganz allein daran gedacht!'],
            [10, 10, 'sidequest', 'Geschirrspüler ausräumen', null],
            [5, 5, 'award', 'Zimmer aufgeräumt', null],
            [20, 20, 'award', 'Kleiner Bruder getröstet', 'Du hast deinem Bruder geholfen, ohne dass wir dich darum gebeten haben. Grossartig!'],
            [8, 8, 'suggestion', 'Garten gegossen', null],
            [15, 15, 'sidequest', 'Laub zusammenrechen', null],
            [5, 5, 'award', 'Geschirr abgeräumt', null],
            [30, 130, 'award', 'Super Zeugnis!', 'Wir sind so stolz auf dich! 🎉'],
            [8, 8, 'award', 'Hausaufgaben ohne Erinnerung', null],
            [10, 10, 'sidequest', 'Bett gemacht — ganze Woche!', null],
            [25, 100, 'award', 'Oma im Garten geholfen', 'Oma hat extra angerufen, um zu schwärmen.'],
            [5, 5, 'award', 'Zimmer aufgeräumt', null],
            [12, 12, 'sidequest', 'Einkäufe hochgetragen', null],
            [9, 9, 'award', 'Vokabeln geübt', null],
            [150, 550, 'award', 'Sommer-Bonus: Ferienlektüre geschafft', 'Fünf Bücher in den Ferien — Rekord!'],
            [-5, 0, 'deduction', 'Zimmer nicht aufgeräumt', null],
            [-30, 0, 'reward_spend', '30 Minuten Gaming', null],
            [-45, 0, 'reward_spend', 'Eis essen mit Freunden', null],
        ];
        $this->postStory($ledger, $emma, $parentId, $emmaStory, 245, 900);

        // Noah's story: XP 497 (level 5), balance 182 = 217 earned − 35 spent.
        $noahStory = [
            [5, 5, 'award', 'Geschirr abgeräumt', null],
            [8, 8, 'award', 'Fussballtraining durchgezogen', null],
            [10, 10, 'sidequest', 'Geschirrspüler ausräumen', null],
            [8, 8, 'award', 'Fussballtraining durchgezogen', 'Auch bei Regen — stark!'],
            [15, 55, 'award', 'Tor-Training mit kleiner Schwester', 'Du warst ein geduldiger Trainer!'],
            [3, 3, 'sidequest', 'Bett gemacht', null],
            [20, 60, 'award', 'Mathe-Test gemeistert', null],
            [8, 8, 'award', 'Fussballtraining durchgezogen', null],
            [5, 5, 'award', 'Tisch gedeckt', null],
            [100, 300, 'award', 'Turnier-Bonus: Fairster Spieler', 'Der Schiedsrichter hat dich extra gelobt! 🏅'],
            [12, 12, 'sidequest', 'Garage aufgeräumt', null],
            [10, 10, 'award', 'Lesestunde freiwillig', null],
            [13, 13, 'award', 'Nachbarn beim Umzug geholfen', null],
            [-35, 0, 'reward_spend', '30 Minuten Gaming', null],
        ];
        $this->postStory($ledger, $noah, $parentId, $noahStory, 182, 497);

        // Live pending items for the approval hub: Emma finished "Tisch
        // decken" (waiting for approval), the dishes quest stays available.
        unset($questDishes);
        $claimId = $quests->accept($questTable, $emma);
        $quests->markCompleted($claimId, $emma);
        $suggestions = new SuggestionService($this->db);
        $suggestions->submit($emma, 'Blumen für Mama gepflückt', 5, 'Vom Feld hinter dem Haus!');
        $rewards->request($gaming30, $noah);
        $milestones->submitWish($emma, 'LEGO Technic Set', 700);

        // Backdate the ledger story across the past three weeks (demo cosmetics).
        $this->spreadTimestamps($emma);
        $this->spreadTimestamps($noah);

        // Achievements reflect the story.
        $achievements = new AchievementService($this->db, new NotificationService($this->db));
        $achievements->sync($emma);
        $achievements->sync($noah);
        // Old unlocks are "seen" — only future ones should celebrate.
        $achievements->markSeen($emma);
        $achievements->markSeen($noah);

        // Same reasoning one level over: the seeded story is three weeks of
        // history, not news. Without this the child's first login greets them
        // with confetti, a rain cloud AND a "99+" Journal badge for events that
        // are backdated to last month — and the CI screenshot job, which runs
        // first against the pristine seed, would capture a rain cloud on every
        // shot.
        $this->db->execute(
            'UPDATE transactions
                SET celebrated_at = created_at, journal_seen_at = created_at
              WHERE child_id IN (?, ?)',
            [$emma, $noah]
        );

        return true;
    }

    /** @param list<array{0:int,1:int,2:string,3:string,4:?string}> $story */
    private function postStory(LedgerService $ledger, int $childId, int $parentId, array $story, int $expectedBalance, int $expectedXp): void
    {
        $coins = array_sum(array_column($story, 0));
        $xp = array_sum(array_column($story, 1));
        if ($coins !== $expectedBalance || $xp !== $expectedXp) {
            throw new \LogicException(
                "Demo story mismatch for child {$childId}: coins {$coins} (expected {$expectedBalance}), xp {$xp} (expected {$expectedXp})"
            );
        }

        foreach ($story as [$c, $x, $type, $title, $comment]) {
            $ledger->post(
                childId: $childId,
                coinsDelta: $c,
                xpDelta: $x,
                type: $type,
                title: $title,
                actorUserId: $parentId,
                comment: $comment,
                allowNegative: false,
            );
        }
    }

    private function spreadTimestamps(int $childId): void
    {
        $rows = $this->db->fetchAll(
            'SELECT id FROM transactions WHERE child_id = ? ORDER BY id',
            [$childId]
        );
        $count = count($rows);
        foreach ($rows as $index => $row) {
            // Oldest ~21 days ago, newest a few hours ago.
            $daysAgo = $count > 1 ? (21.0 * ($count - 1 - $index) / ($count - 1)) : 0.0;
            $ts = gmdate('Y-m-d H:i:s', time() - (int) ($daysAgo * 86400) - random_int(3600, 14400));
            $this->db->execute('UPDATE transactions SET created_at = ? WHERE id = ?', [$ts, $row['id']]);
        }
    }
}
