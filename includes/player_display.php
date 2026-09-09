<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
function allowed_player_label_modes(): array
{
    return [
        'both',
        'full_name',
        'first_name',
        'first_name_number',
        'last_name',
        'last_name_number',
        'first_name_last_initial',
        'first_name_last_initial_number',
        'first_initial_last_name',
        'first_initial_last_name_number',
        'first_initial_last_initial',
        'first_initial_last_initial_number',
        'number',
    ];
}
function player_label_mode(): string
{
    $teamId = function_exists('current_team_id') ? current_team_id() : 0;

    $mode = (string)($_GET['label_mode'] ?? '');

    if ($mode !== '' && in_array($mode, allowed_player_label_modes(), true)) {
        $_SESSION['player_label_mode'] = $mode;
        return $mode;
    }

    if ($teamId > 0) {
        $teamMode = get_team_player_label_mode($teamId);
        $_SESSION['player_label_mode'] = $teamMode;
        return $teamMode;
    }

    $sessionMode = (string)($_SESSION['player_label_mode'] ?? 'both');

    return in_array($sessionMode, allowed_player_label_modes(), true) ? $sessionMode : 'both';
}
function player_label_mode_options(): array
{
    return [
        'both' => 'Full Name + Number',
        'full_name' => 'Full Name',
        'first_name' => 'First Name',
        'first_name_number' => 'First Name + Number',
        'last_name' => 'Last Name',
        'last_name_number' => 'Last Name + Number',
        'first_name_last_initial' => 'First Name + Last Initial',
        'first_name_last_initial_number' => 'First Name + Last Initial + Number',
        'first_initial_last_name' => 'First Initial + Last Name',
        'first_initial_last_name_number' => 'First Initial + Last Name + Number',
        'first_initial_last_initial' => 'First Initial + Last Initial',
        'first_initial_last_initial_number' => 'First Initial + Last Initial + Number',
        'number' => 'Number Only',
    ];
}

function split_player_name_parts(string $fullName): array
{
    $fullName = trim($fullName);

    if ($fullName === '') {
        return ['', ''];
    }

    $parts = preg_split('/\s+/', $fullName) ?: [];

    if (count($parts) <= 1) {
        return [$fullName, ''];
    }

    return [
        (string)$parts[0],
        (string)$parts[count($parts) - 1],
    ];
}

function format_player_label(array $player, ?string $mode = null): string
{
    $mode = $mode ?: player_label_mode();

    $fullName = trim((string)($player['name'] ?? ''));
    $firstName = trim((string)($player['first_name'] ?? ''));
    $lastName = trim((string)($player['last_name'] ?? ''));
    $number = trim((string)($player['jersey_number'] ?? ''));

    if ($fullName === '') {
        $fullName = trim($firstName . ' ' . $lastName);
    }

    if ($firstName === '' && $lastName === '' && $fullName !== '') {
        [$firstName, $lastName] = split_player_name_parts($fullName);
    }

    $firstInitial = $firstName !== '' ? strtoupper(substr($firstName, 0, 1)) . '.' : '';
    $lastInitial = $lastName !== '' ? strtoupper(substr($lastName, 0, 1)) . '.' : '';

    return match ($mode) {
        'full_name' => $fullName,
        'first_name' => $firstName !== '' ? $firstName : $fullName,
        'first_name_number' => $number !== '' ? (($firstName !== '' ? $firstName : $fullName) . ' (#' . $number . ')') : ($firstName !== '' ? $firstName : $fullName),
        'last_name' => $lastName !== '' ? $lastName : $fullName,
        'last_name_number' => $number !== '' ? (($lastName !== '' ? $lastName : $fullName) . ' (#' . $number . ')') : ($lastName !== '' ? $lastName : $fullName),
        'first_name_last_initial' => ($firstName !== '' && $lastInitial !== '') ? $firstName . ' ' . $lastInitial : ($firstName !== '' ? $firstName : $fullName),
        'first_name_last_initial_number' => $number !== ''
            ? ((($firstName !== '' && $lastInitial !== '') ? $firstName . ' ' . $lastInitial : ($firstName !== '' ? $firstName : $fullName)) . ' (#' . $number . ')')
            : (($firstName !== '' && $lastInitial !== '') ? $firstName . ' ' . $lastInitial : ($firstName !== '' ? $firstName : $fullName)),
        'first_initial_last_name' => ($firstInitial !== '' && $lastName !== '') ? $firstInitial . ' ' . $lastName : $fullName,
        'first_initial_last_name_number' => $number !== ''
            ? ((($firstInitial !== '' && $lastName !== '') ? $firstInitial . ' ' . $lastName : $fullName) . ' (#' . $number . ')')
            : (($firstInitial !== '' && $lastName !== '') ? $firstInitial . ' ' . $lastName : $fullName),
        'first_initial_last_initial' => ($firstInitial !== '' && $lastInitial !== '') ? $firstInitial . ' ' . $lastInitial : $fullName,
        'first_initial_last_initial_number' => $number !== ''
            ? ((($firstInitial !== '' && $lastInitial !== '') ? $firstInitial . ' ' . $lastInitial : $fullName) . ' (#' . $number . ')')
            : (($firstInitial !== '' && $lastInitial !== '') ? $firstInitial . ' ' . $lastInitial : $fullName),
        'number' => $number !== '' ? '#' . $number : $fullName,
        default => $number !== '' ? $fullName . ' (#' . $number . ')' : $fullName,
    };
}

function get_team_player_label_mode(int $teamId): string
{
    if ($teamId <= 0) {
        return 'both';
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT player_label_mode
        FROM teams
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([
        'id' => $teamId,
    ]);

    $mode = (string)($stmt->fetchColumn() ?: 'both');

    return in_array($mode, allowed_player_label_modes(), true) ? $mode : 'both';
}

function update_team_player_label_mode(int $teamId, string $mode): void
{
    if ($teamId <= 0) {
        throw new RuntimeException('Invalid team.');
    }

    if (!in_array($mode, allowed_player_label_modes(), true)) {
        throw new RuntimeException('Invalid player display mode.');
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        UPDATE teams
        SET player_label_mode = :mode
        WHERE id = :id
    ");
    $stmt->execute([
        'mode' => $mode,
        'id' => $teamId,
    ]);

    $_SESSION['player_label_mode'] = $mode;
}
