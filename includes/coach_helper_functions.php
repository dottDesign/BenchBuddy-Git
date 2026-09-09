<?php
declare(strict_types=1);

function coach_helper_reply(
    string $message,
    string $page,
    ?array $game,
    array $roster,
    ?array $lineupResult,
    array $warnings,
    ?array $fairness,
    array $suggestions
) {
    $text = strtolower(trim($message));

    $navigationReply = coach_helper_navigation_reply($text, $game);
    if ($navigationReply !== null) {
        return $navigationReply;
    }

    $featuresReply = coach_helper_features_reply($text);
    if ($featuresReply !== null) {
        return $featuresReply;
    }

    $ageGroupReply = coach_helper_age_group_reply($text);
    if ($ageGroupReply !== null) {
        return $ageGroupReply;
    }

    $lineupRulesReply = coach_helper_lineup_rules_reply($text);
    if ($lineupRulesReply !== null) {
        return $lineupRulesReply;
    }

    $instructionalReply = coach_helper_instructional_reply($text);
    if ($instructionalReply !== null) {
        return $instructionalReply;
    }

    $pageReply = coach_helper_page_reply($text, $page);
    if ($pageReply !== null) {
        return $pageReply;
    }

    $templatesReply = coach_helper_templates_reply($text, $page, $game);
    if ($templatesReply !== null) {
        return $templatesReply;
    }
    $gameStatsReply = coach_helper_game_stats_reply($text, $game);
    if ($gameStatsReply !== null) {
        return $gameStatsReply;
    }

    $playerStatsReply = coach_helper_player_stats_reply($text);
    if ($playerStatsReply !== null) {
        return $playerStatsReply;
    }

    $suggestedBattingReply = coach_helper_suggested_batting_lineup_reply($text);
    if ($suggestedBattingReply !== null) {
        return $suggestedBattingReply;
    }
    if (
        str_contains($text, 'warning') ||
        str_contains($text, 'problem') ||
        str_contains($text, 'flag')
    ) {
        return coach_helper_warnings_reply($warnings);
    }

    if (
        str_contains($text, 'fair') ||
        str_contains($text, 'fairness') ||
        str_contains($text, 'balanced')
    ) {
        return coach_helper_fairness_reply($fairness, $warnings);
    }

    if (
        str_contains($text, 'best fix') ||
        str_contains($text, 'best suggestion') ||
        str_contains($text, 'what should i do') ||
        str_contains($text, 'who should i swap') ||
        str_contains($text, 'fix') ||
        str_contains($text, 'suggestion')
    ) {
        return coach_helper_best_fix_reply($suggestions);
    }

    if (
        str_contains($text, 'bench') ||
        str_contains($text, 'benched') ||
        str_contains($text, 'sit')
    ) {
        return coach_helper_bench_reply($fairness);
    }

    if (
        str_contains($text, 'finalize') ||
        str_contains($text, 'can i save') ||
        str_contains($text, 'can i finalize') ||
        str_contains($text, 'ready')
    ) {
        return coach_helper_finalize_reply($warnings);
    }

    return 'I can help with autosave, pitch counts, team branding, print sheets, pitch rules, player display settings, recent feature updates, age-group rules, lineup rules, adding players, editing players, games, lineup warnings, fairness, bench usage, templates, suggestions, finalizing, account settings, and navigation around the app.';
}

