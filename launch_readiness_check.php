<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/billing_config.php';

require_admin_user();

$pageTitle = 'Launch Readiness Check';
$currentPage = 'launch_readiness_check';

function readiness_bool_label(bool $value): string
{
    return $value ? 'Yes' : 'No';
}

function readiness_mask_secret(?string $value): string
{
    $value = trim((string)$value);

    if ($value === '') {
        return 'Missing';
    }

    $length = strlen($value);

    if ($length <= 8) {
        return 'Present';
    }

    return substr($value, 0, 4) . '...' . substr($value, -4);
}

function readiness_add_check(array &$checks, string $section, string $label, string $status, string $details = ''): void
{
    $checks[] = [
        'section' => $section,
        'label' => $label,
        'status' => $status,
        'details' => $details,
    ];
}

function readiness_status_class(string $status): string
{
    return match ($status) {
        'pass' => 'readiness-pass',
        'warn' => 'readiness-warn',
        'fail' => 'readiness-fail',
        default => 'readiness-info',
    };
}

function readiness_status_text(string $status): string
{
    return match ($status) {
        'pass' => 'Pass',
        'warn' => 'Review',
        'fail' => 'Fail',
        default => 'Info',
    };
}

function readiness_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function readiness_columns(PDO $pdo, string $table): array
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `" . str_replace('`', '``', $table) . "`");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }

    $columns = [];

    foreach ($rows as $row) {
        $columns[(string)$row['Field']] = $row;
    }

    return $columns;
}

function readiness_file_summary(string $path): string
{
    if (!file_exists($path)) {
        return 'Missing';
    }

    $parts = [];
    $parts[] = is_readable($path) ? 'readable' : 'not readable';
    $parts[] = is_writable($path) ? 'writable' : 'not writable';
    $parts[] = 'modified ' . date('Y-m-d H:i:s', (int)filemtime($path));

    return implode(', ', $parts);
}

