<?php
declare(strict_types=1);

function get_lineup_coach_notes(int $teamId, int $gameId): array
{
    validate_team_id($teamId);

    $stmt = db()->prepare("
        SELECT
            lcn.*,
            u.full_name AS user_full_name,
            u.email AS user_email
        FROM lineup_coach_notes lcn
        LEFT JOIN users u
            ON u.id = lcn.user_id
        WHERE lcn.team_id = :team_id
          AND lcn.game_id = :game_id
          AND lcn.deleted_at IS NULL
        ORDER BY lcn.created_at DESC, lcn.id DESC
    ");

    $stmt->execute([
        'team_id' => $teamId,
        'game_id' => $gameId,
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function add_lineup_coach_note(int $teamId, int $gameId, ?int $userId, string $note): void
{
    validate_team_id($teamId);

    $note = trim($note);

    if ($gameId <= 0) {
        throw new RuntimeException('No game selected.');
    }

    if ($note === '') {
        throw new RuntimeException('Coach note cannot be empty.');
    }

    if (mb_strlen($note) > 2000) {
        throw new RuntimeException('Coach note is too long. Please keep it under 2,000 characters.');
    }

    $stmt = db()->prepare("
        INSERT INTO lineup_coach_notes (
            team_id,
            game_id,
            user_id,
            note,
            created_at,
            updated_at
        ) VALUES (
            :team_id,
            :game_id,
            :user_id,
            :note,
            NOW(),
            NOW()
        )
    ");

    $stmt->execute([
        'team_id' => $teamId,
        'game_id' => $gameId,
        'user_id' => $userId,
        'note' => $note,
    ]);
}

function delete_lineup_coach_note(int $teamId, int $noteId): void
{
    validate_team_id($teamId);

    if ($noteId <= 0) {
        throw new RuntimeException('No note selected.');
    }

    $stmt = db()->prepare("
        UPDATE lineup_coach_notes
        SET deleted_at = NOW()
        WHERE id = :id
          AND team_id = :team_id
        LIMIT 1
    ");

    $stmt->execute([
        'id' => $noteId,
        'team_id' => $teamId,
    ]);
}
function lineup_note_author_label(array $note): string
{
    $fullName = trim((string)($note['user_full_name'] ?? ''));

    if ($fullName !== '') {
        return $fullName;
    }

    $email = trim((string)($note['user_email'] ?? ''));

    if ($email !== '') {
        return $email;
    }

    return 'Coach';
}