function coach_helper_navigation_reply(string $text, ?array $game = null): ?array
{
    if (
        str_contains($text, 'open dashboard') ||
        str_contains($text, 'go to dashboard') ||
        str_contains($text, 'take me to dashboard')
    ) {
        return [
            'reply' => 'Opening the dashboard.',
            'action_url' => 'index.php',
            'action_label' => 'Open Dashboard',
        ];
    }

    if (
        str_contains($text, 'open players') ||
        str_contains($text, 'go to players') ||
        str_contains($text, 'take me to players')
    ) {
        return [
            'reply' => 'Opening Players so you can manage roster details.',
            'action_url' => 'players.php',
            'action_label' => 'Open Players',
        ];
    }

    if (
        str_contains($text, 'open account') ||
        str_contains($text, 'go to account') ||
        str_contains($text, 'take me to account')
    ) {
        return [
            'reply' => 'Opening Account settings.',
            'action_url' => 'account.php',
            'action_label' => 'Open Account',
        ];
    }

    if (
        str_contains($text, 'open games') ||
        str_contains($text, 'go to games') ||
        str_contains($text, 'take me to games')
    ) {
        return [
            'reply' => 'Opening Games so you can create or edit game setup.',
            'action_url' => 'games.php',
            'action_label' => 'Open Games',
        ];
    }

    if (
        str_contains($text, 'open history') ||
        str_contains($text, 'go to history') ||
        str_contains($text, 'take me to history')
    ) {
        return [
            'reply' => 'Opening archived game history.',
            'action_url' => 'history.php',
            'action_label' => 'Open History',
        ];
    }

    if (
        str_contains($text, 'open about') ||
        str_contains($text, 'go to about') ||
        str_contains($text, 'take me to about')
    ) {
        return [
            'reply' => 'Opening the About page.',
            'action_url' => 'about.php',
            'action_label' => 'Open About',
        ];
    }

    if (
        str_contains($text, 'open features') ||
        str_contains($text, 'go to features') ||
        str_contains($text, 'take me to features') ||
        str_contains($text, "what's new page") ||
        str_contains($text, 'whats new page')
    ) {
        return [
            'reply' => 'Opening the Features page.',
            'action_url' => 'features.php',
            'action_label' => 'Open Features',
        ];
    }

    if (
        str_contains($text, 'open lineup') ||
        str_contains($text, 'build lineup') ||
        str_contains($text, 'lineup builder') ||
        str_contains($text, 'open build lineup')
    ) {
        if ($game && !empty($game['id'])) {
            return [
                'reply' => 'Opening the lineup builder for this game.',
                'action_url' => 'generate.php?game_id=' . (int)$game['id'],
                'action_label' => 'Open Build Lineup',
            ];
        }

        return [
            'reply' => 'Opening the lineup builder.',
            'action_url' => 'generate.php',
            'action_label' => 'Open Build Lineup',
        ];
    }

    if (
        str_contains($text, 'open finalize') ||
        str_contains($text, 'go to finalize') ||
        str_contains($text, 'finalize game') ||
        str_contains($text, 'open lock')
    ) {
        if ($game && !empty($game['id'])) {
            return [
                'reply' => 'Opening finalize for this game.',
                'action_url' => 'lock.php?game_id=' . (int)$game['id'],
                'action_label' => 'Open Finalize',
            ];
        }

        return [
            'reply' => 'Opening finalize page.',
            'action_url' => 'lock.php',
            'action_label' => 'Open Finalize',
        ];
    }

    if (
        str_contains($text, 'open templates') ||
        str_contains($text, 'go to templates') ||
        str_contains($text, 'take me to templates') ||
        str_contains($text, 'open lineup templates')
    ) {
        return [
            'reply' => 'Opening lineup templates.',
            'action_url' => 'lineup_templates.php',
            'action_label' => 'Open Templates',
        ];
    }



    return null;
}

function coach_helper_features_reply(string $text): ?array
{
    $isFeatureQuestion =
        str_contains($text, "what's new") ||
        str_contains($text, 'whats new') ||
        str_contains($text, 'what is new') ||
        str_contains($text, 'show new features') ||
        str_contains($text, 'new features') ||
        str_contains($text, 'recent features') ||
        str_contains($text, 'recent updates') ||
        str_contains($text, 'latest updates') ||
        str_contains($text, 'latest features') ||
        str_contains($text, 'what changed') ||
        str_contains($text, 'what changed recently') ||
        str_contains($text, 'recent changes') ||
        str_contains($text, 'product updates') ||
        str_contains($text, 'release notes') ||
        str_contains($text, 'updates page') ||
        str_contains($text, 'features page');

    if (!$isFeatureQuestion) {
        return null;
    }

    if (!function_exists('get_recent_feature_updates')) {
        return [
            'reply' => 'The Features page shows recent updates, improvements, and planned items.',
            'action_url' => 'features.php',
            'action_label' => 'Open Features',
        ];
    }

    $features = get_recent_feature_updates(3);

    if (empty($features)) {
        return [
            'reply' => 'There are no recent feature updates posted right now.',
            'action_url' => 'features.php',
            'action_label' => 'Open Features',
        ];
    }

    $parts = [];

    foreach ($features as $feature) {
        $title = trim((string)($feature['title'] ?? ''));
        $summary = trim((string)($feature['summary'] ?? ''));
        $type = trim((string)($feature['feature_type'] ?? 'feature'));
        $version = trim((string)($feature['release_version'] ?? ''));

        if ($title === '') {
            continue;
        }

        $label = $title;

        if ($version !== '') {
            $label .= ' (' . $version . ')';
        }

        if ($summary !== '') {
            $label .= ' — ' . $summary;
        }

        $parts[] = $label;
    }

    if (empty($parts)) {
        return [
            'reply' => 'The Features page shows recent updates, improvements, and planned items.',
            'action_url' => 'features.php',
            'action_label' => 'Open Features',
        ];
    }

    return [
        'reply' => 'Here are the latest BenchBuddy updates: ' . implode(' • ', $parts),
        'action_url' => 'features.php',
        'action_label' => 'Open What’s New',
    ];
}

