<?php
declare(strict_types=1);

/**
 * Chiabeatslife - deploy GitHub senza Composer.
 *
 * Caricare questo file come deploy.php nel document root del dominio.
 * Gestisce sia la prima inizializzazione sia gli aggiornamenti successivi:
 * scarica main da GitHub, prepara release atomiche, conserva .env/vendor,
 * mantiene backup e usa Plesk Composer quando le dipendenze vanno installate.
 *
 * Requisiti PHP: 8.3+, curl, zip.
 */

const DEPLOY_VERSION = '2026-08-17.6';
const DEPLOY_REPOSITORY = 'jellero/chiabeatslife';
const DEPLOY_BRANCH = 'main';
const DEPLOY_SOURCE_URL = 'https://chiabeatslife.jacopoellero.it';
const DEPLOY_TARGET_FALLBACK_URL = 'https://chiabeatslife.jacopoellero.it';
const DEPLOY_HEALTH_PATH = '/api/v1/health';
const DEPLOY_IMPORT_MAX_PAGES = 1200;
const DEPLOY_LIVE_DIR = 'chiabeatslife-site';
const DEPLOY_STATE_DIR = '.chiabeatslife-deploy';
const DEPLOY_MAINTENANCE_FILE = '.chiabeatslife-maintenance.html';
const DEPLOY_MAX_ARCHIVE_BYTES = 500_000_000;
const DEPLOY_BACKUPS_TO_KEEP = 3;
const DEPLOY_DIR_MODE = 0755;
const DEPLOY_FILE_MODE = 0644;
const DEPLOY_SECRET_MODE = 0600;

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
set_time_limit(900);

sendSecurityHeaders();
startSecureSession();

$documentRoot = __DIR__;
$stateDir = $documentRoot . DIRECTORY_SEPARATOR . DEPLOY_STATE_DIR;
$liveDir = $documentRoot . DIRECTORY_SEPARATOR . DEPLOY_LIVE_DIR;
$stateFile = $stateDir . DIRECTORY_SEPARATOR . 'state.json';

ensureStateDirectory($stateDir);

if (!isHttpsRequest() && !isLocalRequest()) {
    renderPage('HTTPS richiesto', '<div class="alert error">Apri questo installer tramite HTTPS.</div>');
    exit;
}

$config = readJsonFile($stateFile);
$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');

if ($config === null) {
    handleFirstRun($stateFile, $action);
    exit;
}

if ($action === 'logout') {
    requireValidCsrf();
    $_SESSION = [];
    session_regenerate_id(true);
    header('Location: ' . currentScriptUrl());
    exit;
}

if (!($_SESSION['deploy_authenticated'] ?? false)) {
    handleLogin($config, $action);
    exit;
}

if (in_array($action, ['install', 'update'], true) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireValidCsrf();
    handleDeploy($documentRoot, $stateDir, $liveDir, $action);
    exit;
}

if ($action === 'complete' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireValidCsrf();
    handleComplete($documentRoot, $stateDir, $liveDir);
    exit;
}

renderDeployForm($liveDir);

function sendSecurityHeaders(): void
{
    header_remove('X-Powered-By');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
}

function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name('chiabeatslife_deployer');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => isHttpsRequest(),
        'samesite' => 'Strict',
        'path' => '/',
    ]);
    session_start();

    if (!isset($_SESSION['deploy_csrf'])) {
        $_SESSION['deploy_csrf'] = bin2hex(random_bytes(32));
    }
}

function isHttpsRequest(): bool
{
    return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function isLocalRequest(): bool
{
    return in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true);
}

function currentScriptUrl(): string
{
    return (string) ($_SERVER['SCRIPT_NAME'] ?? '/deploy.php');
}

function deployTargetUrl(): string
{
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '' || !preg_match('/^[A-Za-z0-9.-]+(?::[0-9]{1,5})?$/', $host)) {
        return DEPLOY_TARGET_FALLBACK_URL;
    }

    $scheme = isHttpsRequest() ? 'https' : 'http';
    return $scheme . '://' . $host;
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf" value="' . e((string) $_SESSION['deploy_csrf']) . '">';
}

function requireValidCsrf(): void
{
    $provided = (string) ($_POST['csrf'] ?? '');
    $expected = (string) ($_SESSION['deploy_csrf'] ?? '');

    if ($provided === '' || $expected === '' || !hash_equals($expected, $provided)) {
        throw new RuntimeException('Token CSRF non valido. Ricarica la pagina.');
    }
}

function ensureStateDirectory(string $stateDir): void
{
    mkdirOrFail($stateDir, 0700);

    $deny = "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";

    if (!is_file($stateDir . '/.htaccess')) {
        file_put_contents($stateDir . '/.htaccess', $deny, LOCK_EX);
    }
    if (!is_file($stateDir . '/index.html')) {
        file_put_contents($stateDir . '/index.html', '', LOCK_EX);
    }

    chmodSafe($stateDir . '/.htaccess', DEPLOY_SECRET_MODE);
    chmodSafe($stateDir . '/index.html', DEPLOY_SECRET_MODE);
}

