<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_login();

$pageTitle = 'Search';
$currentPage = 'search';

$teamId = current_team_id();
$query = trim((string)($_GET['q'] ?? ''));

$results = [];

if ($query !== '' && $teamId > 0) {

    /*
    |--------------------------------------------------------------------------
    | NORMALIZE QUERY
    |--------------------------------------------------------------------------
    */

    $normalizedQuery = strtolower($query);
    $normalizedQuery = trim(preg_replace('/\s+/', ' ', $normalizedQuery));

    /*
    |--------------------------------------------------------------------------
    | ALIASES / TYPO CLEANUP
    |--------------------------------------------------------------------------
    */

    $aliases = [
        'stolen base' => 'stolen bases',
        'steal' => 'stolen bases',
        'steals' => 'stolen bases',
        'sb' => 'stolen bases',

        'rbi leader' => 'most rbi',
        'hit leader' => 'most hits',

        'k leader' => 'most strikeouts',
        'ks' => 'most strikeouts',
        'strikeout leaders' => 'most strikeouts',

        'top batting' => 'top hitters',
        'best batting' => 'top hitters',
        'batting leaders' => 'top hitters',

        'top pitching' => 'top pitchers',
        'best pitching' => 'top pitchers',
        'pitching leaders' => 'top pitchers',

        'latest games' => 'recent games',
        'last games' => 'recent games',
    ];

    foreach ($aliases as $from => $to) {

        if (
            $normalizedQuery === $from
            || str_contains($normalizedQuery, $from)
        ) {
            $normalizedQuery = str_replace(
                $from,
                $to,
                $normalizedQuery
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | COMMAND SEARCHES
    |--------------------------------------------------------------------------
    */

    if (str_starts_with($normalizedQuery, 'player:')) {

        $playerSearch = trim(
            substr($normalizedQuery, 7)
        );

        $stmt = db()->prepare("
            SELECT
                'Player Command' AS type,

                CONCAT(
                    p.first_name,
                    ' ',
                    p.last_name
                ) AS title,

                CONCAT(
                    'Jersey #',
                    COALESCE(p.jersey_number, '')
                ) AS subtitle,

                CONCAT(
                    'player_profiles.php?player_id=',
                    p.id
                ) AS url

            FROM players p

            WHERE p.team_id = :team_id
              AND (
                    p.first_name LIKE :q
                 OR p.last_name LIKE :q
              )

            ORDER BY p.last_name ASC

            LIMIT 20
        ");

        $stmt->execute([
            'team_id' => $teamId,
            'q' => '%' . $playerSearch . '%',
        ]);

        $results = array_merge(
            $results,
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    if (str_starts_with($normalizedQuery, 'game:')) {

        $gameSearch = trim(
            substr($normalizedQuery, 5)
        );

        $stmt = db()->prepare("
            SELECT
                'Game Command' AS type,

                g.game_id AS title,

                CONCAT(
                    'Status: ',
                    g.status,
                    ' | Date: ',
                    COALESCE(g.game_date, 'Not set')
                ) AS subtitle,

                CONCAT(
                    'game_stats.php?game_id=',
                    g.id
                ) AS url

            FROM games g

            WHERE g.team_id = :team_id
              AND g.deleted_at IS NULL
              AND (
                    g.game_id LIKE :q
                 OR g.status LIKE :q
                 OR g.game_date LIKE :q
              )

            ORDER BY
                CASE
                    WHEN g.game_date IS NULL THEN 1
                    ELSE 0
                END,
                g.game_date DESC,
                g.id DESC

            LIMIT 20
        ");

        $stmt->execute([
            'team_id' => $teamId,
            'q' => '%' . $gameSearch . '%',
        ]);

        $results = array_merge(
            $results,
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | SMART SEARCH INTENTS
    |--------------------------------------------------------------------------
    */

    $smartSearches = [

        'recent_games' => [
            'recent games'
        ],

        'top_hitters' => [
            'top hitters',
            'best hitters'
        ],

        'top_pitchers' => [
            'top pitchers',
            'best pitchers'
        ],

        'stolen_bases' => [
            'stolen bases',
            'most stolen bases',
            'stolen bases leader'
        ],

        'strikeouts_pitching' => [
            'most strikeouts',
            'strikeout leader',
            'pitching strikeouts'
        ],

        'era' => [
            'best era',
            'lowest era',
            'era leader'
        ],

        'whip' => [
            'best whip',
            'lowest whip',
            'whip leader'
        ],

        'ops' => [
            'best ops',
            'highest ops',
            'ops leader'
        ],

        'avg' => [
            'best average',
            'highest average',
            'batting average'
        ],

        'hits' => [
            'most hits'
        ],

        'rbi' => [
            'most rbi'
        ],
    ];

    $smartIntent = null;

    foreach ($smartSearches as $intent => $phrases) {

        foreach ($phrases as $phrase) {

            if (
                str_contains(
                    $normalizedQuery,
                    $phrase
                )
            ) {
                $smartIntent = $intent;
                break 2;
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | RECENT GAMES
    |--------------------------------------------------------------------------
    */

    if ($smartIntent === 'recent_games') {

        $stmt = db()->prepare("
            SELECT
                'Recent Game' AS type,

                g.game_id AS title,

                CONCAT(
                    'Status: ',
                    g.status,
                    ' | Date: ',
                    COALESCE(g.game_date, 'Not set'),
                    ' | ',
                    COALESCE(
                        UPPER(g.home_away),
                        'Home/Away not set'
                    )
                ) AS subtitle,

                CONCAT(
                    'game_stats.php?game_id=',
                    g.id
                ) AS url

            FROM games g

            WHERE g.team_id = :team_id
              AND g.deleted_at IS NULL

            ORDER BY
                CASE
                    WHEN g.game_date IS NULL THEN 1
                    ELSE 0
                END,
                g.game_date DESC,
                g.created_at DESC,
                g.id DESC

            LIMIT 10
        ");

        $stmt->execute([
            'team_id' => $teamId,
        ]);

        $results = array_merge(
            $results,
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | STOLEN BASES
    |--------------------------------------------------------------------------
    */

    if ($smartIntent === 'stolen_bases') {

        $stmt = db()->prepare("
            SELECT
                'SB Leader' AS type,

                CONCAT(
                    p.first_name,
                    ' ',
                    p.last_name
                ) AS title,

                CONCAT(
                    'SB: ',
                    COALESCE(SUM(bs.stolen_bases), 0)
                ) AS subtitle,

                'player_stats.php?batting_sort=sb' AS url

            FROM players p

            INNER JOIN player_batting_stats bs
                ON bs.player_id = p.id
               AND bs.team_id = p.team_id

            WHERE p.team_id = :team_id

            GROUP BY p.id

            ORDER BY SUM(bs.stolen_bases) DESC

            LIMIT 10
        ");

        $stmt->execute([
            'team_id' => $teamId,
        ]);

        $results = array_merge(
            $results,
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | MOST HITS
    |--------------------------------------------------------------------------
    */

    if ($smartIntent === 'hits') {

        $stmt = db()->prepare("
            SELECT
                'Hit Leader' AS type,

                CONCAT(
                    p.first_name,
                    ' ',
                    p.last_name
                ) AS title,

                CONCAT(
                    'Hits: ',
                    COALESCE(SUM(bs.hits), 0)
                ) AS subtitle,

                'player_stats.php?batting_sort=hits' AS url

            FROM players p

            INNER JOIN player_batting_stats bs
                ON bs.player_id = p.id
               AND bs.team_id = p.team_id

            WHERE p.team_id = :team_id

            GROUP BY p.id

            ORDER BY SUM(bs.hits) DESC

            LIMIT 10
        ");

        $stmt->execute([
            'team_id' => $teamId,
        ]);

        $results = array_merge(
            $results,
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | MOST RBI
    |--------------------------------------------------------------------------
    */

    if ($smartIntent === 'rbi') {

        $stmt = db()->prepare("
            SELECT
                'RBI Leader' AS type,

                CONCAT(
                    p.first_name,
                    ' ',
                    p.last_name
                ) AS title,

                CONCAT(
                    'RBI: ',
                    COALESCE(SUM(bs.rbi), 0)
                ) AS subtitle,

                'player_stats.php?batting_sort=rbi' AS url

            FROM players p

            INNER JOIN player_batting_stats bs
                ON bs.player_id = p.id
               AND bs.team_id = p.team_id

            WHERE p.team_id = :team_id

            GROUP BY p.id

            ORDER BY SUM(bs.rbi) DESC

            LIMIT 10
        ");

        $stmt->execute([
            'team_id' => $teamId,
        ]);

        $results = array_merge(
            $results,
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | OPS / AVG LEADERS
    |--------------------------------------------------------------------------
    */

    if (
        $smartIntent === 'ops'
        || $smartIntent === 'avg'
        || $smartIntent === 'top_hitters'
    ) {

        $sortUrl =
            $smartIntent === 'avg'
            ? 'avg'
            : 'ops';

        $stmt = db()->prepare("
            SELECT
                'Top Hitter' AS type,

                CONCAT(
                    p.first_name,
                    ' ',
                    p.last_name
                ) AS title,

                CONCAT(
                    'AVG: ',
                    FORMAT(
                        CASE
                            WHEN SUM(bs.at_bats) > 0
                            THEN SUM(bs.hits) / SUM(bs.at_bats)
                            ELSE 0
                        END,
                        3
                    ),

                    ' | OPS: ',

                    FORMAT(
                        (
                            CASE
                                WHEN (
                                    SUM(bs.at_bats)
                                    + SUM(bs.walks)
                                    + SUM(bs.hit_by_pitch)
                                    + SUM(bs.sacrifice_flies)
                                ) > 0

                                THEN (
                                    SUM(bs.hits)
                                    + SUM(bs.walks)
                                    + SUM(bs.hit_by_pitch)
                                ) / (
                                    SUM(bs.at_bats)
                                    + SUM(bs.walks)
                                    + SUM(bs.hit_by_pitch)
                                    + SUM(bs.sacrifice_flies)
                                )

                                ELSE 0
                            END
                        )

                        +

                        (
                            CASE
                                WHEN SUM(bs.at_bats) > 0
                                THEN (
                                    SUM(bs.hits)
                                    + SUM(bs.doubles_hit)
                                    + (SUM(bs.triples_hit) * 2)
                                    + (SUM(bs.home_runs) * 3)
                                ) / SUM(bs.at_bats)

                                ELSE 0
                            END
                        ),

                        3
                    )
                ) AS subtitle,

                CONCAT(
                    'player_stats.php?batting_sort=',
                    :sort_url
                ) AS url

            FROM players p

            INNER JOIN player_batting_stats bs
                ON bs.player_id = p.id
               AND bs.team_id = p.team_id

            WHERE p.team_id = :team_id

            GROUP BY p.id

            HAVING SUM(bs.at_bats) > 0

            ORDER BY

                CASE
                    WHEN :intent = 'avg'

                    THEN
                        SUM(bs.hits)
                        / NULLIF(SUM(bs.at_bats), 0)

                    ELSE
                        (
                            (
                                SUM(bs.hits)
                                + SUM(bs.walks)
                                + SUM(bs.hit_by_pitch)
                            )
                            /
                            NULLIF(
                                (
                                    SUM(bs.at_bats)
                                    + SUM(bs.walks)
                                    + SUM(bs.hit_by_pitch)
                                    + SUM(bs.sacrifice_flies)
                                ),
                                0
                            )
                        )

                        +

                        (
                            (
                                SUM(bs.hits)
                                + SUM(bs.doubles_hit)
                                + (SUM(bs.triples_hit) * 2)
                                + (SUM(bs.home_runs) * 3)
                            )
                            /
                            NULLIF(SUM(bs.at_bats), 0)
                        )
                END DESC

            LIMIT 10
        ");

        $stmt->execute([
            'team_id' => $teamId,
            'sort_url' => $sortUrl,
            'intent' => $smartIntent,
        ]);

        $results = array_merge(
            $results,
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PITCHING LEADERS
    |--------------------------------------------------------------------------
    */

    if (
        in_array(
            $smartIntent,
            [
                'era',
                'whip',
                'strikeouts_pitching',
                'top_pitchers'
            ],
            true
        )
    ) {

        $orderBy = match ($smartIntent) {

            'era' => 'era ASC',

            'whip' => 'whip ASC',

            default => 'strikeouts DESC',
        };

        $sortUrl = match ($smartIntent) {

            'era' => 'era',

            'whip' => 'whip',

            default => 'k',
        };

        $stmt = db()->prepare("
            SELECT *

            FROM (

                SELECT
                    'Top Pitcher' AS type,

                    CONCAT(
                        p.first_name,
                        ' ',
                        p.last_name
                    ) AS title,

                    CONCAT(
                        'IP: ',
                        COALESCE(SUM(ps.innings_pitched), 0),

                        ' | ERA: ',

                        FORMAT(
                            (
                                COALESCE(
                                    SUM(ps.earned_runs),
                                    0
                                ) * 7
                            ) /
                            NULLIF(
                                SUM(ps.innings_pitched),
                                0
                            ),
                            2
                        ),

                        ' | WHIP: ',

                        FORMAT(
                            (
                                COALESCE(SUM(ps.walks), 0)
                                +
                                COALESCE(SUM(ps.hits_allowed), 0)
                            ) /
                            NULLIF(
                                SUM(ps.innings_pitched),
                                0
                            ),
                            2
                        ),

                        ' | K: ',

                        COALESCE(
                            SUM(ps.strikeouts),
                            0
                        )
                    ) AS subtitle,

                    CONCAT(
                        'player_stats.php?pitching_sort=',
                        :sort_url
                    ) AS url,

                    COALESCE(
                        SUM(ps.strikeouts),
                        0
                    ) AS strikeouts,

                    (
                        (
                            COALESCE(
                                SUM(ps.earned_runs),
                                0
                            ) * 7
                        )
                        /
                        NULLIF(
                            SUM(ps.innings_pitched),
                            0
                        )
                    ) AS era,

                    (
                        (
                            COALESCE(SUM(ps.walks), 0)
                            +
                            COALESCE(SUM(ps.hits_allowed), 0)
                        )
                        /
                        NULLIF(
                            SUM(ps.innings_pitched),
                            0
                        )
                    ) AS whip

                FROM players p

                INNER JOIN player_pitching_stats ps
                    ON ps.player_id = p.id
                   AND ps.team_id = p.team_id

                WHERE p.team_id = :team_id

                GROUP BY p.id

                HAVING SUM(ps.innings_pitched) > 0

            ) ranked

            ORDER BY {$orderBy}

            LIMIT 10
        ");

        $stmt->execute([
            'team_id' => $teamId,
            'sort_url' => $sortUrl,
        ]);

        $results = array_merge(
            $results,
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PAGE CONTENT SEARCH
    |--------------------------------------------------------------------------
    */

    $pageSearchIndex = [

        [
            'type' => 'Page',
            'title' => 'Dashboard',
            'subtitle' => 'Main BenchBuddy dashboard overview.',
            'url' => 'index.php',
            'keywords' => 'dashboard home overview benchbuddy main'
        ],

        [
            'type' => 'Page',
            'title' => 'Games',
            'subtitle' => 'Create, edit, manage, and finalize games.',
            'url' => 'games.php',
            'keywords' => 'games schedule create game roster innings home away'
        ],

        [
            'type' => 'Page',
            'title' => 'Players',
            'subtitle' => 'Manage player roster, positions, pitching roles, and availability.',
            'url' => 'players.php',
            'keywords' => 'players roster positions pitching roles catching availability active inactive'
        ],

        [
            'type' => 'Page',
            'title' => 'Player Stats',
            'subtitle' => 'Batting and pitching stats, OPS, ERA, WHIP, stolen bases, and leaderboards.',
            'url' => 'player_stats.php',
            'keywords' => 'player stats batting pitching ops era whip average avg obp slugging hits rbi stolen bases strikeouts'
        ],

        [
            'type' => 'Page',
            'title' => 'Game Stats',
            'subtitle' => 'Enter batting and pitching stats for games.',
            'url' => 'game_stats.php',
            'keywords' => 'game stats enter batting pitching stats earned runs innings pitches whip era'
        ],

        [
            'type' => 'Page',
            'title' => 'Manual Lineup',
            'subtitle' => 'Manually edit defensive positions and bench assignments inning by inning.',
            'url' => 'manual_lineup.php',
            'keywords' => 'manual lineup lineup editor positions bench inning substitutions swap mode'
        ],

        [
            'type' => 'Page',
            'title' => 'Generate Lineup',
            'subtitle' => 'Automatically generate balanced defensive lineups.',
            'url' => 'generate_lineup.php',
            'keywords' => 'generate lineup auto lineup automatic lineup builder positions innings'
        ],

        [
            'type' => 'Page',
            'title' => 'Pitch Tracker',
            'subtitle' => 'Track live pitch counts and innings pitched.',
            'url' => 'pitch_tracker.php',
            'keywords' => 'pitch tracker pitch count innings pitched live pitches arm care'
        ],

        [
            'type' => 'Page',
            'title' => 'Pitch Rules',
            'subtitle' => 'Pitch count rules, rest requirements, and pitcher restrictions.',
            'url' => 'pitch_rules.php',
            'keywords' => 'pitch rules pitch count limits restrictions rest days pitching rules'
        ],

        [
            'type' => 'Page',
            'title' => 'History',
            'subtitle' => 'Review finalized games, lineups, stats, and pitch logs.',
            'url' => 'history.php',
            'keywords' => 'history archived finalized games lineups pitch logs stats'
        ],

        [
            'type' => 'Page',
            'title' => 'Account Settings',
            'subtitle' => 'Manage team settings, billing, members, and preferences.',
            'url' => 'account.php',
            'keywords' => 'account settings billing stripe subscription team members preferences stats recording'
        ],

        [
            'type' => 'Page',
            'title' => 'Team Members',
            'subtitle' => 'Invite and manage coaches or assistants.',
            'url' => 'team_members.php',
            'keywords' => 'team members coaches assistants invite permissions access'
        ],

        [
            'type' => 'Page',
            'title' => 'Pitch Log',
            'subtitle' => 'View historical pitch counts and innings pitched.',
            'url' => 'pitch_log.php',
            'keywords' => 'pitch log pitch history innings pitched workload'
        ],

        [
            'type' => 'Page',
            'title' => 'Lineup History',
            'subtitle' => 'Review saved and finalized lineups.',
            'url' => 'lineup_history.php',
            'keywords' => 'lineup history saved lineups defensive assignments batting order'
        ],

        [
            'type' => 'Page',
            'title' => 'Sitemap',
            'subtitle' => 'Browse all BenchBuddy pages and navigation links.',
            'url' => 'sitemap.php',
            'keywords' => 'sitemap navigation pages links'
        ],

        [
            'type' => 'Page',
            'title' => 'Search',
            'subtitle' => 'Search players, games, stats, pitch logs, and pages.',
            'url' => 'search.php',
            'keywords' => 'search find lookup stats players games pitch logs'
        ],

        [
            'type' => 'Page',
            'title' => 'Login',
            'subtitle' => 'Sign into BenchBuddy.',
            'url' => 'login.php',
            'keywords' => 'login sign in authentication'
        ],

        [
            'type' => 'Page',
            'title' => 'Register',
            'subtitle' => 'Create a new BenchBuddy account.',
            'url' => 'register.php',
            'keywords' => 'register signup create account'
        ],

        [
            'type' => 'Page',
            'title' => 'Forgot Password',
            'subtitle' => 'Reset your BenchBuddy password.',
            'url' => 'forgot_password.php',
            'keywords' => 'forgot password reset account recovery'
        ],

        [
            'type' => 'Page',
            'title' => '404 Not Found',
            'subtitle' => 'Custom BenchBuddy baseball-themed error page.',
            'url' => '404.php',
            'keywords' => '404 not found missing page baseball'
        ],
        [
            'type' => 'Page',
            'title' => 'Print Lineup',
            'subtitle' => 'Printable defensive lineup cards and batting orders for games.',
            'url' => 'print_lineup.php',
            'keywords' => 'print lineup printable lineup batting order lineup card print game lineup'
        ],

        [
            'type' => 'Page',
            'title' => 'Print Blank Lineup',
            'subtitle' => 'Printable blank baseball and softball lineup templates.',
            'url' => 'print_blank_lineup.php',
            'keywords' => 'blank lineup printable template empty lineup card baseball softball'
        ],
        [
            'type' => 'Page',
            'title' => 'What’s New',
            'subtitle' => 'Latest BenchBuddy updates, features, improvements, and releases.',
            'url' => 'features.php',
            'keywords' => 'whats new updates changelog releases new features improvements'
        ],

        [
            'type' => 'Page',
            'title' => 'Privacy Policy',
            'subtitle' => 'BenchBuddy privacy policy and data handling.',
            'url' => 'privacy_policy.php',
            'keywords' => 'privacy policy data privacy terms legal'
        ],

        [
            'type' => 'Page',
            'title' => 'Terms of Service',
            'subtitle' => 'BenchBuddy terms and conditions.',
            'url' => 'terms.php',
            'keywords' => 'terms conditions legal service agreement'
        ],

        [
            'type' => 'Page',
            'title' => 'Cookie Policy',
            'subtitle' => 'Information about cookies and tracking.',
            'url' => 'cookie_policy.php',
            'keywords' => 'cookies cookie policy tracking analytics'
        ],

        [
            'type' => 'Page',
            'title' => 'Build Lineup',
            'subtitle' => 'Generate and manage defensive lineups.',
            'url' => 'build_lineup.php',
            'keywords' => 'build lineup lineup builder generate lineup batting order'
        ],

        [
            'type' => 'Page',
            'title' => 'Finalize Game',
            'subtitle' => 'Lock and finalize completed games.',
            'url' => 'lock.php',
            'keywords' => 'finalize game lock game completed archive lineup stats'
        ],

        [
            'type' => 'Page',
            'title' => 'Cancelled Games',
            'subtitle' => 'Review cancelled or deleted games.',
            'url' => 'cancelled_games.php',
            'keywords' => 'cancelled games deleted postponed removed games'
        ],

        [
            'type' => 'Page',
            'title' => 'Add Pitch Counts',
            'subtitle' => 'Enter and manage pitch counts for players.',
            'url' => 'add_pitch_counts.php',
            'keywords' => 'add pitch counts pitch tracker pitches innings workload'
        ],

        [
            'type' => 'Page',
            'title' => 'Lineup Templates',
            'subtitle' => 'Saved lineup templates for quick game builds.',
            'url' => 'lineup_templates.php',
            'keywords' => 'lineup templates lineup blank lineup cards'
        ],

        [
            'type' => 'Page',
            'title' => 'Referrals',
            'subtitle' => 'Invite coaches and manage referral rewards.',
            'url' => 'referrals.php',
            'keywords' => 'referrals invite rewards affiliate coaches'
        ],

        [
            'type' => 'Page',
            'title' => 'Admin Dashboard',
            'subtitle' => 'BenchBuddy administrative dashboard.',
            'url' => 'admin_dashboard.php',
            'keywords' => 'admin dashboard administration analytics'
        ],

        [
            'type' => 'Page',
            'title' => 'Admin Users',
            'subtitle' => 'Manage BenchBuddy users and accounts.',
            'url' => 'admin_users.php',
            'keywords' => 'admin users manage users accounts teams'
        ],

        [
            'type' => 'Page',
            'title' => 'Features Admin',
            'subtitle' => 'Manage BenchBuddy feature flags and releases.',
            'url' => 'feature_admin.php',
            'keywords' => 'features admin feature flags releases beta tools'
        ],

        [
            'type' => 'Page',
            'title' => 'Feature Requests Admin',
            'subtitle' => 'Review and manage feature requests.',
            'url' => 'feature_requests.php',
            'keywords' => 'feature requests admin roadmap suggestions feedback'
        ],
    ];

    foreach ($pageSearchIndex as $page) {
        $haystack = strtolower(
            $page['title'] . ' ' .
            $page['subtitle'] . ' ' .
            $page['keywords']
        );

        if (str_contains($haystack, $normalizedQuery)) {
            $results[] = [
                'type' => $page['type'],
                'title' => $page['title'],
                'subtitle' => $page['subtitle'],
                'url' => $page['url'],
            ];
        }
    }

    /*
    |--------------------------------------------------------------------------
    | GENERAL SEARCH
    |--------------------------------------------------------------------------
    */

    $like = '%' . $query . '%';

    $stmt = db()->prepare("
        SELECT
            'Player' AS type,

            CONCAT(
                p.first_name,
                ' ',
                p.last_name
            ) AS title,

            CONCAT(
                'Jersey #',
                COALESCE(p.jersey_number, '')
            ) AS subtitle,

            CONCAT(
                'player_profiles.php?player_id=',
                p.id
            ) AS url

        FROM players p

        WHERE p.team_id = :team_id
          AND p.active = 1
          AND (
                p.first_name LIKE :q
             OR p.last_name LIKE :q
             OR p.jersey_number LIKE :q
             OR p.pitching_role LIKE :q
             OR p.catching_role LIKE :q
          )

        UNION ALL

        SELECT
            'Game' AS type,

            g.game_id AS title,

            CONCAT(
                'Status: ',
                g.status,
                ' | Date: ',
                COALESCE(g.game_date, 'Not set')
            ) AS subtitle,

            CONCAT(
                'history.php?status=all&game_id=',
                g.id
            ) AS url

        FROM games g

        WHERE g.team_id = :team_id
          AND g.deleted_at IS NULL
          AND (
                g.game_id LIKE :q
             OR g.status LIKE :q
             OR g.game_date LIKE :q
             OR g.home_away LIKE :q
          )

        UNION ALL

        SELECT
            'Batting Stats' AS type,

            CONCAT(
                p.first_name,
                ' ',
                p.last_name
            ) AS title,

            CONCAT(
                'H: ',
                COALESCE(SUM(bs.hits), 0),

                ' | RBI: ',
                COALESCE(SUM(bs.rbi), 0),

                ' | SB: ',
                COALESCE(SUM(bs.stolen_bases), 0)
            ) AS subtitle,

            'player_stats.php' AS url

        FROM players p

        INNER JOIN player_batting_stats bs
            ON bs.player_id = p.id
           AND bs.team_id = p.team_id

        WHERE p.team_id = :team_id
          AND (
                p.first_name LIKE :q
             OR p.last_name LIKE :q
             OR p.jersey_number LIKE :q
             OR 'hits' LIKE :q
             OR 'rbi' LIKE :q
             OR 'stolen bases' LIKE :q
             OR 'batting' LIKE :q
          )

        GROUP BY p.id

        UNION ALL

        SELECT
            'Pitching Stats' AS type,

            CONCAT(
                p.first_name,
                ' ',
                p.last_name
            ) AS title,

            CONCAT(
                'IP: ',
                COALESCE(SUM(ps.innings_pitched), 0),

                ' | K: ',
                COALESCE(SUM(ps.strikeouts), 0),

                ' | BB: ',
                COALESCE(SUM(ps.walks), 0)
            ) AS subtitle,

            'player_stats.php' AS url

        FROM players p

        INNER JOIN player_pitching_stats ps
            ON ps.player_id = p.id
           AND ps.team_id = p.team_id

        WHERE p.team_id = :team_id
          AND (
                p.first_name LIKE :q
             OR p.last_name LIKE :q
             OR p.jersey_number LIKE :q
             OR 'era' LIKE :q
             OR 'whip' LIKE :q
             OR 'strikeouts' LIKE :q
             OR 'pitching' LIKE :q
          )

        GROUP BY p.id

        UNION ALL

        SELECT
            'Pitch Log' AS type,

            CONCAT(
                p.first_name,
                ' ',
                p.last_name
            ) AS title,

            CONCAT(
                'Game: ',
                g.game_id,

                ' | IP: ',
                COALESCE(pl.innings_pitched, 0),

                ' | Pitches: ',
                COALESCE(pl.pitches_thrown, 0)
            ) AS subtitle,

            CONCAT(
                'lock.php?game_id=',
                g.id
            ) AS url

        FROM pitch_log pl

        INNER JOIN players p
            ON p.id = pl.player_id
           AND p.team_id = pl.team_id

        INNER JOIN games g
            ON g.id = pl.game_db_id
           AND g.team_id = pl.team_id

        WHERE pl.team_id = :team_id
          AND (
                p.first_name LIKE :q
             OR p.last_name LIKE :q
             OR p.jersey_number LIKE :q
             OR g.game_id LIKE :q
             OR 'pitch count' LIKE :q
             OR 'pitches' LIKE :q
          )

        ORDER BY type ASC, title ASC

        LIMIT 75
    ");

    $stmt->execute([
        'team_id' => $teamId,
        'q' => $like,
    ]);

    $results = array_merge(
        $results,
        $stmt->fetchAll(PDO::FETCH_ASSOC)
    );

    /*
    |--------------------------------------------------------------------------
    | REMOVE DUPLICATES
    |--------------------------------------------------------------------------
    */

    $results = array_values(array_unique($results, SORT_REGULAR));
}

require_once __DIR__ . '/includes/header.php';
?>

<h1 class="page-title brand-title-font">Search</h1>

<div class="card">
  <form
    method="get"
    action="search.php"
    class="search-page-form js-search-autocomplete"
  >
    <label for="q">Search BenchBuddy</label>

    <div class="mega-search-field">
      <input
        type="search"
        id="q"
        name="q"
        value="<?= h($query) ?>"
        placeholder="Try: top hitters, top pitchers, recent games, most stolen bases..."
        autocomplete="off"
        autofocus
      >

      <div class="mega-search-suggestions" hidden></div>
    </div>

    <div class="actions-row" style="margin-top:12px;">
      <button type="submit">Search</button>
    </div>
  </form>
</div>

<?php if ($query !== ''): ?>

    <div class="card">

        <h2>
            Results for “<?= h($query) ?>”
        </h2>

        <?php if (empty($results)): ?>

            <p class="muted">
                No results found.
            </p>

        <?php else: ?>

            <div class="mobile-card-list">

                <?php foreach ($results as $result): ?>

                    <a
                        class="mobile-card"
                        href="<?= h((string)$result['url']) ?>"
                        style="text-decoration:none;"
                    >

                        <div class="mobile-card-title">
                            <?= h((string)$result['title']) ?>
                        </div>

                        <div class="mobile-card-row">

                            <span class="mobile-card-label">
                                <?= h((string)$result['type']) ?>
                            </span>

                            <?= h((string)$result['subtitle']) ?>

                        </div>

                    </a>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </div>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
