<?php
declare(strict_types=1);

return [
    'positions' => ['P', 'C', '1B', '2B', '3B', 'SS', 'LF', 'CF', 'RF'],

    'innings' => 7,
    'min_roster_size' => 8,
    'max_roster_size' => 14,

    'enforce_cannot_play' => false,
    'allow_relaxed_mode' => true,

    'balance_bench_fairness' => true,
    'balance_season_bench_fairness' => true,
    'balance_position_variety' => true,

    'protect_catcher_workload' => true,
    'prefer_pitcher_continuity' => true,

    'max_pitch_innings_per_game' => 5,
    'soft_pitch_innings_target' => 2,
    'hard_avoid_consecutive_bench' => true,

    'season_bench_totals' => [],
];