function handleFirstRun(string $stateFile, string $action): void
{
    $error = null;

    if ($action === 'setup' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        try {
            requireValidCsrf();
            $password = (string) ($_POST['deploy_password'] ?? '');
            $confirmation = (string) ($_POST['deploy_password_confirm'] ?? '');

            if (strlen($password) < 16) {
                throw new RuntimeException('La password di deploy deve contenere almeno 16 caratteri.');
            }
            if (!hash_equals($password, $confirmation)) {
                throw new RuntimeException('Le due password non coincidono.');
            }

            writeJsonFile($stateFile, [
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'created_at' => gmdate(DATE_ATOM),
                'repository' => DEPLOY_REPOSITORY,
            ]);

            session_regenerate_id(true);
            $_SESSION['deploy_authenticated'] = true;
            header('Location: ' . currentScriptUrl());
            exit;
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }

    $body = '<div class="card"><h1>Configura deploy Chiabeatslife</h1>'
        . '<p>Imposta una password dedicata per proteggere inizializzazione e aggiornamenti da GitHub.</p>'
        . ($error ? '<div class="alert error">' . e($error) . '</div>' : '')
        . '<form method="post">' . csrfField()
        . '<input type="hidden" name="action" value="setup">'
        . field('Password deploy', 'deploy_password', 'password', '', true, 'minlength="16" autocomplete="new-password"')
        . field('Ripeti password', 'deploy_password_confirm', 'password', '', true, 'minlength="16" autocomplete="new-password"')
        . '<button type="submit">Attiva installer</button></form></div>';

    renderPage('Configura deploy Chiabeatslife', $body);
}

function handleLogin(array $config, string $action): void
{
    $error = null;
    $blockedUntil = (int) ($_SESSION['deploy_blocked_until'] ?? 0);

    if ($action === 'login' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        try {
            requireValidCsrf();
            if ($blockedUntil > time()) {
                throw new RuntimeException('Troppi tentativi. Riprova tra qualche minuto.');
            }

            $password = (string) ($_POST['deploy_password'] ?? '');
            $hash = (string) ($config['password_hash'] ?? '');

            if ($hash === '' || !password_verify($password, $hash)) {
                $attempts = (int) ($_SESSION['deploy_login_attempts'] ?? 0) + 1;
                $_SESSION['deploy_login_attempts'] = $attempts;

                if ($attempts >= 5) {
                    $_SESSION['deploy_blocked_until'] = time() + 300;
                    $_SESSION['deploy_login_attempts'] = 0;
                }

                throw new RuntimeException('Password di deploy non valida.');
            }

            session_regenerate_id(true);
            $_SESSION['deploy_authenticated'] = true;
            $_SESSION['deploy_login_attempts'] = 0;
            unset($_SESSION['deploy_blocked_until']);
            header('Location: ' . currentScriptUrl());
            exit;
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }

    $body = '<div class="card"><h1>Deploy Chiabeatslife</h1>'
        . '<p>Repository: <code>' . e(DEPLOY_REPOSITORY) . '</code><br>Branch: <code>' . e(DEPLOY_BRANCH) . '</code></p>'
        . ($error ? '<div class="alert error">' . e($error) . '</div>' : '')
        . '<form method="post">' . csrfField()
        . '<input type="hidden" name="action" value="login">'
        . field('Password deploy', 'deploy_password', 'password', '', true, 'autocomplete="current-password" autofocus')
        . '<button type="submit">Accedi</button></form></div>';

    renderPage('Login deploy Chiabeatslife', $body);
}

function renderDeployForm(string $liveDir, ?string $error = null, ?array $result = null): void
{
    $current = readJsonFile($liveDir . '/.deploy-meta.json');
    $currentLabel = $current !== null
        ? e((string) ($current['commit'] ?? 'sconosciuto')) . ' · ' . e((string) ($current['deployed_at'] ?? ''))
        : (is_dir($liveDir) ? 'release esistente senza metadati' : 'nessuna release');

    $envPath = is_file($liveDir . '/.env') ? $liveDir . '/.env' : null;
    $vendorPath = is_file($liveDir . '/vendor/autoload.php') ? $liveDir . '/vendor/autoload.php' : null;
    $firstInstall = $envPath === null;
    $deployStatus = (string) (($current['status'] ?? '') ?: '');
    $needsCompletion = in_array($deployStatus, ['awaiting_composer', 'maintenance'], true);
    $migration = inspectMigrationReport($liveDir);

    $body = '<div class="topbar"><div><strong>Chiabeatslife</strong><small>Deploy Plesk + GitHub · v' . e(DEPLOY_VERSION) . '</small></div>'
        . '<form method="post" class="inline">' . csrfField() . '<input type="hidden" name="action" value="logout"><button class="secondary" type="submit">Esci</button></form></div>'
        . '<div class="card wide"><h1>' . ($firstInstall ? 'Prima inizializzazione' : 'Aggiornamento') . '</h1>'
        . '<div class="status">'
        . '<span>Repository</span><code>' . e(DEPLOY_REPOSITORY) . '</code>'
        . '<span>Branch</span><code>' . e(DEPLOY_BRANCH) . '</code>'
        . '<span>Applicazione</span><code>' . e(deployTargetUrl()) . '</code>'
        . '<span>Release attuale</span><code>' . $currentLabel . '</code>'
        . '<span>.env</span><code>' . ($envPath ? 'presente' : 'mancante') . '</code>'
        . '<span>vendor</span><code>' . ($vendorPath ? 'presente' : 'mancante') . '</code>'
        . '<span>Snapshot</span><code>' . e($migration['label']) . '</code>'
        . '</div>'
        . '<div class="alert info"><strong>Composer è gestito da Plesk.</strong><br>'
        . 'Questo installer non esegue Composer. Se <code>vendor/</code> manca o cambia il manifest Composer, prepara la release e mette il sito in manutenzione finché completi Composer in <code>/' . e(DEPLOY_LIVE_DIR) . '</code>.</div>'
        . ($error ? '<div class="alert error">' . e($error) . '</div>' : '')
        . ($result ? renderResult($result) : '');

    if ($needsCompletion) {
        $completionMessage = $deployStatus === 'awaiting_composer'
            ? 'Apri Plesk → Composer, usa come cartella applicazione <code>/' . e(DEPLOY_LIVE_DIR) . '</code>, esegui <strong>Installa</strong> o <strong>Aggiorna</strong>. Quando esiste <code>vendor/autoload.php</code>, torna qui e completa.'
            : 'La release è presente ma l’health check Slim non è ancora riuscito. Correggi la configurazione e usa il pulsante qui sotto per riprovare senza riscaricare GitHub.';

        $body .= '<div class="alert warning"><strong>Pubblicazione da completare.</strong><br>' . $completionMessage . '</div>'
            . '<form method="post">' . csrfField()
            . '<input type="hidden" name="action" value="complete">'
            . field('Scrivi COMPLETA', 'confirmation', 'text', '', true, 'pattern="COMPLETA" autocomplete="off"')
            . '<button type="submit">Completa pubblicazione</button></form></div>';
        renderPage('Completa deploy Chiabeatslife', $body);
        return;
    }

    if ($firstInstall) {
        $body .= '<form method="post" autocomplete="off">' . csrfField()
            . '<input type="hidden" name="action" value="install">'
            . '<fieldset><legend>Prima inizializzazione</legend>'
            . '<p>Scarica <code>main</code>, importa automaticamente il WordPress live, genera snapshot/asset/route e crea <code>.env</code> per la destinazione.</p>'
            . '<p>Il WordPress corrente viene letto prima del cut-over. Se l’import fallisce, il routing pubblico non viene sostituito.</p>'
            . field('Scrivi INSTALLA', 'confirmation', 'text', '', true, 'pattern="INSTALLA" autocomplete="off"')
            . '</fieldset>'
            . '<button type="submit">Esegui prima inizializzazione</button></form></div>';
    } else {
        $body .= '<form method="post">' . csrfField()
            . '<input type="hidden" name="action" value="update">'
            . '<fieldset><legend>Aggiornamento</legend>'
            . '<p>Scarica l’ultimo commit di <code>main</code> e crea una nuova release atomica. Vengono conservati automaticamente <code>.env</code>, <code>vendor/</code> e gli snapshot/asset importati sul server.</p>'
            . '<p>Se cambia <code>composer.json</code> o <code>composer.lock</code>, la release resta in manutenzione finché Plesk Composer non viene aggiornato.</p>'
            . field('Scrivi AGGIORNA', 'confirmation', 'text', '', true, 'pattern="AGGIORNA" autocomplete="off"')
            . '</fieldset>'
            . '<button type="submit">Scarica GitHub e aggiorna</button></form></div>';
    }

    renderPage('Deploy Chiabeatslife', $body);
}

function handleDeploy(string $documentRoot, string $stateDir, string $liveDir, string $mode): void
{
    $lockHandle = null;
    $workDir = null;
    $releaseDir = null;
    $backupDir = null;
    $activated = false;

    try {
        validateRuntime();
        $expectedConfirmation = $mode === 'install' ? 'INSTALLA' : 'AGGIORNA';
        if ((string) ($_POST['confirmation'] ?? '') !== $expectedConfirmation) {
            throw new RuntimeException('Conferma non valida: scrivi ' . $expectedConfirmation . '.');
        }

        $existingEnv = is_file($liveDir . '/.env');
        if ($mode === 'install' && $existingEnv) {
            throw new RuntimeException('Esiste già un .env nella release: usa la modalità aggiornamento.');
        }
        if ($mode === 'update' && !$existingEnv) {
            throw new RuntimeException('Manca .env: devi eseguire prima la prima inizializzazione.');
        }

        $lockHandle = fopen($stateDir . '/deploy.lock', 'c+');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('È già in corso un altro deploy.');
        }

        $workDir = $stateDir . '/tmp-' . bin2hex(random_bytes(6));
        $archiveFile = $workDir . '/source.zip';
        $extractDir = $workDir . '/extract';
        $releaseDir = $stateDir . '/release-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
        mkdirOrFail($workDir, 0700);
        mkdirOrFail($extractDir, 0700);

        $commit = resolveBranchCommit();
        $sourceDir = downloadRepositorySource($commit, $workDir, $archiveFile, $extractDir);
        validateSourceTree($sourceDir);
        copyDirectory($sourceDir, $releaseDir);

        $oldComposerJsonHash = is_file($liveDir . '/composer.json') ? hash_file('sha256', $liveDir . '/composer.json') : null;
        $newComposerJsonHash = is_file($releaseDir . '/composer.json') ? hash_file('sha256', $releaseDir . '/composer.json') : null;
        $oldComposerLockHash = is_file($liveDir . '/composer.lock') ? hash_file('sha256', $liveDir . '/composer.lock') : null;
        $newComposerLockHash = is_file($releaseDir . '/composer.lock') ? hash_file('sha256', $releaseDir . '/composer.lock') : null;
        $composerChanged = $mode === 'update'
            && ($oldComposerJsonHash !== $newComposerJsonHash || $oldComposerLockHash !== $newComposerLockHash);

        $preserved = preserveRuntimeData($liveDir, $releaseDir);
        $migration = prepareImportedSite($liveDir, $releaseDir, $stateDir, $mode);

        if ($mode === 'install') {
            if (file_put_contents($releaseDir . '/.env', buildEnvFile(), LOCK_EX) === false) {
                throw new RuntimeException('Impossibile creare .env.');
            }
            chmodSafe($releaseDir . '/.env', DEPLOY_SECRET_MODE);
        } elseif (!is_file($releaseDir . '/.env')) {
            throw new RuntimeException('Aggiornamento bloccato: .env non è stato conservato.');
        }

        fixReleasePermissions($releaseDir);
        verifyRelease($releaseDir);

        $vendorReady = is_file($releaseDir . '/vendor/autoload.php');
        $awaitingComposer = !$vendorReady || $composerChanged;

        writeJsonFile($releaseDir . '/.deploy-meta.json', [
            'repository' => DEPLOY_REPOSITORY,
            'branch' => DEPLOY_BRANCH,
            'commit' => $commit,
            'deployed_at' => gmdate(DATE_ATOM),
            'mode' => $mode,
            'status' => $awaitingComposer ? 'awaiting_composer' : 'ready',
            'composer_manifest_changed' => $composerChanged,
            'pages' => $migration['pages'],
            'assets' => $migration['assets'],
            'css_assets' => $migration['css_assets'],
            'js_assets' => $migration['js_assets'],
        ], DEPLOY_FILE_MODE);

        $backupDir = switchRelease($liveDir, $releaseDir, $stateDir, $commit);
        $releaseDir = null;
        $activated = true;
        fixReleasePermissions($liveDir);

        if ($awaitingComposer) {
            installMaintenanceRouter($documentRoot, $stateDir);
        }
        cleanupOldBackups($stateDir . '/backups', DEPLOY_BACKUPS_TO_KEEP);

        if ($workDir !== null && is_dir($workDir)) {
            removeDirectory($workDir);
            $workDir = null;
        }

        if ($awaitingComposer) {
            appendDeployLog($stateDir, [
                'time' => gmdate(DATE_ATOM),
                'commit' => $commit,
                'result' => 'awaiting_composer',
                'mode' => $mode,
                'pages' => $migration['pages'],
                'assets' => $migration['assets'],
                'composer_manifest_changed' => $composerChanged,
                'ip' => clientIp(),
            ]);

            renderDeployForm($liveDir, null, [
                'commit' => $commit,
                'backup' => $backupDir,
                'preserved' => $preserved,
                'awaiting_composer' => true,
                'composer_changed' => $composerChanged,
                'migration' => $migration,
            ]);
            return;
        }

        installRootRouter($documentRoot, $stateDir);
        $health = checkHealth();

        if (!($health['ok'] ?? false)) {
            $meta = readJsonFile($liveDir . '/.deploy-meta.json') ?? [];
            $meta['status'] = 'maintenance';
            $meta['health_status'] = $health['status'] ?? null;
            writeJsonFile($liveDir . '/.deploy-meta.json', $meta, DEPLOY_FILE_MODE);
            installMaintenanceRouter($documentRoot, $stateDir);
            throw new RuntimeException(
                'Health check Slim fallito su ' . (string) ($health['url'] ?? deployTargetUrl())
                . ' (HTTP ' . (string) ($health['status'] ?? 'nessuna risposta') . '). '
                . (($health['message'] ?? '') !== '' ? 'Errore: ' . (string) $health['message'] . '. ' : '')
                . (($health['body'] ?? '') !== '' ? 'Risposta: ' . (string) $health['body'] . '. ' : '')
                . 'Il sito è stato lasciato in manutenzione.'
            );
        }

        appendDeployLog($stateDir, [
            'time' => gmdate(DATE_ATOM),
            'commit' => $commit,
            'result' => 'success',
            'mode' => $mode,
            'pages' => $migration['pages'],
            'assets' => $migration['assets'],
            'ip' => clientIp(),
        ]);

        renderDeployForm($liveDir, null, [
            'commit' => $commit,
            'backup' => $backupDir,
            'preserved' => $preserved,
            'migration' => $migration,
            'health' => $health,
            'awaiting_composer' => false,
        ]);
    } catch (Throwable $exception) {
        if (!$activated && $releaseDir !== null && is_dir($releaseDir)) {
            removeDirectory($releaseDir);
        }
        if ($workDir !== null && is_dir($workDir)) {
            removeDirectory($workDir);
        }
        appendDeployLog($stateDir, [
            'time' => gmdate(DATE_ATOM),
            'result' => 'failed',
            'mode' => $mode,
            'message' => sanitizeLogMessage($exception->getMessage()),
            'ip' => clientIp(),
        ]);

        renderDeployForm($liveDir, $exception->getMessage());
    } finally {
        if (is_resource($lockHandle)) {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }
}

function handleComplete(string $documentRoot, string $stateDir, string $liveDir): void
{
    try {
        if ((string) ($_POST['confirmation'] ?? '') !== 'COMPLETA') {
            throw new RuntimeException('Conferma non valida: scrivi COMPLETA.');
        }
        if (!is_file($liveDir . '/.env')) {
            throw new RuntimeException('Manca .env: impossibile completare.');
        }
        if (!is_file($liveDir . '/vendor/autoload.php')) {
            throw new RuntimeException('Manca vendor/autoload.php. In Plesk Composer imposta /' . DEPLOY_LIVE_DIR . ' ed esegui Installa/Aggiorna.');
        }

        $migration = verifyRelease($liveDir);
        $meta = readJsonFile($liveDir . '/.deploy-meta.json') ?? [];
        $meta['status'] = 'testing';
        writeJsonFile($liveDir . '/.deploy-meta.json', $meta, DEPLOY_FILE_MODE);

        fixReleasePermissions($liveDir);
        installRootRouter($documentRoot, $stateDir);
        $health = checkHealth();

        if (!($health['ok'] ?? false)) {
            $meta['status'] = 'maintenance';
            $meta['health_status'] = $health['status'] ?? null;
            writeJsonFile($liveDir . '/.deploy-meta.json', $meta, DEPLOY_FILE_MODE);
            installMaintenanceRouter($documentRoot, $stateDir);
            throw new RuntimeException(
                'Health check Slim fallito su ' . (string) ($health['url'] ?? deployTargetUrl())
                . ' (HTTP ' . (string) ($health['status'] ?? 'nessuna risposta') . '). '
                . (($health['message'] ?? '') !== '' ? 'Errore: ' . (string) $health['message'] . '. ' : '')
                . (($health['body'] ?? '') !== '' ? 'Risposta: ' . (string) $health['body'] . '. ' : '')
                . 'Il sito resta in manutenzione.'
            );
        }

        $meta['status'] = 'ready';
        $meta['completed_at'] = gmdate(DATE_ATOM);
        $meta['pages'] = $migration['pages'];
        $meta['assets'] = $migration['assets'];
        unset($meta['health_status']);
        writeJsonFile($liveDir . '/.deploy-meta.json', $meta, DEPLOY_FILE_MODE);

        appendDeployLog($stateDir, [
            'time' => gmdate(DATE_ATOM),
            'commit' => (string) ($meta['commit'] ?? ''),
            'result' => 'completed',
            'pages' => $migration['pages'],
            'assets' => $migration['assets'],
            'ip' => clientIp(),
        ]);

        renderDeployForm($liveDir, null, [
            'commit' => (string) ($meta['commit'] ?? ''),
            'migration' => $migration,
            'health' => $health,
            'awaiting_composer' => false,
            'completed' => true,
        ]);
    } catch (Throwable $exception) {
        renderDeployForm($liveDir, $exception->getMessage());
    }
}

function validateRuntime(): void
{
    if (PHP_VERSION_ID < 80300) {
        throw new RuntimeException('Chiabeatslife richiede PHP 8.3 o superiore. Versione attuale: ' . PHP_VERSION);
    }

    foreach (['curl', 'zip'] as $extension) {
        if (!extension_loaded($extension)) {
            throw new RuntimeException('Estensione PHP mancante: ' . $extension);
        }
    }
}

function buildEnvFile(): string
{
    return implode("\n", [
        '# Generato da deploy.php',
        'APP_DEBUG=0',
        'APP_BASE_PATH=',
        'APP_URL=' . deployTargetUrl(),
        '',
    ]);
}

function resolveBranchCommit(): string
{
    $url = 'https://api.github.com/repos/' . DEPLOY_REPOSITORY . '/commits/' . rawurlencode(DEPLOY_BRANCH);
    $response = httpRequest($url, ['Accept: application/vnd.github+json'], 30, 2_000_000);

    if ($response['status'] !== 200) {
        throw new RuntimeException('GitHub non ha restituito il commit del branch. HTTP ' . $response['status']);
    }

    $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
    $sha = is_array($decoded) ? (string) ($decoded['sha'] ?? '') : '';

    if (!preg_match('/^[a-f0-9]{40}$/', $sha)) {
        throw new RuntimeException('SHA GitHub non valido.');
    }

    return $sha;
}

function downloadRepositorySource(string $commit, string $workDir, string $archiveFile, string $extractDir): string
{
    $apiDir = $workDir . '/api-source';

    try {
        downloadRepositoryViaApi($commit, $apiDir);
        return $apiDir;
    } catch (Throwable $apiException) {
        appendDeployDiagnostic($workDir, 'Download GitHub API fallito: ' . sanitizeLogMessage($apiException->getMessage()));
    }

    try {
        downloadArchive($commit, $archiveFile);
        extractZipSafely($archiveFile, $extractDir);
        return locateSourceDirectory($extractDir);
    } catch (Throwable $archiveException) {
        $diagnostic = readTextFileSafe($workDir . '/download-diagnostic.log');
        $prefix = $diagnostic !== '' ? trim($diagnostic) . ' | ' : '';
        throw new RuntimeException($prefix . 'Fallback ZIP GitHub fallito: ' . $archiveException->getMessage(), 0, $archiveException);
    }
}

function downloadRepositoryViaApi(string $commit, string $destination): void
{
    [$owner, $repo] = explode('/', DEPLOY_REPOSITORY, 2);
    $commitUrl = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/git/commits/' . rawurlencode($commit);
    $commitResponse = githubApiRequest($commitUrl, 30, 2_000_000);

    if ($commitResponse['status'] !== 200) {
        throw new RuntimeException('GitHub commit API HTTP ' . $commitResponse['status'] . '.');
    }

    $commitData = json_decode($commitResponse['body'], true, 512, JSON_THROW_ON_ERROR);
    $treeSha = is_array($commitData) ? (string) ($commitData['tree']['sha'] ?? '') : '';
    if (!preg_match('/^[a-f0-9]{40}$/', $treeSha)) {
        throw new RuntimeException('GitHub commit API non ha restituito un tree SHA valido.');
    }

    $treeUrl = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/git/trees/' . rawurlencode($treeSha) . '?recursive=1';
    $treeResponse = githubApiRequest($treeUrl, 45, 8_000_000);
    if ($treeResponse['status'] !== 200) {
        throw new RuntimeException('GitHub tree API HTTP ' . $treeResponse['status'] . '.');
    }

    $treeData = json_decode($treeResponse['body'], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($treeData) || !empty($treeData['truncated']) || !isset($treeData['tree']) || !is_array($treeData['tree'])) {
        throw new RuntimeException('GitHub tree API ha restituito un albero incompleto.');
    }

    mkdirOrFail($destination, 0700);
    $fileCount = 0;
    $totalBytes = 0;

    foreach ($treeData['tree'] as $entry) {
        if (!is_array($entry) || ($entry['type'] ?? null) !== 'blob') {
            continue;
        }

        $path = (string) ($entry['path'] ?? '');
        $size = (int) ($entry['size'] ?? 0);
        if (!isSafeRepositoryPath($path)) {
            throw new RuntimeException('GitHub tree contiene un percorso non sicuro: ' . $path);
        }
        if ($size > 100_000_000) {
            throw new RuntimeException('File GitHub troppo grande per il deploy: ' . $path);
        }

        $destinationFile = $destination . '/' . $path;
        mkdirOrFail(dirname($destinationFile), DEPLOY_DIR_MODE);

        $rawUrl = 'https://raw.githubusercontent.com/'
            . rawurlencode($owner) . '/' . rawurlencode($repo) . '/' . rawurlencode($commit) . '/' . rawPath($path);
        downloadRawFile($rawUrl, $destinationFile, max(1, $size));

        $actual = filesize($destinationFile);
        if ($actual === false) {
            throw new RuntimeException('Impossibile verificare il file GitHub scaricato: ' . $path);
        }
        $totalBytes += (int) $actual;
        $fileCount++;

        if ($totalBytes > DEPLOY_MAX_ARCHIVE_BYTES) {
            throw new RuntimeException('Repository oltre il limite massimo previsto dal deployer.');
        }
    }

    if ($fileCount === 0) {
        throw new RuntimeException('GitHub tree non contiene file scaricabili.');
    }
}

function githubApiRequest(string $url, int $timeout, int $maxBytes): array
{
    return httpRequestWithRetry(
        $url,
        [
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
        ],
        $timeout,
        $maxBytes,
        4
    );
}

function downloadRawFile(string $url, string $destination, int $expectedSize): void
{
    $maxBytes = min(100_000_000, max($expectedSize + 1_048_576, $expectedSize * 2));
    $last = null;

    for ($attempt = 1; $attempt <= 4; $attempt++) {
        $body = '';
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Impossibile inizializzare cURL per file GitHub.');
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_USERAGENT => 'Chiabeatslife-PurePhpDeployer/' . DEPLOY_VERSION,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, $maxBytes): int {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $message = curl_error($curl);
        curl_close($curl);

        if ($ok !== false && $status >= 200 && $status < 300 && $body !== '') {
            if (file_put_contents($destination, $body, LOCK_EX) === false) {
                throw new RuntimeException('Impossibile salvare file GitHub: ' . basename($destination));
            }
            chmodSafe($destination, DEPLOY_FILE_MODE);
            return;
        }

        $last = $message !== '' ? $message : 'HTTP ' . $status;
        if (!in_array($status, [0, 408, 425, 429, 500, 502, 503, 504], true) || $attempt === 4) {
            break;
        }
        usleep((int) (250_000 * (2 ** ($attempt - 1))));
    }

    throw new RuntimeException('Download raw GitHub fallito: ' . (string) $last);
}

function rawPath(string $path): string
{
    return implode('/', array_map('rawurlencode', explode('/', $path)));
}

function isSafeRepositoryPath(string $path): bool
{
    if ($path === '' || str_contains($path, "\0") || str_starts_with($path, '/') || str_contains($path, '\\')) {
        return false;
    }
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            return false;
        }
    }
    return true;
}

