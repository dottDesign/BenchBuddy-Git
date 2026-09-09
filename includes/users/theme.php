<?php
declare(strict_types=1);

function get_allowed_theme_colors(): array
{
    return [
        "red" => [
            "label" => "BenchBuddy Red",
            "primary" => "#ff0000",
            "primary_dark" => "#bb0000",
            "nav_bg" => "#000000",
            "nav_text" => "#ffffff",
        ],
        "blue" => [
            "label" => "Blue",
            "primary" => "#2563eb",
            "primary_dark" => "#1d4ed8",
            "nav_bg" => "#000000",
            "nav_text" => "#ffffff",
        ],
        "green" => [
            "label" => "Green",
            "primary" => "#16a34a",
            "primary_dark" => "#15803d",
            "nav_bg" => "#000000",
            "nav_text" => "#ffffff",
        ],
        "purple" => [
            "label" => "Purple",
            "primary" => "#7c3aed",
            "primary_dark" => "#6d28d9",
            "nav_bg" => "#000000",
            "nav_text" => "#ffffff",
        ],
        "orange" => [
            "label" => "Orange",
            "primary" => "#ea580c",
            "primary_dark" => "#c2410c",
            "nav_bg" => "#000000",
            "nav_text" => "#ffffff",
        ],
        "teal" => [
            "label" => "Teal",
            "primary" => "#0d9488",
            "primary_dark" => "#0f766e",
            "nav_bg" => "#000000",
            "nav_text" => "#ffffff",
        ],
        "cyan" => [
            "label" => "Cyan",
            "primary" => "#0891b2",
            "primary_dark" => "#0e7490",
            "nav_bg" => "#000000",
            "nav_text" => "#ffffff",
        ],
        "indigo" => [
            "label" => "Indigo",
            "primary" => "#4f46e5",
            "primary_dark" => "#4338ca",
            "nav_bg" => "#000000",
            "nav_text" => "#ffffff",
        ],
        "pink" => [
            "label" => "Pink",
            "primary" => "#db2777",
            "primary_dark" => "#be185d",
            "nav_bg" => "#000000",
            "nav_text" => "#ffffff",
        ],
        "rose" => [
            "label" => "Rose",
            "primary" => "#e11d48",
            "primary_dark" => "#be123c",
            "nav_bg" => "#000000",
            "nav_text" => "#ffffff",
        ],
        "amber" => [
            "label" => "Amber",
            "primary" => "#d97706",
            "primary_dark" => "#b45309",
            "nav_bg" => "#000000",
            "nav_text" => "#ffffff",
        ],
        "yellow" => [
            "label" => "Yellow",
            "primary" => "#ca8a04",
            "primary_dark" => "#a16207",
            "nav_bg" => "#000000",
            "nav_text" => "#ffffff",
        ],
        "lime" => [
            "label" => "Lime",
            "primary" => "#65a30d",
            "primary_dark" => "#4d7c0f",
            "nav_bg" => "#000000",
            "nav_text" => "#ffffff",
        ],
        "emerald" => [
            "label" => "Emerald",
            "primary" => "#059669",
            "primary_dark" => "#047857",
            "nav_bg" => "#000000",
            "nav_text" => "#ffffff",
        ],
        "sky" => [
            "label" => "Sky",
            "primary" => "#0284c7",
            "primary_dark" => "#0369a1",
            "nav_bg" => "#000000",
            "nav_text" => "#ffffff",
        ],
        "violet" => [
            "label" => "Violet",
            "primary" => "#8b5cf6",
            "primary_dark" => "#7c3aed",
            "nav_bg" => "#000000",
            "nav_text" => "#ffffff",
        ],
        "fuchsia" => [
            "label" => "Fuchsia",
            "primary" => "#c026d3",
            "primary_dark" => "#a21caf",
            "nav_bg" => "#000000",
            "nav_text" => "#ffffff",
        ],
        "slate" => [
            "label" => "Slate",
            "primary" => "#475569",
            "primary_dark" => "#334155",
            "nav_bg" => "#000000",
            "nav_text" => "#ffffff",
        ],
        "gray" => [
            "label" => "Gray",
            "primary" => "#4b5563",
            "primary_dark" => "#374151",
            "nav_bg" => "#000000",
            "nav_text" => "#ffffff",
        ],
        "black" => [
            "label" => "Black",
            "primary" => "#111827",
            "primary_dark" => "#000000",
            "nav_bg" => "#000000",
            "nav_text" => "#ffffff",
        ],
    ];
}

function normalize_theme_color(string $theme): string
{
    $theme = strtolower(trim($theme));
    $allowed = get_allowed_theme_colors();

    return array_key_exists($theme, $allowed) ? $theme : "red";
}

function get_user_theme_color(int $userId): string
{
    if ($userId <= 0) {
        return "red";
    }

    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT theme_color
        FROM users
        WHERE id = :id
        LIMIT 1
        ");
    $stmt->execute([
        "id" => $userId,
    ]);

    $theme = $stmt->fetchColumn();

    return normalize_theme_color((string) ($theme ?: "red"));
}

function update_user_theme_color(int $userId, string $themeColor): void
{
    if ($userId <= 0) {
        throw new RuntimeException("Invalid user.");
    }

    $themeColor = normalize_theme_color($themeColor);

    $pdo = db();
    $stmt = $pdo->prepare("
        UPDATE users
            SET theme_color = :theme_color
            WHERE id = :id
            LIMIT 1
            ");
    $stmt->execute([
        "theme_color" => $themeColor,
        "id" => $userId,
    ]);
}
function get_theme_vars(string $themeColor): array
{
    $allowed = get_allowed_theme_colors();
    $themeColor = normalize_theme_color($themeColor);

    return $allowed[$themeColor] ?? $allowed['red'];
}
