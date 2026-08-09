<?php

declare(strict_types=1);

namespace FamilyCastel\Http;

use FamilyCastel\Core\Config;
use FamilyCastel\Core\Csrf;
use FamilyCastel\Core\Db;
use FamilyCastel\Core\View;
use FamilyCastel\Install\Installer;
use FamilyCastel\Install\InstallState;
use FamilyCastel\Install\SystemCheck;

/**
 * The installation wizard. Route order (each gated on the previous):
 * welcome → check → ownership → database → family → parent → run → done.
 * Only reachable while NOT installed AND config/CAN_INSTALL exists.
 */
final class InstallController
{
    private const STEPS = ['welcome', 'check', 'ownership', 'database', 'family', 'parent', 'run'];

    private InstallState $state;
    private View $view;

    public function __construct(
        private readonly string $rootDir,
        private readonly Config $config,
    ) {
        $this->state = new InstallState($this->rootDir . '/storage');
        $this->view = new View($this->rootDir . '/views');
    }

    public function handle(string $method, array $post): string
    {
        if ($this->config->isInstalled()) {
            http_response_code(404);

            return $this->view->render('install/locked', [], 'layouts/install');
        }

        if (!is_file($this->rootDir . '/config/CAN_INSTALL')) {
            http_response_code(403);

            return $this->view->render('install/no-marker', [], 'layouts/install');
        }

        if ($method === 'POST') {
            if (!Csrf::validate($post['_csrf'] ?? null)) {
                return $this->render($this->currentStep(), ['error' => t('install.error_csrf')]);
            }

            return $this->processStep((string) ($post['step'] ?? ''), $post);
        }

        return $this->render($this->currentStep());
    }

    private function currentStep(): string
    {
        $done = $this->state->completedSteps();
        foreach (self::STEPS as $step) {
            if (!in_array($step, $done, true)) {
                return $step;
            }
        }

        return 'run';
    }

    private function processStep(string $step, array $post): string
    {
        // Re-binding path: whoever can read the setup token owns the
        // filesystem and may (re)claim a half-finished wizard from another
        // browser/session at any time — the old session's binding dies.
        if ($step === 'ownership' && in_array('ownership', $this->state->completedSteps(), true)) {
            if ($this->state->verifySetupToken((string) ($post['setup_code'] ?? ''))) {
                $_SESSION['install_binding'] = $this->state->bindSession();
                \FamilyCastel\Core\Session::regenerate();

                return $this->render($this->currentStep());
            }

            return $this->render('ownership', ['error' => t('install.error_setup_code')]);
        }

        // Never allow skipping ahead of the gate order.
        if ($step !== $this->currentStep()) {
            return $this->render($this->currentStep());
        }

        // Steps after the ownership proof are bound to the session that
        // provided it — another client cannot hijack a half-finished install.
        if (in_array($step, ['database', 'family', 'parent', 'run'], true)
            && !$this->state->sessionMatches($_SESSION['install_binding'] ?? null)) {
            return $this->render('ownership', ['error' => t('install.error_session_binding')]);
        }

        return match ($step) {
            'welcome' => $this->completeAndNext('welcome'),
            'check' => $this->processCheck(),
            'ownership' => $this->processOwnership($post),
            'database' => $this->processDatabase($post),
            'family' => $this->processFamily($post),
            'parent' => $this->processParent($post),
            'run' => $this->processRun(),
            default => $this->render($this->currentStep()),
        };
    }

    private function completeAndNext(string $step): string
    {
        $this->state->markStep($step);

        return $this->render($this->currentStep());
    }

    private function processCheck(): string
    {
        $checker = new SystemCheck($this->rootDir);
        if ($checker->hasBlockingFailure()) {
            return $this->render('check', ['error' => t('install.error_checks_failed')]);
        }

        return $this->completeAndNext('check');
    }

    private function processOwnership(array $post): string
    {
        if (!$this->state->verifySetupToken((string) ($post['setup_code'] ?? ''))) {
            return $this->render('ownership', ['error' => t('install.error_setup_code')]);
        }

        // Bind the rest of the wizard to THIS browser session.
        $_SESSION['install_binding'] = $this->state->bindSession();
        \FamilyCastel\Core\Session::regenerate();

        return $this->completeAndNext('ownership');
    }