function downloadArchive(string $commit, string $destination): void
{
    $url = 'https://codeload.github.com/' . DEPLOY_REPOSITORY . '/zip/' . $commit;
    $lastError = null;

    for ($attempt = 1; $attempt <= 4; $attempt++) {
        $file = fopen($destination, 'wb');
        $curl = curl_init($url);
        if ($file === false || $curl === false) {
            if (is_resource($file)) {
                fclose($file);
            }
            throw new RuntimeException('Impossibile inizializzare il fallback ZIP GitHub.');
        }

        $written = 0;
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_USERAGENT => 'Chiabeatslife-PurePhpDeployer/' . DEPLOY_VERSION,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use ($file, &$written): int {
                $length = strlen($chunk);
                if ($written + $length > DEPLOY_MAX_ARCHIVE_BYTES) {
                    return 0;
                }
                $result = fwrite($file, $chunk);
                if ($result !== false) {
                    $written += $result;
                }
                return $result === false ? 0 : $result;
            },
        ]);

        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $message = curl_error($curl);
        curl_close($curl);
        fclose($file);

        if ($ok !== false && $status === 200 && $written > 0) {
            chmodSafe($destination, DEPLOY_SECRET_MODE);
            return;
        }

        @unlink($destination);
        $lastError = $message !== '' ? $message : 'HTTP ' . $status;
        if (!in_array($status, [0, 408, 425, 429, 500, 502, 503, 504], true) || $attempt === 4) {
            break;
        }
        usleep((int) (300_000 * (2 ** ($attempt - 1))));
    }

    throw new RuntimeException('Download GitHub ZIP fallito: ' . (string) $lastError);
}

