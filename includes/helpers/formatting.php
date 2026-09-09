<?php
declare(strict_types=1);

function format_datetime_12h(?string $value, string $emptyFallback = ""): string
{
    $value = trim((string) $value);

    if ($value === "") {
        return $emptyFallback;
    }

    $dt = date_create($value);

    if (!$dt) {
        return $value;
    }

    return $dt->format("M.jS, Y") . "<br>" . $dt->format("g:i A");
}


function render_game_timeline(array $game): string
{
    $status = game_display_status($game);

    $isDraft = $status === "draft";
    $isGenerated = $status === "generated";
    $isLocked = $status === "locked";
    $isCancelled = $status === "cancelled";

    ob_start();
    ?>
    <div class="game-timeline">
      <span class="game-timeline-step <?= $isDraft
          ? "active"
          : ($isGenerated || $isLocked || $isCancelled
              ? "done"
              : "") ?>">
        Draft
    </span>

    <span class="game-timeline-line <?= $isGenerated ||
    $isLocked ||
    $isCancelled
        ? "done"
        : "" ?>"></span>

    <span class="game-timeline-step <?= $isGenerated
        ? "active"
        : ($isLocked || $isCancelled
            ? "done"
            : "") ?>">
        Built
    </span>

    <span class="game-timeline-line <?= $isLocked || $isCancelled
        ? "done"
        : "" ?>"></span>

    <span class="game-timeline-step <?= $isLocked
        ? "active"
        : ($isCancelled
            ? "done"
            : "") ?>">
        Locked
    </span>

    <?php if ($isCancelled): ?>
        <span class="game-timeline-line done"></span>
        <span class="game-timeline-step active">Cancelled</span>
    <?php endif; ?>
</div>
<?php return (string) ob_get_clean();
}