function readiness_tail_lines(string $path, int $maxLines = 8): array
{
    if (!is_readable($path)) {
        return [];
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if (!is_array($lines)) {
        return [];
    }

    return array_slice($lines, -$maxLines);
}

$pdo = db();
$checks = [];
$summary = [
    'pass' => 0,
    'warn' => 0,
    'fail' => 0,
    'info' => 0,
];

$databaseName = 'Unknown';

try {
    $databaseName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
} catch (Throwable $e) {
    $databaseName = 'Unavailable: ' . $e->getMessage();
}

readiness_add_check(
    $checks,
    'Environment',
    'Current environment',
    in_array(APP_ENV, ['staging', 'production'], true) ? 'pass' : 'warn',
    defined('APP_ENV') ? APP_ENV : 'APP_ENV is not defined'
);

readiness_add_check(
    $checks,
    'Environment',
    'Database name',
    $databaseName !== '' && $databaseName !== 'Unknown' ? 'pass' : 'fail',
    $databaseName
);

readiness_add_check(
    $checks,
    'Environment',
    'PHP version',
    version_compare(PHP_VERSION, '8.0.0', '>=') ? 'pass' : 'warn',
    PHP_VERSION
);

$displayErrors = strtolower((string)ini_get('display_errors'));
$displayStartupErrors = strtolower((string)ini_get('display_startup_errors'));
$productionDebugOff = !in_array($displayErrors, ['1', 'on', 'true'], true)
    && !in_array($displayStartupErrors, ['1', 'on', 'true'], true);

if (APP_ENV === 'production') {
    readiness_add_check(
        $checks,
        'Environment',
        'Debug display disabled in production',
        $productionDebugOff ? 'pass' : 'fail',
        'display_errors=' . (string)ini_get('display_errors') . ', display_startup_errors=' . (string)ini_get('display_startup_errors')
    );
} else {
    readiness_add_check(
        $checks,
        'Environment',
        'Debug display setting',
        'info',
        'display_errors=' . (string)ini_get('display_errors') . ', display_startup_errors=' . (string)ini_get('display_startup_errors')
    );
}

$rootWritable = is_writable(__DIR__);
$logsDir = __DIR__ . '/logs';
$logsDirExists = is_dir($logsDir);
$logsWritable = $logsDirExists && is_writable($logsDir);

readiness_add_check(
    $checks,
    'Files',
    'Writable log location',
    ($logsWritable || $rootWritable) ? 'pass' : 'fail',
    $logsWritable
        ? 'logs/ exists and is writable'
        : ($rootWritable ? 'app root is writable, but logs/ directory is not configured' : 'no writable log location found')
);

readiness_add_check(
    $checks,
    'Billing',
    'Billing UI enabled',
    billing_enabled() ? 'pass' : 'warn',
    'BILLING_ENABLED=' . readiness_bool_label(billing_enabled())
);

readiness_add_check(
    $checks,
    'Billing',
    'Billing enforcement enabled',
    billing_enforcement_enabled() ? 'pass' : 'warn',
    'BILLING_ENFORCEMENT_ENABLED=' . readiness_bool_label(billing_enforcement_enabled())
);

$stripeRequiredConstants = [
    'STRIPE_SECRET_KEY',
    'STRIPE_WEBHOOK_SECRET',
    'STRIPE_PRICE_COACH_MONTHLY',
    'STRIPE_PRICE_COACHPLUS_MONTHLY',
    'STRIPE_PRICE_UNLIMITED_MONTHLY',
    'STRIPE_PRICE_COACH_YEARLY',
    'STRIPE_PRICE_COACHPLUS_YEARLY',
    'STRIPE_PRICE_UNLIMITED_YEARLY',
];

$missingStripe = [];
$stripeDetails = [];

foreach ($stripeRequiredConstants as $constant) {
    if (!defined($constant) || trim((string)constant($constant)) === '') {
        $missingStripe[] = $constant;
    } else {
        $stripeDetails[] = $constant . '=' . readiness_mask_secret((string)constant($constant));
    }
}

readiness_add_check(
    $checks,
    'Billing',
    'Stripe config present',
    empty($missingStripe) ? 'pass' : 'fail',
    empty($missingStripe) ? implode(', ', $stripeDetails) : 'Missing: ' . implode(', ', $missingStripe)
);

$mailConfigPath = __DIR__ . '/includes/mail_config.php';
$mailConfig = is_file($mailConfigPath) ? require $mailConfigPath : [];
$mailRequiredKeys = ['host', 'port', 'username', 'password', 'encryption', 'from_email', 'from_name'];
$missingMail = [];
$mailDetails = [];

foreach ($mailRequiredKeys as $key) {
    $value = is_array($mailConfig) ? (string)($mailConfig[$key] ?? '') : '';

    if (trim($value) === '') {
        $missingMail[] = $key;
    } else {
        $mailDetails[] = $key . '=' . ($key === 'password' ? readiness_mask_secret($value) : $value);
    }
}

readiness_add_check(
    $checks,
    'Mail',
    'Mail config present',
    empty($missingMail) ? 'pass' : 'fail',
    empty($missingMail) ? implode(', ', $mailDetails) : 'Missing: ' . implode(', ', $missingMail)
);

$requiredTables = [
    'admin_audit_log',
    'app_settings',
    'archived_games',
    'bench_entries',
    'feature_requests',
    'feature_updates',
    'game_roster',
    'games',
    'lineup_drafts',
    'lineup_entries',
    'lineup_history',
    'lineup_templates',
    'password_resets',
    'pitch_log',
    'pitch_rest_rules',
    'pitch_rule_sets',
    'player_batting_game_stats',
    'player_batting_stats',
    'player_pitching_stats',
    'player_positions',
    'players',
    'promo_codes',
    'referral_rewards',
    'signup_attempts',
    'team_invitations',
    'team_memberships',
    'teams',
    'user_backup_codes',
    'user_feature_views',
    'user_sessions',
    'users',
];

$missingTables = [];

foreach ($requiredTables as $table) {
    if (!readiness_table_exists($pdo, $table)) {
        $missingTables[] = $table;
    }
}

readiness_add_check(
    $checks,
    'Database',
    'Required tables exist',
    empty($missingTables) ? 'pass' : 'fail',
    empty($missingTables) ? count($requiredTables) . ' required tables found' : 'Missing: ' . implode(', ', $missingTables)
);

$requiredColumns = [
    'users' => [
        'id',
        'full_name',
        'email',
        'password_hash',
        'reset_token',
        'reset_expires',
        'role',
        'is_active',
        'email_preferences_token',
        'is_admin',
    ],
    'teams' => [
        'id',
        'name',
        'theme_color',
        'plan_key',
        'billing_interval',
        'stripe_customer_id',
        'stripe_subscription_id',
        'subscription_status',
        'is_trial',
        'trial_ends_at',
        'subscription_current_period_end',
        'billing_override',
        'pitch_rule_set_id',
        'stats_enabled',
        'signup_promo_code',
    ],
    'feature_requests' => [
        'id',
        'user_id',
        'name',
        'email',
        'title',
        'description',
        'status',
        'admin_notes',
        'converted_feature_update_id',
        'created_at',
        'updated_at',
        'user_dismissed_at',
    ],
    'player_batting_game_stats' => [
        'id',
        'team_id',
        'game_db_id',
        'player_id',
        'at_bats',
        'runs',
        'hits',
        'doubles_hit',
        'triples_hit',
        'home_runs',
        'rbi',
        'walks',
        'strikeouts',
        'hit_by_pitch',
        'sacrifice_flies',
        'stolen_bases',
        'created_at',
        'updated_at',
    ],
    'referral_rewards' => [
        'id',
        'referrer_user_id',
        'referred_user_id',
        'referred_team_id',
        'reward_type',
        'reward_status',
        'rewarded_at',
        'reward_value',
        'notes',
        'redeemed_at',
        'stripe_coupon_id',
        'created_at',
    ],
];

$missingColumns = [];

foreach ($requiredColumns as $table => $columns) {
    $existingColumns = readiness_columns($pdo, $table);

    foreach ($columns as $column) {
        if (!array_key_exists($column, $existingColumns)) {
            $missingColumns[] = $table . '.' . $column;
        }
    }
}

readiness_add_check(
    $checks,
    'Database',
    'Required columns exist',
    empty($missingColumns) ? 'pass' : 'fail',
    empty($missingColumns) ? 'Key launch columns found' : 'Missing: ' . implode(', ', $missingColumns)
);

try {
    $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' OR COALESCE(is_admin, 0) = 1")->fetchColumn();
    readiness_add_check(
        $checks,
        'Access',
        'Admin account exists',
        $adminCount > 0 ? 'pass' : 'fail',
        $adminCount . ' admin account(s) found'
    );
} catch (Throwable $e) {
    readiness_add_check($checks, 'Access', 'Admin account exists', 'fail', $e->getMessage());
}

$webhookLogPath = __DIR__ . '/stripe_webhook_log.txt';
$webhookLogExists = is_file($webhookLogPath);
$webhookLogRecent = $webhookLogExists && filemtime($webhookLogPath) >= strtotime('-7 days');

readiness_add_check(
    $checks,
    'Billing',
    'Recent webhook events/log status',
    $webhookLogExists ? ($webhookLogRecent ? 'pass' : 'warn') : 'warn',
    $webhookLogExists
        ? 'stripe_webhook_log.txt exists, ' . readiness_file_summary($webhookLogPath)
        : 'stripe_webhook_log.txt has not been created yet'
);

$cronFiles = [
    'cron_trial_reminders.php',
    'cron_pitch_count_reminders.php',
];
$missingCronFiles = [];
$cronDetails = [];

foreach ($cronFiles as $cronFile) {
    $path = __DIR__ . '/' . $cronFile;

    if (!is_file($path)) {
        $missingCronFiles[] = $cronFile;
    } else {
        $cronDetails[] = $cronFile . ' (' . readiness_file_summary($path) . ')';
    }
}

readiness_add_check(
    $checks,
    'Cron',
    'Cron reminder files present',
    empty($missingCronFiles) ? 'pass' : 'fail',
    empty($missingCronFiles) ? implode('; ', $cronDetails) : 'Missing: ' . implode(', ', $missingCronFiles)
);

$migrationTables = ['schema_migrations', 'migrations'];
$migrationTableFound = null;

foreach ($migrationTables as $migrationTable) {
    if (readiness_table_exists($pdo, $migrationTable)) {
        $migrationTableFound = $migrationTable;
        break;
    }
}

if ($migrationTableFound === null) {
    readiness_add_check(
        $checks,
        'Database',
        'Schema version',
        'warn',
        'No schema_migrations or migrations table found yet'
    );
} else {
    try {
        $columns = readiness_columns($pdo, $migrationTableFound);
        $versionColumn = array_key_exists('version', $columns)
            ? 'version'
            : (array_key_exists('migration', $columns) ? 'migration' : array_key_first($columns));

        $stmt = $pdo->query("SELECT `" . str_replace('`', '``', (string)$versionColumn) . "` FROM `" . str_replace('`', '``', $migrationTableFound) . "` ORDER BY 1 DESC LIMIT 1");
        $latestVersion = (string)$stmt->fetchColumn();

        readiness_add_check(
            $checks,
            'Database',
            'Schema version',
            $latestVersion !== '' ? 'pass' : 'warn',
            $migrationTableFound . ': ' . ($latestVersion !== '' ? $latestVersion : 'no rows found')
        );
    } catch (Throwable $e) {
        readiness_add_check($checks, 'Database', 'Schema version', 'warn', $migrationTableFound . ': ' . $e->getMessage());
    }
}
$csrfFile = __DIR__ . '/includes/csrf.php';

readiness_add_check(
    $checks,
    'CSRF Protection',
    'CSRF helper file',
    is_readable($csrfFile) ? 'pass' : 'fail',
    is_readable($csrfFile)
        ? 'includes/csrf.php exists and is readable.'
        : 'includes/csrf.php is missing or not readable.'
);

$requiredCsrfFunctions = [
    'csrf_token',
    'csrf_field',
    'verify_csrf_token',
];

$missingCsrfFunctions = [];

foreach ($requiredCsrfFunctions as $functionName) {
    if (!function_exists($functionName)) {
        $missingCsrfFunctions[] = $functionName;
    }
}

readiness_add_check(
    $checks,
    'CSRF Protection',
    'CSRF functions available',
    empty($missingCsrfFunctions) ? 'pass' : 'fail',
    empty($missingCsrfFunctions)
        ? 'csrf_token(), csrf_field(), and verify_csrf_token() are available.'
        : 'Missing: ' . implode(', ', $missingCsrfFunctions) . '. Confirm includes/auth.php loads includes/csrf.php.'
);

$csrfTokenStatus = 'fail';
$csrfTokenDetail = 'CSRF token could not be generated.';

if (function_exists('csrf_token')) {
    try {
        $token = csrf_token();

        if (is_string($token) && strlen($token) >= 64) {
            $csrfTokenStatus = 'pass';
            $csrfTokenDetail = 'CSRF token can be generated and stored in the session. Token value is hidden.';
        } else {
            $csrfTokenStatus = 'warn';
            $csrfTokenDetail = 'CSRF token was generated, but its length is shorter than expected. Token value is hidden.';
        }
    } catch (Throwable $e) {
        $csrfTokenStatus = 'fail';
        $csrfTokenDetail = 'CSRF token generation failed: ' . $e->getMessage();
    }
}

readiness_add_check(
    $checks,
    'CSRF Protection',
    'CSRF token generation',
    $csrfTokenStatus,
    $csrfTokenDetail
);

readiness_add_check(
    $checks,
    'CSRF Protection',
    'CSRF form coverage audit',
    'warn',
    'Manual review still required: confirm every browser-submitted POST form includes csrf_field() and every POST handler calls verify_csrf_token(). AJAX POST requests should send X-CSRF-Token.'
);
foreach ($checks as $check) {
    $summary[$check['status']] = ($summary[$check['status']] ?? 0) + 1;
}

$sections = [];

foreach ($checks as $check) {
    $sections[$check['section']][] = $check;
}

$webhookTailLines = readiness_tail_lines($webhookLogPath, 8);

require_once __DIR__ . '/includes/header.php';
?>

<style>
.readiness-hero {
    display: grid;
    gap: 1rem;
    margin-bottom: 1.5rem;
}
.readiness-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 1rem;
    margin: 1rem 0 1.5rem;
}
.readiness-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    padding: 1rem;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.08);
}
.readiness-card h2 {
    margin-top: 0;
}
.readiness-count {
    font-size: 2rem;
    font-weight: 800;
    line-height: 1;
}
.readiness-table {
    width: 100%;
    border-collapse: collapse;
}
.readiness-table th,
.readiness-table td {
    border-bottom: 1px solid #e5e7eb;
    padding: 0.7rem;
    text-align: left;
    vertical-align: top;
}
.readiness-badge {
    display: inline-block;
    min-width: 64px;
    border-radius: 999px;
    padding: 0.2rem 0.55rem;
    font-size: 0.8rem;
    font-weight: 700;
    text-align: center;
}
.readiness-pass {
    background: #dcfce7;
    color: #166534;
}
.readiness-warn {
    background: #fef3c7;
    color: #92400e;
}
.readiness-fail {
    background: #fee2e2;
    color: #991b1b;
}
.readiness-info {
    background: #dbeafe;
    color: #1e40af;
}
.readiness-details {
    color: #475569;
    font-size: 0.92rem;
    overflow-wrap: anywhere;
}
.readiness-log {
    background: #0f172a;
    color: #e2e8f0;
    border-radius: 12px;
    padding: 1rem;
    overflow: auto;
    font-size: 0.85rem;
}
.readiness-muted {
    color: #64748b;
}
</style>