function httpRequestWithRetry(string $url, array $headers, int $timeout, int $maxBytes, int $attempts): array
{
    $last = null;
    for ($attempt = 1; $attempt <= max(1, $attempts); $attempt++) {
        try {
            $response = httpRequest($url, $headers, $timeout, $maxBytes);
            if (!in_array($response['status'], [408, 425, 429, 500, 502, 503, 504], true) || $attempt === $attempts) {
                return $response;
            }
            $last = $response;
        } catch (Throwable $exception) {
            if ($attempt === $attempts) {
                throw $exception;
            }
        }
        usleep((int) (250_000 * (2 ** ($attempt - 1))));
    }

    return is_array($last) ? $last : ['status' => 0, 'body' => ''];
}

function httpRequest(string $url, array $headers, int $timeout, int $maxBytes): array
{
    $body = '';
    $curl = curl_init($url);

    if ($curl === false) {
        throw new RuntimeException('Impossibile inizializzare cURL.');
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'Chiabeatslife-PurePhpDeployer/' . DEPLOY_VERSION,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, $maxBytes): int {
            if (strlen($body) + strlen($chunk) > $maxBytes) {
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);

    $ok = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $message = curl_error($curl);
    curl_close($curl);

    if ($ok === false) {
        throw new RuntimeException('Richiesta HTTPS fallita: ' . $message);
    }

    return ['status' => $status, 'body' => $body];
}

function extractZipSafely(string $archive, string $destination): void
{
    $zip = new ZipArchive();
    if ($zip->open($archive) !== true) {
        throw new RuntimeException('Archivio GitHub non leggibile.');
    }

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        $normalized = str_replace('\\', '/', $name);

        if (
            $normalized === ''
            || str_contains($normalized, "\0")
            || str_starts_with($normalized, '/')
            || preg_match('~(^|/)\.\.(?:/|$)~', $normalized)
        ) {
            $zip->close();
            throw new RuntimeException('Archivio GitHub non sicuro: percorso non valido.');
        }
    }

    if (!$zip->extractTo($destination)) {
        $zip->close();
        throw new RuntimeException('Estrazione archivio GitHub fallita.');
    }

    $zip->close();
}

