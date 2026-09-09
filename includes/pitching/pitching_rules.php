<?php
declare(strict_types=1);


function get_pitching_rules_for_division(?string $division): array
{
    $division = strtoupper(trim((string) $division));

    $rules = [
        "5U" => [
            "max_pitches" => 999,
            "rest_days" => [["min" => 1, "max" => 999, "days" => 0]],
        ],
        "7U" => [
            "max_pitches" => 999,
            "rest_days" => [["min" => 1, "max" => 999, "days" => 0]],
        ],
        "9U" => [
            "max_pitches" => 999,
            "rest_days" => [["min" => 1, "max" => 999, "days" => 0]],
        ],
        "11U" => [
            "max_pitches" => 75,
            "rest_days" => [
                ["min" => 1, "max" => 25, "days" => 0],
                ["min" => 26, "max" => 40, "days" => 2],
                ["min" => 41, "max" => 55, "days" => 3],
                ["min" => 56, "max" => 65, "days" => 4],
                ["min" => 66, "max" => 75, "days" => 5],
            ],
        ],
        "13U" => [
            "max_pitches" => 75,
            "rest_days" => [
                ["min" => 1, "max" => 35, "days" => 0],
                ["min" => 36, "max" => 55, "days" => 2],
                ["min" => 56, "max" => 75, "days" => 3],
            ],
        ],
        "15U" => [
            "max_pitches" => 85,
            "rest_days" => [
                ["min" => 1, "max" => 35, "days" => 0],
                ["min" => 36, "max" => 65, "days" => 2],
                ["min" => 66, "max" => 85, "days" => 3],
            ],
        ],
        "18U" => [
            "max_pitches" => 100,
            "rest_days" => [
                ["min" => 1, "max" => 45, "days" => 0],
                ["min" => 46, "max" => 65, "days" => 2],
                ["min" => 66, "max" => 100, "days" => 3],
            ],
        ],
        "26U" => [
            "max_pitches" => 120,
            "rest_days" => [["min" => 1, "max" => 120, "days" => 4]],
        ],
    ];

    return $rules[$division] ?? [
        "max_pitches" => 65,
        "rest_days" => [
            ["min" => 1, "max" => 20, "days" => 0],
            ["min" => 21, "max" => 35, "days" => 1],
            ["min" => 36, "max" => 50, "days" => 2],
            ["min" => 51, "max" => 65, "days" => 3],
        ],
    ];
}


function get_max_pitch_count(
    ?string $division = null,
    ?int $teamId = null,
    ?int $ruleSetId = null
): int {
    if ($ruleSetId === null && $teamId !== null && $teamId > 0) {
        $ruleSetId = get_team_pitch_rule_set_id($teamId);
    }

    if ($ruleSetId !== null && $ruleSetId > 0) {
        $ruleSet = get_pitch_rule_set($ruleSetId);

        if ($ruleSet) {
            $maxPitches = (int)($ruleSet['max_pitches_per_day'] ?? 0);

            if ($maxPitches > 0) {
                return $maxPitches;
            }
        }
    }

    // Legacy fallback for teams without a selected rule set.
    $rules = get_pitching_rules_for_division($division);

    return (int)($rules['max_pitches'] ?? 65);
}


function get_team_pitching_rules(int $teamId): array
{
    return get_pitching_rules_for_division(get_team_division($teamId));
}

function get_available_divisions(): array
{
    return ["5U", "7U", "9U", "11U", "13U", "15U", "18U", "26U"];
}

function get_required_rest_days_from_pitch_count(
    int $pitchCount,
    ?string $division,
    ?int $teamId = null,
    ?int $ruleSetId = null
): int {
    if ($pitchCount <= 0) {
        return 0;
    }

    if ($ruleSetId === null && $teamId !== null && $teamId > 0) {
        $ruleSetId = get_team_pitch_rule_set_id($teamId);
    }

    if ($ruleSetId !== null && $ruleSetId > 0) {
        $ruleSet = get_pitch_rule_set($ruleSetId);

        if ($ruleSet) {
            $maxPitches = (int)($ruleSet['max_pitches_per_day'] ?? 0);

            if ($maxPitches > 0 && $pitchCount > $maxPitches) {
                return 999;
            }

            $stmt = db()->prepare("
                SELECT rest_days
                FROM pitch_rest_rules
                WHERE rule_set_id = :rule_set_id
                  AND min_pitches <= :pitch_count
                  AND (
                      max_pitches IS NULL
                      OR max_pitches >= :pitch_count
                  )
                ORDER BY min_pitches DESC
                LIMIT 1
            ");

            $stmt->execute([
                'rule_set_id' => $ruleSetId,
                'pitch_count' => $pitchCount,
            ]);

            $restDays = $stmt->fetchColumn();

            if ($restDays !== false) {
                return (int)$restDays;
            }
        }
    }

    // Fallback for older teams that do not have a pitch_rule_set_id yet.
    $rules = get_pitching_rules_for_division($division);
    $maxPitches = (int)($rules["max_pitches"] ?? 0);

    if ($maxPitches > 0 && $pitchCount > $maxPitches) {
        return 999;
    }

    foreach ($rules["rest_days"] ?? [] as $rule) {
        $min = (int)($rule["min"] ?? 0);
        $max = (int)($rule["max"] ?? 0);
        $days = (int)($rule["days"] ?? 0);

        if ($pitchCount >= $min && $pitchCount <= $max) {
            return $days;
        }
    }

    return 0;
}


function get_no_rest_pitch_limit_for_division(?string $division): int
{
    $rules = get_pitching_rules_for_division($division);
    $limit = 0;
    foreach ($rules['rest_days'] ?? [] as $rule) {
        $days = (int)($rule['days'] ?? -1);
        if ($days === 0) {
            $limit = max($limit, (int)($rule['max'] ?? 0));
        }
    }
    return $limit;
}



function get_pitch_rule_sets(bool $activeOnly = false): array
{
    $sql = "
        SELECT *
        FROM pitch_rule_sets
    ";

    if ($activeOnly) {
        $sql .= " WHERE is_active = 1";
    }

    $sql .= " ORDER BY created_at ASC, id ASC";

    return db()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function get_pitch_rule_set(int $ruleSetId): ?array
{
    $stmt = db()->prepare("
        SELECT *
        FROM pitch_rule_sets
        WHERE id = :id
        LIMIT 1
    ");

    $stmt->execute(['id' => $ruleSetId]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function get_pitch_rest_rules(int $ruleSetId): array
{
    $stmt = db()->prepare("
        SELECT *
        FROM pitch_rest_rules
        WHERE rule_set_id = :rule_set_id
        ORDER BY sort_order ASC, min_pitches ASC
    ");

    $stmt->execute(['rule_set_id' => $ruleSetId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_team_pitch_rule_set_id(int $teamId): ?int
{
    $stmt = db()->prepare("
        SELECT pitch_rule_set_id
        FROM teams
        WHERE id = :team_id
        LIMIT 1
    ");

    $stmt->execute(['team_id' => $teamId]);

    $value = $stmt->fetchColumn();

    return $value ? (int)$value : null;
}
function get_pitch_rule_distinct_values(string $column): array
{
    $allowedColumns = [
        'country',
        'program_name',
        'division_label',
    ];

    if (!in_array($column, $allowedColumns, true)) {
        return [];
    }

    $stmt = db()->query("
        SELECT DISTINCT {$column} AS value
        FROM pitch_rule_sets
        WHERE {$column} IS NOT NULL
          AND {$column} <> ''
        ORDER BY {$column} ASC
    ");

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}