function coach_helper_age_group_reply(string $text): ?array
{
    $rules = function_exists('benchbuddy_age_group_rules') ? benchbuddy_age_group_rules() : [];

    if (empty($rules)) {
        return null;
    }

    $isAgeGroupQuestion =
        str_contains($text, 'age group') ||
        str_contains($text, '6u') ||
        str_contains($text, '8u') ||
        str_contains($text, '10u') ||
        str_contains($text, '12u') ||
        str_contains($text, '14u') ||
        str_contains($text, 'tee ball') ||
        str_contains($text, 'what are the rules') ||
        str_contains($text, 'youth rules') ||
        str_contains($text, 'division rules');

    if (!$isAgeGroupQuestion) {
        return null;
    }

    foreach ($rules as $group) {
        $ageGroup = strtolower((string)($group['age_group'] ?? ''));

        if (
            (str_contains($text, '6u') && str_contains($ageGroup, '6u')) ||
            (str_contains($text, 'tee ball') && str_contains($ageGroup, 'tee ball')) ||
            (str_contains($text, '8u') && $ageGroup === '8u') ||
            (str_contains($text, '10u') && $ageGroup === '10u') ||
            (str_contains($text, '12u') && $ageGroup === '12u') ||
            (str_contains($text, '14u') && str_contains($ageGroup, '14u'))
        ) {
            return [
                'reply' =>
                    (string)$group['age_group'] . ': ' .
                    'Typical game length is ' . (string)$group['innings'] . '. ' .
                    'Defense is usually ' . (string)$group['defense'] . '. ' .
                    'Pitching is ' . (string)$group['pitching'] . '. ' .
                    'BenchBuddy use: ' . (string)$group['benchbuddy'],
                'action_url' => 'about.php',
                'action_label' => 'View About Page',
            ];
        }
    }

    $labels = array_map(
        fn(array $group): string => (string)($group['age_group'] ?? ''),
        $rules
    );

    return [
        'reply' => 'BenchBuddy includes general guidance for these age groups: ' . implode(' • ', $labels) . '.',
        'action_url' => 'about.php',
        'action_label' => 'View About Page',
    ];
}

function coach_helper_lineup_rules_reply(string $text): ?array
{
    $rules = function_exists('benchbuddy_lineup_rules') ? benchbuddy_lineup_rules() : [];

    if (empty($rules)) {
        return null;
    }

    $isRuleQuestion =
        str_contains($text, 'lineup rules') ||
        str_contains($text, 'how does benchbuddy build lineups') ||
        str_contains($text, 'how do lineups work') ||
        str_contains($text, 'what rules are used for lineups') ||
        str_contains($text, 'how does fairness work') ||
        str_contains($text, 'what does benchbuddy use');

    if (!$isRuleQuestion) {
        return null;
    }

    $topRules = array_slice($rules, 0, 4);
    $parts = [];

    foreach ($topRules as $rule) {
        $parts[] = (string)($rule['title'] ?? '');
    }

    return [
        'reply' => 'BenchBuddy lineup rules include: ' . implode(' • ', $parts) . '. You can view the full lineup rules on the About page.',
        'action_url' => 'about.php',
        'action_label' => 'View About Page',
    ];
}