function locateSourceDirectory(string $extractDir): string
{
    $matches = glob($extractDir . '/*', GLOB_ONLYDIR) ?: [];
    if (count($matches) !== 1) {
        throw new RuntimeException('Root del repository non trovata univocamente nello ZIP GitHub.');
    }
    return $matches[0];
}

function validateSourceTree(string $sourceDir): void
{
    foreach ([
        '.htaccess',
        'composer.json',
        'public/index.php',
        'bootstrap/app.php',
        'config/routes.php',
        'resources/views/layout.php',
        'src/Http/ApplicationFactory.php',
        'src/Http/PageAction.php',
        'tools/import_wordpress.py',
    ] as $path) {
        if (!is_file($sourceDir . '/' . $path)) {
            throw new RuntimeException('Release GitHub incompleta: manca ' . $path . '.');
        }
    }
}

function preserveRuntimeData(string $liveDir, string $releaseDir): array
{
    $preserved = [
        'env' => false,
        'vendor' => false,
        'snapshots' => false,
    ];

    if (is_file($liveDir . '/.env')) {
        copyFileOrFail($liveDir . '/.env', $releaseDir . '/.env', DEPLOY_SECRET_MODE);
        $preserved['env'] = true;
    }

    if (!is_file($releaseDir . '/vendor/autoload.php') && is_file($liveDir . '/vendor/autoload.php')) {
        copyDirectory($liveDir . '/vendor', $releaseDir . '/vendor');
        $preserved['vendor'] = true;
    }

    return $preserved;
}

function preserveImportedSite(string $liveDir, string $releaseDir): void
{
    $sourcePages = $liveDir . '/storage/pages';
    if (is_dir($sourcePages)) {
        if (is_dir($releaseDir . '/storage/pages')) {
            removeDirectory($releaseDir . '/storage/pages');
        }
        copyDirectory($sourcePages, $releaseDir . '/storage/pages');
    }

    foreach (['storage/site-map.json', 'storage/migration-report.json', 'config/routes.php'] as $relative) {
        if (is_file($liveDir . '/' . $relative)) {
            copyFileOrFail($liveDir . '/' . $relative, $releaseDir . '/' . $relative, DEPLOY_FILE_MODE);
        }
    }

    $report = readJsonFile($liveDir . '/storage/migration-report.json');
    if ($report !== null && isset($report['asset_manifest']) && is_array($report['asset_manifest'])) {
        foreach ($report['asset_manifest'] as $asset) {
            $publicPath = is_array($asset) ? (string) ($asset['public_path'] ?? '') : '';
            if ($publicPath === '' || !str_starts_with($publicPath, '/') || str_contains($publicPath, '..')) {
                continue;
            }
            $relative = ltrim($publicPath, '/');
            if (is_file($liveDir . '/public/' . $relative)) {
                copyFileOrFail($liveDir . '/public/' . $relative, $releaseDir . '/public/' . $relative, DEPLOY_FILE_MODE);
            }
        }
    }
}

