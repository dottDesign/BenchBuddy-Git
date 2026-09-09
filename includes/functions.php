<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin/admin.php';
require_once __DIR__ . '/player_display.php';
require_once __DIR__ . '/flash.php';
require_once __DIR__ . '/plan_limits.php';

require_once __DIR__ . '/promos/promos.php';

require_once __DIR__ . '/coach/coach_dashboard.php';

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/helpers/general.php';
require_once __DIR__ . '/helpers/formatting.php';

require_once __DIR__ . '/teams/teams.php';
require_once __DIR__ . '/teams/team_members.php';
require_once __DIR__ . '/teams/invitations.php';

require_once __DIR__ . '/users/users.php';
require_once __DIR__ . '/users/password_resets.php';
require_once __DIR__ . '/users/theme.php';

require_once __DIR__ . '/players/players.php';

require_once __DIR__ . '/games/games.php';
require_once __DIR__ . '/games/game_roster.php';
require_once __DIR__ . '/games/game_status.php';
require_once __DIR__ . '/games/game_delete.php';

require_once __DIR__ . '/lineups/lineup_entries.php';
require_once __DIR__ . '/lineups/lineup_history.php';
require_once __DIR__ . '/lineups/lineup_fairness.php';
require_once __DIR__ . '/lineups/lineup_templates.php';
require_once __DIR__ . '/lineups/lineup_notes.php';

require_once __DIR__ . '/mail/mail.php';

require_once __DIR__ . '/pitching/pitching_rules.php';
require_once __DIR__ . '/pitching/pitching_rest.php';
require_once __DIR__ . '/pitching/pitch_log.php';

require_once __DIR__ . '/dashboard/dashboard.php';
require_once __DIR__ . '/features/features.php';
require_once __DIR__ . '/features/feature_requests.php';