    private function processDatabase(array $post): string
    {
        $db = [
            'host' => trim((string) ($post['db_host'] ?? '')),
            'port' => (int) ($post['db_port'] ?? 3306),
            'name' => trim((string) ($post['db_name'] ?? '')),
            'user' => trim((string) ($post['db_user'] ?? '')),
            'password' => (string) ($post['db_password'] ?? ''),
            'prefix' => trim((string) ($post['db_prefix'] ?? '')),
        ];

        if ($db['host'] === '' || $db['name'] === '' || $db['user'] === '') {
            return $this->render('database', ['error' => t('install.error_db_fields'), 'db' => $db]);
        }
        if ($db['prefix'] !== '' && !preg_match('/^[a-z0-9_]{1,16}$/i', $db['prefix'])) {
            return $this->render('database', ['error' => t('install.error_db_prefix'), 'db' => $db]);
        }

        try {
            $connection = Db::fromParams($db['host'], $db['port'], $db['name'], $db['user'], $db['password']);
            // Real DDL privilege test — SELECT 1 is not enough.
            $probe = 'fc_install_probe_' . bin2hex(random_bytes(4));
            $connection->execute("CREATE TABLE `{$probe}` (id INT) ENGINE=InnoDB");
            $connection->execute("DROP TABLE `{$probe}`");
        } catch (\RuntimeException|\PDOException $e) {
            return $this->render('database', [
                'error' => t('install.error_db_connect', ['message' => $e->getMessage()]),
                'db' => $db,
            ]);
        }

        $this->state->set('db', $db);

        return $this->completeAndNext('database');
    }

    private function processFamily(array $post): string
    {
        $family = [
            'name' => trim((string) ($post['family_name'] ?? '')),
            'locale' => (string) ($post['locale'] ?? 'de'),
            'timezone' => (string) ($post['timezone'] ?? 'Europe/Zurich'),
        ];

        if ($family['name'] === '') {
            return $this->render('family', ['error' => t('install.error_family_name'), 'family' => $family]);
        }
        if (!in_array($family['locale'], ['de', 'en', 'fr', 'it'], true)
            || !in_array($family['timezone'], \DateTimeZone::listIdentifiers(), true)) {
            return $this->render('family', ['error' => t('install.error_family_fields'), 'family' => $family]);
        }

        $this->state->set('family', $family);

        return $this->completeAndNext('family');
    }

    private function processParent(array $post): string
    {
        $parent = [
            'name' => trim((string) ($post['parent_name'] ?? '')),
            'username' => trim((string) ($post['parent_username'] ?? '')),
            'password' => (string) ($post['parent_password'] ?? ''),
        ];
        $confirm = (string) ($post['parent_password_confirm'] ?? '');

        if ($parent['name'] === '' || $parent['username'] === '') {
            return $this->render('parent', ['error' => t('install.error_parent_fields'), 'parent' => $parent]);
        }
        if (strlen($parent['password']) < 10) {
            return $this->render('parent', ['error' => t('install.error_parent_password'), 'parent' => $parent]);
        }
        if (!hash_equals($parent['password'], $confirm)) {
            return $this->render('parent', ['error' => t('install.error_parent_confirm'), 'parent' => $parent]);
        }

        $this->state->set('parent', $parent);

        return $this->completeAndNext('parent');
    }

    private function processRun(): string
    {
        $db = $this->state->get('db');
        $family = $this->state->get('family');
        $parent = $this->state->get('parent');

        if (!is_array($db) || !is_array($family) || !is_array($parent)) {
            // State expired mid-run: restart cleanly.
            $this->state->destroy();

            return $this->render('welcome', ['error' => t('install.error_state_expired')]);
        }

        $warning = null;
        try {
            $installer = new Installer($this->rootDir . '/config', $this->rootDir . '/app/Database/Migrations');
            $installer->perform(['db' => $db, 'family' => $family, 'parent' => $parent]);
        } catch (\Throwable $e) {
            \FamilyCastel\Core\ErrorHandler::log($this->rootDir . '/storage/logs', $e);

            // Terminal failure AFTER the lock was written (e.g. CAN_INSTALL not
            // deletable): the install itself succeeded — never leave plaintext
            // credentials/token behind, and surface the warning on the done page.
            if (is_file($this->rootDir . '/config/installed.lock')) {
                $warning = $e->getMessage();
            } else {
                return $this->render('run', ['error' => t('install.error_failed', ['message' => $e->getMessage()])]);
            }
        }

        $leftovers = $this->state->destroy();
        if ($leftovers !== []) {
            $warning = trim(($warning ?? '') . ' ' . t('install.warn_leftovers', ['files' => implode(', ', $leftovers)]));
        }
        \FamilyCastel\Core\Session::regenerate();
        Csrf::rotate();

        return $this->view->render('install/done', ['family' => $family, 'warning' => $warning], 'layouts/install');
    }

    /** @param array<string, mixed> $extra */
    private function render(string $step, array $extra = []): string
    {
        $data = $extra + [
            'step' => $step,
            'steps' => self::STEPS,
            'completed' => $this->state->completedSteps(),
        ];

        if ($step === 'check') {
            $data['checks'] = (new SystemCheck($this->rootDir))->run();
        }
        if ($step === 'ownership') {
            $this->state->ensureSetupToken();
        }
        if ($step === 'family') {
            $data['timezones'] = \DateTimeZone::listIdentifiers();
        }
        if ($step === 'run') {
            $data['family'] = $this->state->get('family');
            $data['parent'] = $this->state->get('parent');
            $data['db'] = $this->state->get('db');
        }

        return $this->view->render('install/' . $step, $data, 'layouts/install');
    }
}