function prepareImportedSite(string $liveDir, string $releaseDir, string $stateDir, string $mode): array
{
    if ($mode === 'install') {
        return runInitialImport($releaseDir, $stateDir);
    }

    try {
        return verifyImportedSite($releaseDir);
    } catch (Throwable) {
        preserveImportedSite($liveDir, $releaseDir);
        return verifyImportedSite($releaseDir);
    }
}

function runInitialImport(string $releaseDir, string $stateDir): array
{
    if (!function_exists('proc_open')) {
        throw new RuntimeException('Import iniziale impossibile: proc_open è disabilitato in PHP. Abilitalo in Plesk per questo dominio.');
    }

    $python = findPythonBinary($stateDir);
    $command = [
        $python,
        $releaseDir . '/tools/import_wordpress.py',
        '--base-url', DEPLOY_SOURCE_URL,
        '--output', $releaseDir,
        '--max-pages', (string) DEPLOY_IMPORT_MAX_PAGES,
    ];

    $result = runProcess($command, $releaseDir, $stateDir);
    if ($result['exit_code'] !== 0) {
        throw new RuntimeException(
            "Import iniziale WordPress fallito.\n" . trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout'])
        );
    }

    return verifyImportedSite($releaseDir);
}

function findPythonBinary(string $stateDir): string
{
    $configured = trim((string) getenv('DEPLOY_PYTHON_BIN'));
    $candidates = array_values(array_unique(array_filter([$configured, 'python3', 'python'])));

    foreach ($candidates as $candidate) {
        try {
            $result = runProcess([
                $candidate,
                '-c',
                'import sys; print("%d.%d" % (sys.version_info[0], sys.version_info[1])); sys.exit(0 if sys.version_info >= (3, 6) else 2)',
            ], null, $stateDir, [], 30);
            if ($result['exit_code'] === 0) {
                return $candidate;
            }
        } catch (Throwable) {
        }
    }

    throw new RuntimeException('Python 3.6+ non disponibile sul server. Configura DEPLOY_PYTHON_BIN se Plesk lo espone con un percorso diverso.');
}

function runProcess(array $command, ?string $cwd, string $stateDir, array $env = [], int $timeout = 600): array
{
    $commandLine = implode(' ', array_map('escapeshellarg', $command));
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $environment = $env === [] ? null : array_merge($_ENV, $env);
    $process = proc_open($commandLine, $descriptors, $pipes, $cwd, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Impossibile avviare processo: ' . $command[0]);
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $started = time();

    while (true) {
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);

        $status = proc_get_status($process);
        if (!($status['running'] ?? false)) {
            break;
        }
        if (time() - $started > $timeout) {
            proc_terminate($process, 9);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            throw new RuntimeException('Processo scaduto dopo ' . $timeout . ' secondi.');
        }
        usleep(100_000);
    }

    $stdout .= (string) stream_get_contents($pipes[1]);
    $stderr .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    $stdout = substr($stdout, 0, 200_000);
    $stderr = substr($stderr, 0, 200_000);

    appendDeployLog($stateDir, [
        'time' => gmdate(DATE_ATOM),
        'result' => 'process',
        'command' => basename((string) $command[0]),
        'exit_code' => $exitCode,
        'stdout' => sanitizeLogMessage($stdout),
        'stderr' => sanitizeLogMessage($stderr),
    ]);

    return ['exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
}

function verifyImportedSite(string $releaseDir): array
{
    foreach (['storage/site-map.json', 'storage/migration-report.json', 'config/routes.php'] as $relative) {
        if (!is_file($releaseDir . '/' . $relative)) {
            throw new RuntimeException('Import incompleto: manca ' . $relative . '.');
        }
    }

    $report = readJsonFile($releaseDir . '/storage/migration-report.json');
    if ($report === null) {
        throw new RuntimeException('Import incompleto: migration-report.json non è leggibile.');
    }

    $pages = (int) ($report['pages'] ?? 0);
    $assets = (int) ($report['assets'] ?? 0);
    $css = (int) ($report['css_assets'] ?? 0);
    $js = (int) ($report['js_assets'] ?? 0);
    $pageFailures = is_array($report['page_failures'] ?? null) ? count($report['page_failures']) : 0;
    $assetFailures = is_array($report['asset_failures'] ?? null) ? count($report['asset_failures']) : 0;
    $snapshotFiles = glob($releaseDir . '/storage/pages/*.json') ?: [];

    if ($pages < 1 || count($snapshotFiles) < $pages) {
        throw new RuntimeException('Import incompleto: snapshot pagine insufficienti.');
    }
    if ($assets < 1 || $css < 1 || $js < 1) {
        throw new RuntimeException('Import incompleto: CSS/JS/asset non risultano acquisiti.');
    }
    if ($pageFailures > 0 || $assetFailures > 0) {
        throw new RuntimeException('Import incompleto: ' . $pageFailures . ' errori pagina e ' . $assetFailures . ' errori asset.');
    }

    return [
        'pages' => $pages,
        'assets' => $assets,
        'css_assets' => $css,
        'js_assets' => $js,
        'page_failures' => $pageFailures,
        'asset_failures' => $assetFailures,
    ];
}

function inspectMigrationReport(string $liveDir): array
{
    $report = readJsonFile($liveDir . '/storage/migration-report.json');
    if ($report === null) {
        return ['label' => 'non generati'];
    }
    return [
        'label' => (int) ($report['pages'] ?? 0) . ' pagine · ' . (int) ($report['assets'] ?? 0) . ' asset',
    ];
}

function fixReleasePermissions(string $releaseDir): void
{
    chmodSafe($releaseDir, DEPLOY_DIR_MODE);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($releaseDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isLink()) {
            throw new RuntimeException('La release contiene un link simbolico non consentito.');
        }
        $relative = str_replace('\\', '/', $iterator->getSubPathName());
        $mode = $item->isDir() ? DEPLOY_DIR_MODE : ($relative === '.env' ? DEPLOY_SECRET_MODE : DEPLOY_FILE_MODE);
        chmodSafe($item->getPathname(), $mode);
    }
}

function verifyRelease(string $releaseDir): array
{
    foreach (['.htaccess', '.env', 'public/index.php', 'bootstrap/app.php', 'config/routes.php', 'storage/migration-report.json'] as $relative) {
        if (!is_readable($releaseDir . '/' . $relative)) {
            throw new RuntimeException('File non leggibile dopo il deploy: ' . $relative);
        }
    }

    return verifyImportedSite($releaseDir);
}

