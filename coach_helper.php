<?php
declare(strict_types=1);


require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/lineup_engine.php';
require_once __DIR__ . '/includes/coach_helper_functions.php';

require_login();

header('Content-Type: application/json');

try {
    $teamId = current_team_id();
    if ($teamId <= 0) {
        throw new RuntimeException('No active team selected.');
    }

    $gameId = (int)($_POST['game_id'] ?? 0);
    $page = trim((string)($_POST['page'] ?? 'general'));
    $message = trim((string)($_POST['message'] ?? ''));

    if ($message === '') {
        throw new RuntimeException('Please enter a question.');
    }

    $game = null;
    $roster = [];
    $lineupResult = null;
    $warnings = [];
    $fairness = null;
    $suggestions = [];

    if ($gameId > 0) {
        $game = get_game_by_id($teamId, $gameId);

        if ($game) {
            $roster = get_game_roster($teamId, $gameId);
            $lineupResult = get_generated_lineup_result($teamId, $gameId);

            if (is_array($lineupResult)) {
                $warnings = analyze_lineup_warnings($lineupResult);
                $fairness = calculate_lineup_fairness($lineupResult);
                $suggestions = generate_lineup_suggestions($lineupResult, $roster);
            }
        }
    }

    $response = coach_helper_reply(
        $message,
        $page,
        $game,
        $roster,
        $lineupResult,
        $warnings,
        $fairness,
        $suggestions
    );

    if (is_string($response)) {
        $response = [
            'reply' => $response,
            'action_url' => null,
            'action_label' => null,
        ];
    }

    echo json_encode([
        'ok' => true,
        'reply' => (string)($response['reply'] ?? ''),
        'action_url' => $response['action_url'] ?? null,
        'action_label' => $response['action_label'] ?? null,
    ]);
    exit;
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'reply' => $e->getMessage(),
        'action_url' => null,
        'action_label' => null,
    ]);
    exit;
}
