<?php
declare(strict_types=1);

function get_valid_promo_code(string $code): ?array
{
    $code = strtoupper(trim($code));

    if ($code === '') {
        return null;
    }

    $stmt = db()->prepare("
        SELECT *
        FROM promo_codes
        WHERE code = :code
          AND active = 1
          AND (starts_at IS NULL OR starts_at <= NOW())
          AND (ends_at IS NULL OR ends_at >= NOW())
          AND (max_redemptions IS NULL OR redemption_count < max_redemptions)
        LIMIT 1
    ");

    $stmt->execute([
        'code' => $code,
    ]);

    $promo = $stmt->fetch(PDO::FETCH_ASSOC);

    return $promo ?: null;
}