function installMaintenanceRouter(string $documentRoot, string $stateDir): void
{
    backupRootHtaccess($documentRoot, $stateDir);

    $path = $documentRoot . '/.htaccess';
    $maintenanceFile = $documentRoot . '/' . DEPLOY_MAINTENANCE_FILE;
    $installer = preg_quote(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '/deploy.php')), '~');
    $maintenanceName = preg_quote(DEPLOY_MAINTENANCE_FILE, '~');
    $html = '<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Chiabeatslife · Manutenzione</title></head><body style="font-family:system-ui;padding:40px;max-width:720px;margin:auto"><h1>Chiabeatslife</h1><p>Aggiornamento in corso. Riprova tra poco.</p></body></html>';
    if (file_put_contents($maintenanceFile, $html, LOCK_EX) === false) {
        throw new RuntimeException('Impossibile creare la pagina di manutenzione.');
    }
    chmodSafe($maintenanceFile, DEPLOY_FILE_MODE);

    $router = "# BEGIN CHIABEATSLIFE MAINTENANCE\n"
        . "Options -Indexes\n<IfModule mod_rewrite.c>\nRewriteEngine On\n"
        . "RewriteRule ^" . $installer . "$ - [L]\n"
        . "RewriteRule ^\\.well-known/acme-challenge/ - [L]\n"
        . "RewriteRule ^" . $maintenanceName . "$ - [L]\n"
        . "RewriteRule ^ " . DEPLOY_MAINTENANCE_FILE . " [L]\n"
        . "</IfModule>\n# END CHIABEATSLIFE MAINTENANCE\n";

    if (file_put_contents($path, $router, LOCK_EX) === false) {
        throw new RuntimeException('Impossibile attivare la modalità manutenzione.');
    }
    chmodSafe($path, DEPLOY_FILE_MODE);
}

function installRootRouter(string $documentRoot, string $stateDir): void
{
    backupRootHtaccess($documentRoot, $stateDir);

    $path = $documentRoot . '/.htaccess';
    $live = preg_quote(DEPLOY_LIVE_DIR, '~');
    $state = preg_quote(DEPLOY_STATE_DIR, '~');
    $installer = preg_quote(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '/deploy.php')), '~');

    $router = "# BEGIN CHIABEATSLIFE DEPLOY ROUTER\n"
        . "Options -Indexes -MultiViews\n<IfModule mod_rewrite.c>\nRewriteEngine On\n"
        . "RewriteRule ^" . $installer . "$ - [L]\n"
        . "RewriteRule ^\\.well-known/acme-challenge/ - [L]\n"
        . "RewriteRule ^" . $state . "(?:/|$) - [F,L]\n"
        . "RewriteRule ^(?:bootstrap|config|resources|src|storage|tests|tools|vendor)(?:/|$) - [F,L,NC]\n"
        . "RewriteRule ^(?:\\.env(?:\\.example)?|composer\\.(?:json|lock)|phpunit\\.xml|README\\.md)$ - [F,L,NC]\n"
        . "RewriteCond %{THE_REQUEST} \\s/+" . $live . "(?:[/?\\s]) [NC]\n"
        . "RewriteRule ^" . $live . "(?:/|$) - [F,L]\n"
        . "RewriteCond %{DOCUMENT_ROOT}/" . DEPLOY_LIVE_DIR . "/public/$1 -f [OR]\n"
        . "RewriteCond %{DOCUMENT_ROOT}/" . DEPLOY_LIVE_DIR . "/public/$1 -d\n"
        . "RewriteRule ^(.+)$ " . DEPLOY_LIVE_DIR . "/public/$1 [L,QSA]\n"
        . "RewriteRule ^ " . DEPLOY_LIVE_DIR . "/public/index.php [L,QSA]\n"
        . "</IfModule>\n"
        . "<IfModule mod_headers.c>\n"
        . "Header always set X-Content-Type-Options \"nosniff\"\n"
        . "Header always set X-Frame-Options \"SAMEORIGIN\"\n"
        . "Header always set Referrer-Policy \"strict-origin-when-cross-origin\"\n"
        . "</IfModule>\n# END CHIABEATSLIFE DEPLOY ROUTER\n";

    if (file_put_contents($path, $router, LOCK_EX) === false) {
        throw new RuntimeException('Impossibile installare il router .htaccess.');
    }

    chmodSafe($path, DEPLOY_FILE_MODE);
    if (is_file($documentRoot . '/' . DEPLOY_MAINTENANCE_FILE)) {
        @unlink($documentRoot . '/' . DEPLOY_MAINTENANCE_FILE);
    }
}

function backupRootHtaccess(string $documentRoot, string $stateDir): void
{
    $path = $documentRoot . '/.htaccess';
    if (!is_file($path)) {
        return;
    }

    $hash = hash_file('sha256', $path);
    $lastHashFile = $stateDir . '/last-root-htaccess.sha256';
    $lastHash = is_file($lastHashFile) ? trim((string) file_get_contents($lastHashFile)) : '';

    if (is_string($hash) && $hash !== '' && hash_equals($lastHash, $hash)) {
        return;
    }

    $backup = $stateDir . '/root-htaccess-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(2)) . '.bak';
    if (@copy($path, $backup)) {
        chmodSafe($backup, DEPLOY_SECRET_MODE);
        file_put_contents($lastHashFile, $hash ?: '', LOCK_EX);
        chmodSafe($lastHashFile, DEPLOY_SECRET_MODE);
    }
}

function switchRelease(string $liveDir, string $releaseDir, string $stateDir, string $commit): ?string
{
    $backupsDir = $stateDir . '/backups';
    mkdirOrFail($backupsDir, 0700);
    $backupDir = null;

    if (is_dir($liveDir)) {
        $backupDir = $backupsDir . '/' . gmdate('YmdHis') . '-' . substr($commit, 0, 12);
        if (!rename($liveDir, $backupDir)) {
            throw new RuntimeException('Impossibile spostare la release precedente nel backup.');
        }
    }

    if (!rename($releaseDir, $liveDir)) {
        if ($backupDir !== null && is_dir($backupDir)) {
            @rename($backupDir, $liveDir);
        }
        throw new RuntimeException('Impossibile attivare la nuova release; rollback della directory eseguito.');
    }

    return $backupDir;
}

function checkHealth(): array
{
    $url = rtrim(deployTargetUrl(), '/') . DEPLOY_HEALTH_PATH;

    try {
        $response = httpRequest(
            $url,
            ['Accept: application/json', 'Cache-Control: no-cache'],
            20,
            1_000_000
        );

        $payload = json_decode($response['body'], true);
        $statusOk = $response['status'] >= 200 && $response['status'] < 300;
        $payloadOk = is_array($payload) && ($payload['status'] ?? null) === 'ok';

        return [
            'ok' => $statusOk && $payloadOk,
            'status' => $response['status'],
            'url' => $url,
            'body' => substr(trim(strip_tags((string) $response['body'])), 0, 800),
        ];
    } catch (Throwable $exception) {
        return [
            'ok' => false,
            'status' => null,
            'url' => $url,
            'body' => '',
            'message' => sanitizeLogMessage($exception->getMessage()),
        ];
    }
}

function copyDirectory(string $source, string $destination): void
{
    mkdirOrFail($destination, DEPLOY_DIR_MODE);

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $relative = $iterator->getSubPathName();
        $target = $destination . DIRECTORY_SEPARATOR . $relative;

        if ($item->isLink()) {
            throw new RuntimeException('La release contiene un link simbolico non consentito: ' . $relative);
        }

        if ($item->isDir()) {
            mkdirOrFail($target, DEPLOY_DIR_MODE);
        } else {
            mkdirOrFail(dirname($target), DEPLOY_DIR_MODE);
            if (!copy($item->getPathname(), $target)) {
                throw new RuntimeException('Copia file fallita: ' . $relative);
            }
            chmodSafe($target, DEPLOY_FILE_MODE);
        }
    }
}

