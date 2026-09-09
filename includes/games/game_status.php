<?php
declare(strict_types=1);


function game_status_label(array $game): string
{
    $status = game_display_status($game);

    return match ($status) {
        "draft" => "Draft",
        "generated" => "Ready to Finalize",
        "locked" => "Finalized",
        "cancelled" => "Cancelled",
        default => "Unknown",
    };
}

function game_display_status(array $game): string
{
    if (!empty($game["deleted_at"])) {
        return "cancelled";
    }

    return strtolower(trim((string) ($game["status"] ?? "draft")));
}