<section class="readiness-hero">
    <div>
        <h1>Launch Readiness Check</h1>
        <p class="readiness-muted">
            Admin-only diagnostic page for environment, billing, database, mail, webhook, cron, and schema readiness.
        </p>
    </div>
</section>

<section class="readiness-summary">
    <div class="readiness-card">
        <div class="readiness-count"><?= (int)$summary['pass'] ?></div>
        <div>Passing</div>
    </div>
    <div class="readiness-card">
        <div class="readiness-count"><?= (int)$summary['warn'] ?></div>
        <div>Needs Review</div>
    </div>
    <div class="readiness-card">
        <div class="readiness-count"><?= (int)$summary['fail'] ?></div>
        <div>Failing</div>
    </div>
    <div class="readiness-card">
        <div class="readiness-count"><?= (int)$summary['info'] ?></div>
        <div>Info</div>
    </div>
</section>

<?php foreach ($sections as $sectionName => $sectionChecks): ?>
    <section class="readiness-card">
        <h2><?= h((string)$sectionName) ?></h2>
        <table class="readiness-table">
            <thead>
                <tr>
                    <th>Check</th>
                    <th>Status</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sectionChecks as $check): ?>
                    <tr>
                        <td><?= h((string)$check['label']) ?></td>
                        <td>
                            <span class="readiness-badge <?= h(readiness_status_class((string)$check['status'])) ?>">
                                <?= h(readiness_status_text((string)$check['status'])) ?>
                            </span>
                        </td>
                        <td class="readiness-details"><?= h((string)$check['details']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
<?php endforeach; ?>

<section class="readiness-card">
    <h2>Recent Stripe Webhook Log Lines</h2>
    <?php if (!empty($webhookTailLines)): ?>
        <pre class="readiness-log"><?= h(implode("\n", $webhookTailLines)) ?></pre>
    <?php else: ?>
        <p class="readiness-muted">No readable webhook log lines found yet.</p>
    <?php endif; ?>
</section>

<?php
require_once __DIR__ . '/includes/footer.php';