function coach_helper_instructional_reply(string $text)
{
    if (
        str_contains($text, 'add player') ||
        str_contains($text, 'how do i add a player') ||
        str_contains($text, 'create player') ||
        str_contains($text, 'new player')
    ) {
        return [
            'reply' => 'To add a player, open Players, enter first name, last name, jersey number, active status, and any roles or position settings, then click Add Player.',
            'action_url' => 'players.php',
            'action_label' => 'Open Players',
        ];
    }

    if (
        str_contains($text, 'edit player') ||
        str_contains($text, 'how do i edit a player') ||
        str_contains($text, 'update player')
    ) {
        return [
            'reply' => 'To edit a player, open Players, find the player in the list, and click Edit.',
            'action_url' => 'players.php',
            'action_label' => 'Open Players',
        ];
    }

    if (
        str_contains($text, 'delete player') ||
        str_contains($text, 'how do i delete a player') ||
        str_contains($text, 'remove player')
    ) {
        return [
            'reply' => 'To delete a player, open Players, find the player in the list, and click Delete.',
            'action_url' => 'players.php',
            'action_label' => 'Open Players',
        ];
    }

    if (
        str_contains($text, 'inactive player') ||
        str_contains($text, 'what does inactive mean') ||
        str_contains($text, 'active player')
    ) {
        return 'Inactive players stay in your player list but are usually excluded from active lineup planning until you mark them active again.';
    }

    if (
        str_contains($text, 'pitching role') ||
        str_contains($text, 'what is pitching role')
    ) {
        return [
            'reply' => 'Pitching role tells the lineup builder how to use that player. Primary pitchers are preferred first. Emergency pitchers are fallback options.',
            'action_url' => 'players.php',
            'action_label' => 'Open Players',
        ];
    }

    if (
        str_contains($text, 'catching role') ||
        str_contains($text, 'what is catching role')
    ) {
        return [
            'reply' => 'Catching role works like pitching role. Primary catchers are preferred first, while emergency catchers are backups.',
            'action_url' => 'players.php',
            'action_label' => 'Open Players',
        ];
    }

    if (
        str_contains($text, 'can play') ||
        str_contains($text, 'cannot play') ||
        str_contains($text, 'what does can play mean') ||
        str_contains($text, 'what does cannot play mean')
    ) {
        return [
            'reply' => 'Can Play marks positions the player is allowed to play. Cannot Play blocks positions. If a position is selected in both, Can Play wins.',
            'action_url' => 'players.php',
            'action_label' => 'Open Players',
        ];
    }

    if (
        str_contains($text, 'theme') ||
        str_contains($text, 'color') ||
        str_contains($text, 'accent') ||
        str_contains($text, 'theme color') ||
        str_contains($text, 'change color')
    ) {
        return [
            'reply' => 'Theme color and appearance settings are managed from your account or app settings area. Open Account and look for appearance or color options.',
            'action_url' => 'account.php',
            'action_label' => 'Open Account',
        ];
    }

    if (
        str_contains($text, 'switch team') ||
        str_contains($text, 'change team')
    ) {
        return [
            'reply' => 'You can switch teams from the Account page if your account has access to more than one team.',
            'action_url' => 'account.php',
            'action_label' => 'Open Account',
        ];
    }

    if (
        str_contains($text, 'feature') ||
        str_contains($text, 'feature request')
    ) {
        return [
            'reply' => 'You can now submit feature requests directly from your account page. Track status updates and see when your ideas go live.',
            'action_url' => 'account.php',
            'action_label' => 'Open Account',
        ];
    }

    if (
        str_contains($text, 'create game') ||
        str_contains($text, 'how do i create a game') ||
        str_contains($text, 'add game') ||
        str_contains($text, 'new game')
    ) {
        return [
            'reply' => 'To create a game, open Games, add the game details, set the roster and batting order, then save it as a draft.',
            'action_url' => 'games.php',
            'action_label' => 'Open Games',
        ];
    }

    if (
        str_contains($text, 'batting order') ||
        str_contains($text, 'change batting order')
    ) {
        return [
            'reply' => 'Batting order is set on the Games page as part of the game roster setup before building the lineup.',
            'action_url' => 'games.php',
            'action_label' => 'Open Games',
        ];
    }

    if (
        str_contains($text, 'draft') ||
        str_contains($text, 'draft game') ||
        str_contains($text, 'what does draft mean') ||
        str_contains($text, 'draft status')
    ) {
        return 'Draft means the game has been set up, but the lineup has not been fully generated and finalized yet.';
    }

    if (
        str_contains($text, 'generated lineup') ||
        str_contains($text, 'built lineup') ||
        str_contains($text, 'what does generated mean')
    ) {
        return 'Generated means the lineup has been built and saved, but the game has not been finalized yet.';
    }

    if (
        str_contains($text, 'finalized game') ||
        str_contains($text, 'what does finalized mean') ||
        str_contains($text, 'locked game')
    ) {
        return 'Finalized means the game has been locked in and moved into history with its saved lineup and pitching results.';
    }

    if (
        str_contains($text, 'save pitch') ||
        str_contains($text, 'pitch count') ||
        str_contains($text, 'save pitch counts')
    ) {
        return [
            'reply' => 'Save Pitch Counts updates the actual innings and pitch totals without finalizing again. It is useful after the game when real pitching differs from the planned lineup.',
            'action_url' => 'lock.php',
            'action_label' => 'Open Finalize',
        ];
    }

    if (
        str_contains($text, 'reopen game') ||
        str_contains($text, 'what does reopen game do') ||
        str_contains($text, 'reopen')
    ) {
        return [
            'reply' => 'Reopen Game changes a finalized game back to built status so you can edit the lineup and finalize it again.',
            'action_url' => 'lock.php',
            'action_label' => 'Open Finalize',
        ];
    }

    if (
        str_contains($text, 'autosave') ||
        str_contains($text, 'auto save') ||
        str_contains($text, 'saved draft') ||
        str_contains($text, 'resume draft') ||
        str_contains($text, 'lineup draft')
    ) {
        return 'Manual lineup edits are now autosaved while you work. If you leave, refresh, or your session times out, BenchBuddy can offer to resume your saved lineup draft when you return to that game.';
    }

    if (
        str_contains($text, 'pitch count additions') ||
        str_contains($text, 'add pitch counts') ||
        str_contains($text, 'bulk pitch counts') ||
        str_contains($text, 'enter pitch counts') ||
        str_contains($text, 'pitch totals')
    ) {
        return [
            'reply' => 'Use Add Pitch Counts after a game to enter actual pitch totals. This is useful when real pitching differs from the planned lineup.',
            'action_url' => 'bulk_pitch_counts.php',
            'action_label' => 'Add Pitch Counts',
        ];
    }

    if (
        str_contains($text, 'team branding') ||
        str_contains($text, 'logo') ||
        str_contains($text, 'team logo') ||
        str_contains($text, 'brand name') ||
        str_contains($text, 'print branding')
    ) {
        return [
            'reply' => 'Team branding is managed from Team Settings. You can set a print brand name, upload a team logo, and control how your team appears on printable lineup sheets when your plan supports custom branding.',
            'action_url' => 'team_settings.php',
            'action_label' => 'Open Team Settings',
        ];
    }

    if (
        str_contains($text, 'pitch rules') ||
        str_contains($text, 'current pitch rules') ||
        str_contains($text, 'division rules') ||
        str_contains($text, 'daily max')
    ) {
        return [
            'reply' => 'Your team’s current pitch rules are shown in Team Settings based on the selected program and division. BenchBuddy displays the daily max and required rest rules there.',
            'action_url' => 'team_settings.php',
            'action_label' => 'Open Team Settings',
        ];
    }

    if (
        str_contains($text, 'player display') ||
        str_contains($text, 'show jersey') ||
        str_contains($text, 'jersey number') ||
        str_contains($text, 'display name') ||
        str_contains($text, 'name format')
    ) {
        return [
            'reply' => 'Player display controls how names appear across lineup pages and print sheets. You can choose full name, first name, last name, jersey number, or combined display formats from Account settings.',
            'action_url' => 'account.php',
            'action_label' => 'Open Account',
        ];
    }

    if (
        str_contains($text, 'print sheet') ||
        str_contains($text, 'printable') ||
        str_contains($text, 'print lineup') ||
        str_contains($text, 'blank lineup sheet')
    ) {
        return [
            'reply' => 'BenchBuddy supports print-ready lineup sheets, blank lineup sheets, team branding, and watermark-free printouts depending on your plan.',
            'action_url' => 'print_blank_lineup.php',
            'action_label' => 'Open Blank Lineup',
        ];
    }

    if (
        str_contains($text, 'invite coach') ||
        str_contains($text, 'add coach') ||
        str_contains($text, 'team member') ||
        str_contains($text, 'assistant coach')
    ) {
        return [
            'reply' => 'Head Coaches can add team members from Account settings. Existing users are added directly, and new users receive a signup invite link.',
            'action_url' => 'account.php',
            'action_label' => 'Open Account',
        ];
    }

    if (
        str_contains($text, 'delete account') ||
        str_contains($text, 'remove account') ||
        str_contains($text, 'account deletion')
    ) {
        return [
            'reply' => 'Admins can delete a user account and associated data from Admin Users. The delete flow requires typing DELETE before the account is removed.',
            'action_url' => 'admin_users.php',
            'action_label' => 'Open Admin Users',
        ];
    }

    if (
        str_contains($text, 'fairness score') ||
        str_contains($text, 'what is fairness')
    ) {
        return 'Fairness score measures how balanced the lineup is across bench time, defensive innings, and bench streaks. Higher scores mean a more balanced lineup.';
    }

    if (
        str_contains($text, 'smart suggestion') ||
        str_contains($text, 'what are suggestions')
    ) {
        return 'Smart Suggestions are lineup improvement ideas based on fairness, bench streaks, position rotation, and heavy pitcher or catcher usage.';
    }

    if (
        str_contains($text, 'swap mode') ||
        str_contains($text, 'what is swap mode')
    ) {
        return 'Swap mode automatically trades players when you pick someone already used in the same inning, instead of creating a duplicate.';
    }

    if (
        str_contains($text, 'bench streak') ||
        str_contains($text, 'long bench streak')
    ) {
        return 'A bench streak means the same player is sitting for several innings in a row. Long streaks usually lower fairness and trigger suggestions or warnings.';
    }

    return null;
}

