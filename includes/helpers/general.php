<?php
declare(strict_types=1);


function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

function normalize_positions(array $positions): array
{
    $clean = [];

    foreach ($positions as $pos) {
        $pos = strtoupper(trim((string) $pos));
        if ($pos !== "") {
            $clean[] = $pos;
        }
    }

    $clean = array_values(array_unique($clean));
    sort($clean);

    return $clean;
}

function normalize_name(string $name): string
{
    return trim($name);
}

function normalize_jersey_number(?string $jerseyNumber): ?string
{
    if ($jerseyNumber === null) {
        return null;
    }

    $jerseyNumber = trim($jerseyNumber);

    if ($jerseyNumber === "") {
        return null;
    }

    if (strlen($jerseyNumber) > 10) {
        throw new InvalidArgumentException(
            "Jersey number must be 10 characters or fewer.",
        );
    }

    return $jerseyNumber;
}

function normalize_game_date(?string $gameDate): ?string
{
    if ($gameDate === null) {
        return null;
    }

    $gameDate = trim($gameDate);
    if ($gameDate === "") {
        return null;
    }

    $dt = DateTime::createFromFormat("Y-m-d", $gameDate);
    if (!$dt || $dt->format("Y-m-d") !== $gameDate) {
        throw new InvalidArgumentException(
            "Game date must be in YYYY-MM-DD format.",
        );
    }

    return $gameDate;
}

function validate_team_id(int $teamId): void
{
    if ($teamId <= 0) {
        throw new InvalidArgumentException("A valid team ID is required.");
    }
}
function status_class(string $status): string
{
    $status = strtolower(trim($status));

    if ($status === "locked") {
        return "locked";
    }

    if ($status === "generated") {
        return "generated";
    }

    if ($status === "draft") {
        return "draft";
    }

    if ($status === "cancelled") {
        return "cancelled";
    }

    return "default";
}


function benchbuddy_lineup_rules(): array
{
    return [
        [
            "title" => "One assignment per inning",
            "text" =>
                "Each player can only appear once per inning, either in the field or on the bench.",
        ],
        [
            "title" => "Bench fairness",
            "text" =>
                "BenchBuddy tries to rotate bench time as evenly as possible and reduce long bench streaks.",
        ],
        [
            "title" => "Pitcher priority",
            "text" =>
                "Primary pitchers are used before emergency pitchers whenever possible.",
        ],
        [
            "title" => "Catcher priority",
            "text" =>
                "Primary catchers are used before emergency catchers whenever possible.",
        ],
        [
            "title" => "Position variety",
            "text" =>
                "BenchBuddy tries to avoid overusing the same player at the same position when other valid options exist.",
        ],
        [
            "title" => "Pitcher and catcher workload warnings",
            "text" =>
                "BenchBuddy flags heavy pitcher and catcher usage so coaches can review fairness and workload before finalizing.",
        ],
        [
            "title" => "Manual lineup validation",
            "text" =>
                "Manual edits are checked for duplicate assignments, missing players, and inning-by-inning lineup problems before saving.",
        ],
        [
            "title" => "8-player lineup support",
            "text" =>
                "For 8-player games, BenchBuddy can still display center field as open so the defensive layout is easy to understand.",
        ],
    ];
}

function benchbuddy_age_group_rules(): array
{
    return [
        [
            "age_group" => "6U / Tee Ball",
            "innings" => "Usually 3 to 4 innings",
            "defense" =>
                "Often 10 players in the field, sometimes with extra infield or outfield help",
            "pitching" => "Usually coach pitch or tee batting",
            "focus" => "Learning positions, participation, and simple rotation",
            "benchbuddy" =>
                "BenchBuddy is best used here for simple defensive rotation and equal participation.",
        ],
        [
            "age_group" => "8U",
            "innings" => "Usually 5 to 6 innings",
            "defense" =>
                "Often 8 to 10 defensive players depending on league rules",
            "pitching" =>
                "Machine pitch, coach pitch, or player pitch depending on division",
            "focus" =>
                "Fair field time, simple pitcher and catcher planning, and clear defensive rotation",
            "benchbuddy" =>
                "BenchBuddy supports 8-player lineups and helps balance field time and bench usage.",
        ],
        [
            "age_group" => "10U",
            "innings" => "Usually 6 innings",
            "defense" => "Standard defensive positions are common",
            "pitching" =>
                "Player pitching is usually established and workload matters more",
            "focus" =>
                "Pitcher planning, catcher limits, position development, and fairness",
            "benchbuddy" =>
                "BenchBuddy helps manage pitching roles, catching roles, bench fairness, and inning-by-inning assignments.",
        ],
        [
            "age_group" => "12U",
            "innings" => "Usually 6 innings",
            "defense" => "Standard 9-player defense",
            "pitching" =>
                "Pitch counts, innings limits, and position specialization matter more",
            "focus" =>
                "Balancing competition, player development, and safe pitcher or catcher usage",
            "benchbuddy" =>
                "BenchBuddy is useful for managing defensive variety, bench fairness, and heavy-role warnings.",
        ],
        [
            "age_group" => "14U and older",
            "innings" => "Usually 7 innings",
            "defense" => "Standard 9-player defense",
            "pitching" =>
                "Pitching and catching workloads are more important and roles are often more specialized",
            "focus" =>
                "Competitive lineups, rotation planning, and role-based usage",
            "benchbuddy" =>
                "BenchBuddy supports structured lineup planning while still showing fairness and workload concerns.",
        ],
    ];
}

function ordinal(int $n): string
{
    if (!in_array(($n % 100), [11, 12, 13], true)) {
        return match ($n % 10) {
            1 => $n . 'st',
            2 => $n . 'nd',
            3 => $n . 'rd',
            default => $n . 'th',
        };
    }

    return $n . 'th';
}

function onboarding_dismissed(int $teamId): bool
{
    $stmt = db()->prepare("
        SELECT onboarding_dismissed
        FROM teams
        WHERE id = :team_id
        LIMIT 1
    ");
    $stmt->execute([
        'team_id' => $teamId,
    ]);
    return (bool)$stmt->fetchColumn();
}

function dismiss_onboarding(int $teamId): void
{
    $stmt = db()->prepare("
        UPDATE teams
        SET onboarding_dismissed = 1
        WHERE id = :team_id
        LIMIT 1
    ");
    $stmt->execute([
        'team_id' => $teamId,
    ]);
}

function set_next_step_modal(
    string $title,
    string $message,
    string $primaryText,
    string $primaryUrl,
    string $secondaryText = '',
    string $secondaryUrl = ''
): void {
    $_SESSION['next_step_modal'] = [
        'title' => $title,
        'message' => $message,
        'primary_text' => $primaryText,
        'primary_url' => $primaryUrl,
        'secondary_text' => $secondaryText,
        'secondary_url' => $secondaryUrl,
    ];
}