function copyFileOrFail(string $source, string $destination, int $mode): void
{
    mkdirOrFail(dirname($destination), DEPLOY_DIR_MODE);
    if (!copy($source, $destination)) {
        throw new RuntimeException('Copia file fallita: ' . basename($source));
    }
    chmodSafe($destination, $mode);
}

function mkdirOrFail(string $path, int $mode): void
{
    if (!is_dir($path) && !mkdir($path, $mode, true) && !is_dir($path)) {
        throw new RuntimeException('Impossibile creare la directory: ' . $path);
    }
    chmodSafe($path, $mode);
}

function chmodSafe(string $path, int $mode): void
{
    if (!@chmod($path, $mode) && !is_readable($path)) {
        throw new RuntimeException('Permessi non applicabili e percorso non leggibile: ' . $path);
    }
}

function removeDirectory(string $path): void
{
    if (!is_dir($path)) {
        if (is_file($path)) {
            @unlink($path);
        }
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }

    @rmdir($path);
}

function cleanupOldBackups(string $directory, int $keep): void
{
    if (!is_dir($directory)) {
        return;
    }

    $backups = glob($directory . '/*', GLOB_ONLYDIR) ?: [];
    usort($backups, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

    foreach (array_slice($backups, $keep) as $backup) {
        removeDirectory($backup);
    }
}

function readJsonFile(string $file): ?array
{
    if (!is_file($file)) {
        return null;
    }

    $content = file_get_contents($file);
    if (!is_string($content) || $content === '') {
        return null;
    }

    $decoded = json_decode($content, true);
    return is_array($decoded) ? $decoded : null;
}

function writeJsonFile(string $file, array $data, int $mode = DEPLOY_SECRET_MODE): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

    if (file_put_contents($file, $json, LOCK_EX) === false) {
        throw new RuntimeException('Impossibile scrivere file JSON.');
    }

    chmodSafe($file, $mode);
}

function appendDeployLog(string $stateDir, array $entry): void
{
    $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    @file_put_contents($stateDir . '/deploy.log', $line, FILE_APPEND | LOCK_EX);

    if (is_file($stateDir . '/deploy.log')) {
        chmodSafe($stateDir . '/deploy.log', DEPLOY_SECRET_MODE);
    }
}

function appendDeployDiagnostic(string $workDir, string $message): void
{
    @file_put_contents($workDir . '/download-diagnostic.log', gmdate(DATE_ATOM) . ' ' . $message . "\n", FILE_APPEND | LOCK_EX);
}

function readTextFileSafe(string $path): string
{
    if (!is_file($path)) {
        return '';
    }
    $content = file_get_contents($path);
    return is_string($content) ? substr($content, 0, 2000) : '';
}

function sanitizeLogMessage(string $message): string
{
    $message = preg_replace('/\s+/', ' ', $message) ?? $message;
    return substr($message, 0, 1000);
}

function clientIp(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function renderResult(array $result): string
{
    $awaiting = (bool) ($result['awaiting_composer'] ?? false);
    $health = is_array($result['health'] ?? null) ? $result['health'] : [];
    $migration = is_array($result['migration'] ?? null) ? $result['migration'] : [];
    $preserved = is_array($result['preserved'] ?? null) ? $result['preserved'] : [];

    $migrationText = isset($migration['pages'])
        ? e((string) $migration['pages']) . ' pagine · ' . e((string) ($migration['assets'] ?? 0)) . ' asset · '
            . e((string) ($migration['css_assets'] ?? 0)) . ' CSS · ' . e((string) ($migration['js_assets'] ?? 0)) . ' JS'
        : 'non disponibile';

    if ($awaiting) {
        return '<div class="alert warning"><strong>Release preparata. Il sito è in manutenzione.</strong><br>'
            . 'Commit: <code>' . e((string) ($result['commit'] ?? '')) . '</code><br>'
            . 'Import: <code>' . $migrationText . '</code><br>'
            . (!empty($result['composer_changed']) ? '<strong>composer.json/composer.lock sono cambiati.</strong><br>' : '')
            . 'In Plesk → Composer usa <code>/' . e(DEPLOY_LIVE_DIR) . '</code>, esegui Installa/Aggiorna e poi torna qui con <strong>COMPLETA</strong>.</div>';
    }

    $healthText = ($health['ok'] ?? false)
        ? 'Health check Slim riuscito (HTTP ' . e((string) $health['status']) . ').'
        : 'Health check non disponibile.';

    return '<div class="alert success"><strong>Deploy completato.</strong><br>'
        . 'Commit: <code>' . e((string) ($result['commit'] ?? '')) . '</code><br>'
        . 'Import: <code>' . $migrationText . '</code><br>'
        . e($healthText)
        . (!empty($preserved['env']) ? '<br>.env conservato.' : '')
        . (!empty($preserved['vendor']) ? '<br>vendor conservato.' : '')
        . '</div>';
}

function field(string $label, string $name, string $type, string $value = '', bool $required = false, string $extra = ''): string
{
    return '<label><span>' . e($label) . '</span><input type="' . e($type) . '" name="' . e($name) . '" value="' . e($value) . '" '
        . ($required ? 'required ' : '') . $extra . '></label>';
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function renderPage(string $title, string $body): void
{
    echo '<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . e($title) . '</title><style>'
        . ':root{color-scheme:light;--bg:#f5f2ed;--card:#fff;--ink:#1d1d1f;--muted:#6c6964;--accent:#342c27;--border:#ddd6ce;--error:#8b1e1e;--warn:#805b00;--ok:#24643a}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;line-height:1.5}.shell{max-width:920px;margin:0 auto;padding:42px 20px 80px}.topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;gap:20px}.topbar strong{display:block;font-size:1.1rem}.topbar small{color:var(--muted)}.card{max-width:620px;margin:40px auto;background:var(--card);border:1px solid var(--border);border-radius:20px;padding:28px;box-shadow:0 12px 34px rgba(35,28,23,.07)}.card.wide{max-width:none;margin:0}.card h1{margin-top:0;font-size:1.75rem}.alert{padding:14px 16px;border-radius:12px;margin:18px 0;background:#f3f1ef;border:1px solid var(--border)}.alert.error{color:var(--error);background:#fff4f4;border-color:#e9c1c1}.alert.warning{color:#5e4200;background:#fff8e4;border-color:#ecd89c}.alert.success{color:#174728;background:#edf8f0;border-color:#b9dfc4}.alert.info{background:#f1f5f8;border-color:#cad8e2}.status{display:grid;grid-template-columns:minmax(140px,.6fr) minmax(0,1.4fr);gap:9px 16px;padding:14px 0 20px}.status span{color:var(--muted)}code{overflow-wrap:anywhere}fieldset{border:1px solid var(--border);border-radius:14px;padding:18px;margin:18px 0}legend{padding:0 8px;font-weight:700}label{display:grid;gap:7px;margin:14px 0}input{width:100%;padding:12px 13px;border:1px solid #c9c1b9;border-radius:10px;background:#fff;font:inherit}button{appearance:none;border:0;border-radius:10px;background:var(--accent);color:#fff;padding:12px 17px;font-weight:700;cursor:pointer}button.secondary{background:#766c65}.inline{margin:0}.inline button{padding:9px 13px}@media(max-width:600px){.shell{padding:22px 14px 60px}.topbar{align-items:flex-start}.status{grid-template-columns:1fr}.card{padding:20px;border-radius:14px}}'
        . '</style></head><body><main class="shell">' . $body . '</main></body></html>';
}