function coach_helper_templates_reply(string $text, string $page, ?array $game = null): ?array
{
    if (
        !str_contains($text, 'template') &&
        !str_contains($text, 'templates')
    ) {
        return null;
    }

    if (
        str_contains($text, 'what are') ||
        str_contains($text, 'what is')
    ) {
        return [
            'reply' => 'Lineup templates are reusable saved lineup patterns for a team. They help you save a lineup structure once and apply it again later instead of rebuilding from scratch.',
            'action_url' => 'lineup_templates.php',
            'action_label' => 'Open Templates',
        ];
    }

    if (
        str_contains($text, 'save') &&
        str_contains($text, 'template')
    ) {
        return [
            'reply' => 'To save a template, build or edit a lineup first, then use the save-as-template option. Templates are saved for the current team.',
            'action_url' => 'lineup_templates.php',
            'action_label' => 'Manage Templates',
        ];
    }

    if (
        str_contains($text, 'apply') &&
        str_contains($text, 'template')
    ) {
        if ($game && !empty($game['id'])) {
            return [
                'reply' => 'To apply a template, open the lineup for this game and choose a saved template that matches the team and roster size.',
                'action_url' => 'manual_lineup.php?game_id=' . (int)$game['id'],
                'action_label' => 'Open Lineup',
            ];
        }

        return [
            'reply' => 'Templates are applied from the lineup workflow for a game. Pick a template that matches the roster size for that team.',
            'action_url' => 'lineup_templates.php',
            'action_label' => 'Manage Templates',
        ];
    }

    if (
        str_contains($text, 'manage') ||
        str_contains($text, 'rename') ||
        str_contains($text, 'delete') ||
        str_contains($text, 'edit')
    ) {
        return [
            'reply' => 'You can manage lineup templates from the Lineup Templates page. There you can rename, duplicate, edit, and delete saved templates.',
            'action_url' => 'lineup_templates.php',
            'action_label' => 'Manage Templates',
        ];
    }

    if (
        str_contains($text, 'team based') ||
        str_contains($text, 'team-based') ||
        str_contains($text, 'account based') ||
        str_contains($text, 'account-based')
    ) {
        return [
            'reply' => 'Templates are team-based. That keeps saved lineup patterns tied to the correct team roster, roster size, and lineup workflow.',
            'action_url' => 'lineup_templates.php',
            'action_label' => 'Open Templates',
        ];
    }

    if (
        str_contains($text, '8 player') ||
        str_contains($text, '9 player') ||
        str_contains($text, 'roster size') ||
        str_contains($text, 'mismatch') ||
        str_contains($text, 'match')
    ) {
        return [
            'reply' => 'Templates should match the roster size they were created for. An 8-player template should be used for 8-player lineups, and a 9-player template should be used for 9-player lineups.',
            'action_url' => 'lineup_templates.php',
            'action_label' => 'Open Templates',
        ];
    }

    return [
        'reply' => 'I can help with lineup templates. You can save reusable lineup patterns, apply them to future games, and manage them from the Lineup Templates page.',
        'action_url' => 'lineup_templates.php',
        'action_label' => 'Manage Templates',
    ];
}

