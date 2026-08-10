<?php

declare(strict_types=1);

namespace FamilyCastel\Tests\Integration;

use FamilyCastel\Core\Db;
use FamilyCastel\Database\Migrator;
use FamilyCastel\Domain\TemplateService;
use PHPUnit\Framework\TestCase;

final class TemplateServiceTest extends TestCase
{
    private Db $db;
    private TemplateService $templates;
    private int $emma;
    private int $noah;

    protected function setUp(): void
    {
        $this->db = Db::fromParams(
            host: getenv('FC_TEST_DB_HOST') ?: '127.0.0.1',
            port: (int) (getenv('FC_TEST_DB_PORT') ?: 3306),
            name: getenv('FC_TEST_DB_NAME') ?: 'familycastel_test',
            user: getenv('FC_TEST_DB_USER') ?: 'fc',
            password: getenv('FC_TEST_DB_PASS') ?: 'fc-dev-password',
        );
        $this->wipe();
        (new Migrator($this->db, FC_ROOT . '/app/Database/Migrations'))->migrate();
        $this->templates = new TemplateService($this->db);

        foreach ([['Emma', 'fantasy'], ['Noah', 'football']] as [$name, $theme]) {
            $this->db->execute(
                'INSERT INTO children (name, theme, created_at, updated_at) VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                [$name, $theme]
            );
        }
        $this->emma = (int) $this->db->fetchOne('SELECT id FROM children WHERE name = ?', ['Emma'])['id'];
        $this->noah = (int) $this->db->fetchOne('SELECT id FROM children WHERE name = ?', ['Noah'])['id'];
    }

    protected function tearDown(): void
    {
        $this->wipe();
    }

    private function wipe(): void
    {
        $this->db->execute('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->db->fetchAll('SHOW TABLES') as $row) {
            $table = str_replace('`', '``', (string) array_values($row)[0]);
            $this->db->execute("DROP TABLE IF EXISTS `{$table}`");
        }
        $this->db->execute('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function testCreateGlobalAndScopedTemplates(): void
    {
        $global = $this->templates->create([
            'title' => 'Geschirr abgeräumt', 'coins_delta' => 5, 'xp_delta' => 5,
            'scope' => 'all', 'is_favorite' => true,
        ]);
        $scoped = $this->templates->create([
            'title' => 'Fussballtraining', 'coins_delta' => 8, 'xp_delta' => 8,
            'scope' => 'selected', 'child_ids' => [$this->noah],
        ]);

        $forEmma = $this->templates->forChild($this->emma);
        $forNoah = $this->templates->forChild($this->noah);

        self::assertCount(1, $forEmma);
        self::assertSame('Geschirr abgeräumt', $forEmma[0]['title']);
        self::assertCount(2, $forNoah);
        self::assertNotFalse(array_search('Fussballtraining', array_column($forNoah, 'title'), true));
        self::assertGreaterThan(0, $global);
        self::assertGreaterThan(0, $scoped);
    }

    public function testNegativeTemplatesAllowed(): void
    {
        $this->templates->create([
            'title' => 'Zimmer nicht aufgeräumt', 'coins_delta' => -5, 'xp_delta' => 0, 'scope' => 'all',
        ]);
        $t = $this->templates->forChild($this->emma)[0];
        self::assertSame(-5, (int) $t['coins_delta']);
        self::assertSame(0, (int) $t['xp_delta']);
    }

    public function testValidationRejectsEmptyTitleZeroDeltaAndNegativeXp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->templates->create(['title' => '', 'coins_delta' => 5, 'xp_delta' => 0, 'scope' => 'all']);
    }

    public function testZeroCoinAndXpTemplateRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->templates->create(['title' => 'Nichts', 'coins_delta' => 0, 'xp_delta' => 0, 'scope' => 'all']);
    }

    public function testFavoritesSortFirst(): void
    {
        $this->templates->create(['title' => 'B normal', 'coins_delta' => 5, 'xp_delta' => 0, 'scope' => 'all']);
        $this->templates->create(['title' => 'A favorit', 'coins_delta' => 5, 'xp_delta' => 0, 'scope' => 'all', 'is_favorite' => true]);

        $list = $this->templates->forChild($this->emma);
        self::assertSame('A favorit', $list[0]['title']);
    }

    public function testArchiveHidesTemplateButKeepsRow(): void
    {
        $id = $this->templates->create(['title' => 'Alt', 'coins_delta' => 5, 'xp_delta' => 0, 'scope' => 'all']);
        $this->templates->archive($id);

        self::assertCount(0, $this->templates->forChild($this->emma));
        self::assertNotNull($this->db->fetchOne('SELECT id FROM point_templates WHERE id = ?', [$id]));
    }

    public function testApplyPostsThroughLedgerWithSnapshotTitle(): void
    {
        $id = $this->templates->create([
            'title' => 'Geschirr abgeräumt', 'coins_delta' => 5, 'xp_delta' => 5, 'scope' => 'all',
        ]);

        $txId = $this->templates->apply($id, $this->emma, actorUserId: null);

        $tx = $this->db->fetchOne('SELECT * FROM transactions WHERE id = ?', [$txId]);
        self::assertSame('Geschirr abgeräumt', $tx['title']);
        self::assertSame('award', $tx['type']);
        self::assertSame('point_template', $tx['source_type']);
        self::assertSame($id, (int) $tx['source_id']);

        $child = $this->db->fetchOne('SELECT coin_balance, xp_total FROM children WHERE id = ?', [$this->emma]);
        self::assertSame(5, (int) $child['coin_balance']);
        self::assertSame(5, (int) $child['xp_total']);
    }

    public function testApplyScopedTemplateToWrongChildFails(): void
    {
        $id = $this->templates->create([
            'title' => 'Nur Noah', 'coins_delta' => 5, 'xp_delta' => 0,
            'scope' => 'selected', 'child_ids' => [$this->noah],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->templates->apply($id, $this->emma, actorUserId: null);
    }

    public function testApplyArchivedTemplateFails(): void
    {
        $id = $this->templates->create(['title' => 'Alt', 'coins_delta' => 5, 'xp_delta' => 0, 'scope' => 'all']);
        $this->templates->archive($id);

        $this->expectException(\InvalidArgumentException::class);
        $this->templates->apply($id, $this->emma, actorUserId: null);
    }
}
