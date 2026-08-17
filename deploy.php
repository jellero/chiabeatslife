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

const DEPLOY_REPOSITORY = 'jellero/chiabeatslife';
const DEPLOY_BRANCH = 'main';
const DEPLOY_APP_URL = 'https://chiabeatslife.jacopoellero.it';
const DEPLOY_HEALTH_PATH = '/api/v1/health';
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

    $body = '<div class="topbar"><div><strong>Chiabeatslife</strong><small>Deploy Plesk + GitHub</small></div>'
        . '<form method="post" class="inline">' . csrfField() . '<input type="hidden" name="action" value="logout"><button class="secondary" type="submit">Esci</button></form></div>'
        . '<div class="card wide"><h1>' . ($firstInstall ? 'Prima inizializzazione' : 'Aggiornamento') . '</h1>'
        . '<div class="status">'
        . '<span>Repository</span><code>' . e(DEPLOY_REPOSITORY) . '</code>'
        . '<span>Branch</span><code>' . e(DEPLOY_BRANCH) . '</code>'
        . '<span>Applicazione</span><code>' . e(DEPLOY_APP_URL) . '</code>'
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
            . '<p>Scarica <code>main</code>, verifica che siano presenti gli snapshot del sito importato, crea <code>.env</code> per il dominio Chiabeatslife e prepara la nuova release in <code>/' . e(DEPLOY_LIVE_DIR) . '</code>.</p>'
            . '<p>Il WordPress corrente non viene cancellato. Il routing pubblico viene sostituito solo dal router del deployer; i backup delle release Slim vengono conservati separatamente.</p>'
            . field('Scrivi INSTALLA', 'confirmation', 'text', '', true, 'pattern="INSTALLA" autocomplete="off"')
            . '</fieldset>'
            . '<button type="submit">Esegui prima inizializzazione</button></form></div>';
    } else {
        $body .= '<form method="post">' . csrfField()
            . '<input type="hidden" name="action" value="update">'
            . '<fieldset><legend>Aggiornamento</legend>'
            . '<p>Scarica l’ultimo commit di <code>main</code> e crea una nuova release atomica. Vengono conservati automaticamente <code>.env</code> e <code>vendor/</code>.</p>'
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
            throw new RuntimeException('Esiste già una release inizializzata: usa la modalità aggiornamento.');
        }
        if ($mode === 'update' && !$existingEnv) {
            throw new RuntimeException('Manca .env nella release corrente: devi eseguire prima l’inizializzazione.');
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
        downloadArchive($commit, $archiveFile);
        extractZipSafely($archiveFile, $extractDir);
        $sourceDir = locateSourceDirectory($extractDir);
        validateSourceTree($sourceDir);
        copyDirectory($sourceDir, $releaseDir);

        $oldComposerLockHash = hashOptionalFile($liveDir . '/composer.lock');
        $newComposerLockHash = hashOptionalFile($releaseDir . '/composer.lock');
        $oldComposerJsonHash = hashOptionalFile($liveDir . '/composer.json');
        $newComposerJsonHash = hashOptionalFile($releaseDir . '/composer.json');
        $composerChanged = $mode === 'update'
            && ($oldComposerLockHash !== $newComposerLockHash || $oldComposerJsonHash !== $newComposerJsonHash);

        $preserved = preserveRuntimeData($liveDir, $releaseDir);

        if ($mode === 'install') {
            $envContent = buildEnvFile();
            if (file_put_contents($releaseDir . '/.env', $envContent, LOCK_EX) === false) {
                throw new RuntimeException('Impossibile creare .env.');
            }
            chmodSafe($releaseDir . '/.env', DEPLOY_SECRET_MODE);
        } elseif (!is_file($releaseDir . '/.env')) {
            throw new RuntimeException('Aggiornamento bloccato: .env non è stato conservato.');
        }

        fixReleasePermissions($releaseDir);
        $migration = verifyRelease($releaseDir);

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

        cleanupOldBackups($stateDir . '/backups', DEPLOY_BACKUPS_TO_KEEP);

        if ($workDir !== null && is_dir($workDir)) {
            removeDirectory($workDir);
            $workDir = null;
        }

        if ($awaitingComposer) {
            installMaintenanceRouter($documentRoot, $stateDir);
            appendDeployLog($stateDir, [
                'time' => gmdate(DATE_ATOM),
                'commit' => $commit,
                'result' => 'awaiting_composer',
                'mode' => $mode,
                'composer_manifest_changed' => $composerChanged,
                'pages' => $migration['pages'],
                'assets' => $migration['assets'],
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
            throw new RuntimeException('La nuova release non supera l’health check Slim. Il sito è stato lasciato in manutenzione.');
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
            throw new RuntimeException('Le dipendenze sono presenti, ma l’applicazione non supera ancora l’health check Slim. Il sito resta in manutenzione.');
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
            'health' => $health,
            'migration' => $migration,
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
        'APP_URL=' . DEPLOY_APP_URL,
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

function downloadArchive(string $commit, string $destination): void
{
    $url = 'https://codeload.github.com/' . DEPLOY_REPOSITORY . '/zip/' . $commit;
    $lastError = 'errore sconosciuto';

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        @unlink($destination);
        $file = fopen($destination, 'wb');
        $curl = curl_init($url);

        if ($file === false || $curl === false) {
            if (is_resource($file)) {
                fclose($file);
            }
            throw new RuntimeException('Impossibile inizializzare il download GitHub.');
        }

        $written = 0;
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_USERAGENT => 'Chiabeatslife-PurePhpDeployer/1.0',
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

        $lastError = $message !== '' ? $message : 'HTTP ' . $status;
        @unlink($destination);

        if ($attempt < 3 && in_array($status, [429, 500, 502, 503, 504], true)) {
            sleep($attempt * 2);
            continue;
        }
        break;
    }

    throw new RuntimeException('Download GitHub fallito: ' . $lastError);
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
        CURLOPT_USERAGENT => 'Chiabeatslife-PurePhpDeployer/1.0',
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
        throw new RuntimeException('Estrazione archivio fallita.');
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
    $requiredFiles = [
        '.htaccess',
        '.env.example',
        'composer.json',
        'public/index.php',
        'public/.htaccess',
        'bootstrap/app.php',
        'config/routes.php',
        'resources/views/layout.php',
        'src/Http/ApplicationFactory.php',
        'storage/site-map.json',
        'storage/migration-report.json',
    ];

    foreach ($requiredFiles as $path) {
        if (!is_file($sourceDir . '/' . $path)) {
            throw new RuntimeException('Release GitHub incompleta: manca ' . $path . '. Esegui prima l’import del sito e committa gli snapshot.');
        }
    }

    if (!is_dir($sourceDir . '/storage/pages')) {
        throw new RuntimeException('Release GitHub incompleta: manca storage/pages.');
    }

    verifyImportedSite($sourceDir);
}

function verifyImportedSite(string $releaseDir): array
{
    $report = readJsonFile($releaseDir . '/storage/migration-report.json');
    $siteMap = readJsonFile($releaseDir . '/storage/site-map.json');

    if ($report === null || $siteMap === null) {
        throw new RuntimeException('Report/sitemap di migrazione non leggibili.');
    }

    $pages = (int) ($report['pages'] ?? 0);
    $assets = (int) ($report['assets'] ?? 0);
    $css = (int) ($report['css_assets'] ?? 0);
    $js = (int) ($report['js_assets'] ?? 0);
    $pageFailures = $report['page_failures'] ?? null;
    $assetFailures = $report['asset_failures'] ?? null;

    if ($pages < 1 || count($siteMap) < 1) {
        throw new RuntimeException('Import non valido: nessuna pagina disponibile.');
    }
    if ($css < 1 || $js < 1) {
        throw new RuntimeException('Import non valido: CSS o JavaScript legacy mancanti.');
    }
    if (!is_array($pageFailures) || $pageFailures !== []) {
        throw new RuntimeException('Import non pubblicabile: sono presenti errori pagina nel migration-report.');
    }
    if (!is_array($assetFailures) || $assetFailures !== []) {
        throw new RuntimeException('Import non pubblicabile: sono presenti errori asset nel migration-report.');
    }

    $snapshotFiles = glob($releaseDir . '/storage/pages/*.json') ?: [];
    if (count($snapshotFiles) < $pages) {
        throw new RuntimeException('Import non valido: il numero di snapshot è inferiore alle pagine dichiarate.');
    }

    foreach ($siteMap as $route) {
        if (!is_array($route)) {
            throw new RuntimeException('Sitemap interna non valida.');
        }
        $path = (string) ($route['path'] ?? '');
        $page = (string) ($route['page'] ?? '');
        if ($path === '' || $page === '' || !preg_match('/^[a-z0-9._-]+$/i', $page)) {
            throw new RuntimeException('Route importata non valida.');
        }
        if (!is_file($releaseDir . '/storage/pages/' . $page . '.json')) {
            throw new RuntimeException('Snapshot mancante per la route ' . $path . '.');
        }
    }

    $runtimeDependencies = $report['runtime_dependencies'] ?? [];
    if (!is_array($runtimeDependencies)) {
        $runtimeDependencies = [];
    }

    return [
        'pages' => $pages,
        'assets' => $assets,
        'css_assets' => $css,
        'js_assets' => $js,
        'runtime_dependencies' => array_keys($runtimeDependencies),
    ];
}

function inspectMigrationReport(string $liveDir): array
{
    if (!is_file($liveDir . '/storage/migration-report.json')) {
        return ['label' => 'non presenti'];
    }

    try {
        $report = verifyImportedSite($liveDir);
        return [
            'label' => $report['pages'] . ' pagine · ' . $report['assets'] . ' asset',
        ];
    } catch (Throwable $exception) {
        return ['label' => 'non validi'];
    }
}

function hashOptionalFile(string $path): ?string
{
    if (!is_file($path)) {
        return null;
    }

    $hash = hash_file('sha256', $path);
    return is_string($hash) ? $hash : null;
}

function preserveRuntimeData(string $liveDir, string $releaseDir): array
{
    $preserved = [
        'env' => false,
        'vendor' => false,
    ];

    if (is_file($liveDir . '/.env')) {
        if (!copy($liveDir . '/.env', $releaseDir . '/.env')) {
            throw new RuntimeException('Impossibile conservare .env.');
        }
        chmodSafe($releaseDir . '/.env', DEPLOY_SECRET_MODE);
        $preserved['env'] = true;
    }

    if (!is_file($releaseDir . '/vendor/autoload.php') && is_file($liveDir . '/vendor/autoload.php')) {
        copyDirectory($liveDir . '/vendor', $releaseDir . '/vendor');
        $preserved['vendor'] = true;
    }

    return $preserved;
}

function installMaintenanceRouter(string $documentRoot, string $stateDir): void
{
    backupRootHtaccess($documentRoot, $stateDir);

    $path = $documentRoot . '/.htaccess';
    $maintenanceFile = $documentRoot . '/' . DEPLOY_MAINTENANCE_FILE;
    $installer = preg_quote(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '/deploy.php')), '~');
    $maintenanceName = preg_quote(DEPLOY_MAINTENANCE_FILE, '~');

    $html = '<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Manutenzione</title></head><body style="font-family:system-ui;padding:40px;max-width:720px;margin:auto"><h1>Chiabeatslife</h1><p>Aggiornamento in corso. Riprova tra poco.</p></body></html>';

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
    try {
        $response = httpRequest(
            rtrim(DEPLOY_APP_URL, '/') . DEPLOY_HEALTH_PATH,
            ['Accept: application/json'],
            20,
            1_000_000
        );

        $payload = json_decode($response['body'], true);
        $statusOk = $response['status'] >= 200 && $response['status'] < 300;
        $payloadOk = is_array($payload) && ($payload['status'] ?? null) === 'ok';

        return [
            'ok' => $statusOk && $payloadOk,
            'status' => $response['status'],
        ];
    } catch (Throwable $exception) {
        return [
            'ok' => false,
            'status' => null,
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
    foreach ([
        '.htaccess',
        '.env',
        'composer.json',
        'public/index.php',
        'public/.htaccess',
        'bootstrap/app.php',
        'config/routes.php',
        'storage/site-map.json',
        'storage/migration-report.json',
    ] as $relative) {
        if (!is_readable($releaseDir . '/' . $relative)) {
            throw new RuntimeException('File non leggibile dopo il deploy: ' . $relative);
        }
    }

    return verifyImportedSite($releaseDir);
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
        throw new RuntimeException('Impossibile scrivere il file di stato.');
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

function sanitizeLogMessage(string $message): string
{
    return substr($message, 0, 500);
}

function clientIp(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function renderResult(array $result): string
{
    $awaiting = (bool) ($result['awaiting_composer'] ?? false);
    $health = $result['health'] ?? [];
    $preserved = $result['preserved'] ?? [];
    $migration = $result['migration'] ?? [];

    if ($awaiting) {
        return '<div class="alert warning"><strong>Release preparata. Il sito è in manutenzione.</strong><br>'
            . 'Commit: <code>' . e((string) ($result['commit'] ?? '')) . '</code><br>'
            . 'Snapshot: ' . e((string) ($migration['pages'] ?? '?')) . ' pagine · '
            . e((string) ($migration['assets'] ?? '?')) . ' asset.<br>'
            . (!empty($result['composer_changed']) ? '<strong>Il manifest Composer è cambiato.</strong><br>' : '')
            . 'In Plesk → Composer usa <code>/' . e(DEPLOY_LIVE_DIR) . '</code>, esegui <strong>Installa/Aggiorna</strong>, poi torna qui e premi <strong>Completa pubblicazione</strong>.</div>';
    }

    $healthText = ($health['ok'] ?? false)
        ? 'Health check Slim riuscito (HTTP ' . e((string) $health['status']) . ').'
        : 'Health check non eseguito o non riuscito.';

    return '<div class="alert success"><strong>Deploy completato.</strong><br>'
        . 'Commit: <code>' . e((string) ($result['commit'] ?? '')) . '</code><br>'
        . 'Snapshot: ' . e((string) ($migration['pages'] ?? '?')) . ' pagine · '
        . e((string) ($migration['assets'] ?? '?')) . ' asset · '
        . e((string) ($migration['css_assets'] ?? '?')) . ' CSS · '
        . e((string) ($migration['js_assets'] ?? '?')) . ' JS.<br>'
        . (!empty($preserved['env']) ? '.env conservato.<br>' : '')
        . (!empty($preserved['vendor']) ? 'vendor conservato.<br>' : '')
        . e($healthText)
        . '</div>';
}

function field(string $label, string $name, string $type = 'text', string $value = '', bool $required = false, string $extra = ''): string
{
    $requiredAttribute = $required ? ' required' : '';
    $valueAttribute = $type === 'password' ? '' : ' value="' . e($value) . '"';

    return '<label><span>' . e($label) . '</span><input type="' . e($type) . '" name="' . e($name) . '"' . $valueAttribute . $requiredAttribute . ' ' . $extra . '></label>';
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function renderPage(string $title, string $body): void
{
    echo '<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . e($title) . '</title><style>'
        . ':root{font-family:Inter,system-ui,sans-serif;color:#172019;background:#eef1ec;color-scheme:light}*{box-sizing:border-box}body{margin:0;padding:24px}code{overflow-wrap:anywhere}h1{margin-top:0}.card{max-width:560px;margin:5vh auto;background:#fff;padding:28px;border-radius:18px;box-shadow:0 14px 45px #173d2b18}.wide{max-width:900px;margin:24px auto}.topbar{max-width:900px;margin:auto;display:flex;justify-content:space-between;align-items:center}.topbar small{display:block;color:#647269;margin-top:4px}.inline{margin:0}fieldset{border:1px solid #d9dfd7;border-radius:14px;padding:18px;margin:20px 0}legend{font-weight:750;padding:0 8px}label{display:block;margin:13px 0}label span{display:block;font-weight:650;margin-bottom:7px}input{width:100%;border:1px solid #b9c4ba;border-radius:10px;padding:11px 12px;font:inherit;background:#fff}button{border:0;border-radius:10px;padding:12px 18px;background:#173d2b;color:#fff;font-weight:750;cursor:pointer}.secondary{background:#dfe6df;color:#173d2b}.alert{padding:14px 16px;border-radius:12px;margin:16px 0}.error{background:#ffe8e5;color:#7a1b10}.success{background:#e3f5e8;color:#174d28}.warning{background:#fff2cc;color:#6a4c00}.info{background:#e7eef8;color:#1f426d}.status{display:grid;grid-template-columns:140px 1fr;gap:7px 14px;background:#f3f5f1;padding:14px;border-radius:12px}.status span{color:#647269}@media(max-width:700px){body{padding:12px}.card{padding:20px}.status{grid-template-columns:1fr}.topbar{align-items:flex-start}}'
        . '</style></head><body>' . $body . '</body></html>';
}