function coach_helper_page_reply(string $text, string $page): ?string
{
    if ($page === 'players') {
        if (str_contains($text, 'inactive')) {
            return 'Inactive players stay in your player list but are typically excluded from active lineup planning unless you reactivate them.';
        }
    }

    if ($page === 'games') {
        if (str_contains($text, 'draft')) {
            return 'Draft means the game has been set up, but the lineup has not been fully generated and finalized yet.';
        }
    }

    if ($page === 'lock') {
        if (str_contains($text, 'finalize')) {
            return 'Finalize locks in the game record and stores the completed lineup and pitching information in history.';
        }
    }

    if ($page === 'generate' || $page === 'manual_lineup') {
        if (str_contains($text, 'swap mode')) {
            return 'Swap mode automatically trades players when you select a player already used in the same inning.';
        }
    }

    if ($page === 'account') {
        if (str_contains($text, 'template') || str_contains($text, 'templates')) {
            return 'Lineup templates are managed from the Lineup Templates page, where you can rename, duplicate, edit, and delete reusable templates.';
        }

        if (
            str_contains($text, "what's new") ||
            str_contains($text, 'whats new') ||
            str_contains($text, 'features')
        ) {
            return 'The Features page shows recent BenchBuddy improvements, beta items, planned work, and highlighted updates.';
        }
    }

    if ($page === 'templates') {
        if (str_contains($text, 'template')) {
            return 'This page lets you manage saved lineup templates for the current team. You can rename, duplicate, edit, and delete templates here.';
        }
    }

    if ($page === 'about') {
        if (str_contains($text, 'rules') || str_contains($text, 'age group')) {
            return 'The About page explains both BenchBuddy lineup rules and general age-group guidance used as coaching references.';
        }
    }

    return null;
}

function coach_helper_warnings_reply(array $warnings): string
{
    if (empty($warnings)) {
        return 'There are no current lineup warnings.';
    }

    $top = array_slice($warnings, 0, 3);
    $parts = [];

    foreach ($top as $warning) {
        $parts[] = (string)($warning['message'] ?? '');
    }

    return 'Top issues: ' . implode(' ', $parts);
}

function coach_helper_fairness_reply(?array $fairness, array $warnings): string
{
    if (!is_array($fairness)) {
        return 'Fairness is not available for this page yet.';
    }

    $teamScore = (int)($fairness['team_score'] ?? 0);

    if ($teamScore >= 90) {
        $label = 'excellent';
    } elseif ($teamScore >= 75) {
        $label = 'good';
    } else {
        $label = 'needs review';
    }

    $reason = function_exists('fairness_reason_summary')
        ? fairness_reason_summary($warnings)
        : 'Review the current warnings and suggestions for the biggest improvements.';

    return 'Team fairness is ' . $teamScore . '/100, which is ' . $label . '. ' . $reason;
}

function coach_helper_best_fix_reply(array $suggestions): string
{
    if (empty($suggestions)) {
        return 'I do not see any high-priority fixes right now.';
    }

    $best = $suggestions[0];
    return (string)($best['message'] ?? 'No suggestion available.');
}

function coach_helper_bench_reply(?array $fairness): string
{
    if (!is_array($fairness)) {
        return 'No bench usage data is available here.';
    }

    $players = $fairness['players'] ?? [];
    if (empty($players)) {
        return 'No bench usage data is available yet.';
    }

    usort($players, function (array $a, array $b): int {
        return (int)($b['longest_bench_streak'] ?? 0) <=> (int)($a['longest_bench_streak'] ?? 0);
    });

    $top = $players[0] ?? null;
    if (!$top) {
        return 'No bench usage data is available yet.';
    }

    $name = (string)($top['name'] ?? 'A player');
    $streak = (int)($top['longest_bench_streak'] ?? 0);
    $total = (int)($top['bench_innings'] ?? 0);

    return $name . ' currently has the longest bench streak at ' . $streak . ' innings, with ' . $total . ' total bench innings.';
}

function coach_helper_finalize_reply(array $warnings): string
{
    $errors = array_values(array_filter($warnings, fn(array $w): bool => (($w['type'] ?? '') === 'error')));
    $soft = array_values(array_filter($warnings, fn(array $w): bool => (($w['type'] ?? '') === 'warning')));

    if (!empty($errors)) {
        return 'Not yet. You still have blocking lineup errors that should be fixed before saving or finalizing.';
    }

    if (!empty($soft)) {
        return 'Yes, but with caution. There are no blocking errors, though there are still warning-level issues to review.';
    }

    return 'Yes. There are no current blocking errors or warning-level issues.';
}
function coach_helper_game_stats_reply(string $text, ?array $game = null): ?array
{
    if (
        !str_contains($text, 'game stats') &&
        !str_contains($text, 'enter stats') &&
        !str_contains($text, 'batting stats') &&
        !str_contains($text, 'pitching stats') &&
        !str_contains($text, 'earned runs') &&
        !str_contains($text, 'era') &&
        !str_contains($text, 'whip')
    ) {
        return null;
    }

    if ($game && !empty($game['id'])) {
        return [
            'reply' => 'Game Stats lets you enter batting and pitching results for this game, including AB, hits, RBI, walks, strikeouts, innings pitched, pitches, earned runs, ERA, and WHIP.',
            'action_url' => 'game_stats.php?game_id=' . (int)$game['id'],
            'action_label' => 'Open Game Stats',
        ];
    }

    return [
        'reply' => 'Game Stats lets you enter batting and pitching results after a game. Choose a game first, then enter batting totals and pitcher results.',
        'action_url' => 'game_stats.php',
        'action_label' => 'Open Game Stats',
    ];
}

function coach_helper_player_stats_reply(string $text): ?array
{
    if (
        !str_contains($text, 'player stats') &&
        !str_contains($text, 'season stats') &&
        !str_contains($text, 'top hitters') &&
        !str_contains($text, 'top pitchers') &&
        !str_contains($text, 'stolen bases') &&
        !str_contains($text, 'ops') &&
        !str_contains($text, 'batting average') &&
        !str_contains($text, 'pitching leaders')
    ) {
        return null;
    }

    return [
        'reply' => 'Player Stats shows season totals, batting rates, pitching results, top hitters, top pitchers, stolen bases, OPS, ERA, WHIP, and sortable leaderboards.',
        'action_url' => 'player_stats.php',
        'action_label' => 'Open Player Stats',
    ];
}

function coach_helper_suggested_batting_lineup_reply(string $text): ?array
{
    if (
        !str_contains($text, 'suggested batting lineup') &&
        !str_contains($text, 'suggested lineup') &&
        !str_contains($text, 'batting order suggestion') &&
        !str_contains($text, 'best batting order') &&
        !str_contains($text, 'who should bat') &&
        !str_contains($text, 'lineup based on batting')
    ) {
        return null;
    }

    return [
        'reply' => 'Suggested Batting Lineup uses OBP, OPS, contact rate, speed, power, and run production to recommend batting order roles like leadoff, contact, power, run producer, and depth bats.',
        'action_url' => 'player_stats.php',
        'action_label' => 'View Suggested Lineup',
    ];
}
function coach_helper_quick_actions(string $page): array
{
    return match ($page) {
        'players' => [
            'How do I add a player?',
            'How do I edit a player?',
            'What is pitching role?',
            'What is catching role?',
            'What does can play mean?',
        ],
        'account' => [
            'Can I ask for a new feature?',
            'How do I change theme color?',
            'How do I switch teams?',
            'What are lineup templates?',
            'Where do I manage templates?',
            'What’s new?',
            'What rules are used for lineups?',
            'Open account',
            'Where do I change team branding?',
            'How do I invite a coach?',
            'How do I change player display?',
        ],
        'games' => [
            'How do I create a game?',
            'How do I change batting order?',
            'What does draft mean?',
            'What’s new?',
            'Open games',
        ],
        'generate', 'manual_lineup' => [
            'Why are there warnings?',
            'Explain fairness',
            'What is the best fix?',
            'Who is benched too long?',
            'What is swap mode?',
            'How do I save a template?',
            'How do I apply a template?',
            'What rules are used for lineups?',
            'What’s new?',
            'Can I finalize this lineup?',
            'Open finalize',
            'How does autosave work?',
            'Where do I add pitch counts?',
            'Where do I change team branding?',
            'Where are my pitch rules?',
        ],
        'templates' => [
            'What are lineup templates?',
            'How do I save a template?',
            'How do I apply a template?',
            'Where do I manage templates?',
            'What’s new?',
        ],
        'about' => [
            'What rules are used for lineups?',
            'What are the 8U rules?',
            'What are the 10U rules?',
            'What are the 12U rules?',
            'Open features',
        ],
        'lock' => [
            'How do I finalize this game?',
            'What does reopen game do?',
            'What is save pitch counts?',
            'What’s new?',
            'Open history',
            'Where do I add pitch counts?',
            'What is save pitch counts?',
            'How do I reopen a game?',
        ],
        'player_stats' => [
            'What are player stats?',
            'How does suggested batting lineup work?',
                'Who are my top hitters?',
                'Who are my top pitchers?',
                'How is ERA calculated?',
                'How is WHIP calculated?',
                'Show stolen base leaders',
            'Show top hitters',
            'Show top pitchers',
            'What is suggested batting lineup?',
            'How is OPS calculated?',
            'How is ERA calculated?',
        ],
        'game_stats' => [
            'How do I enter game stats?',
            'What is earned run?',
            'How is ERA calculated?',
            'How is WHIP calculated?',
            'Open player stats',
        ],
        default => [
            'What can you help with?',
            'How do I add a player?',
            'How do I create a game?',
            'What are lineup templates?',
            'What’s new?',
            'What rules are used for lineups?',
            'What is fairness score?',
            'Open players',
            'Open account',
        ],
    };
}
