<?php
declare(strict_types=1);

function two_factor_base32_chars(): string
{
    return 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
}

function two_factor_generate_secret(int $length = 32): string
{
    $chars = two_factor_base32_chars();
    $secret = '';

    for ($i = 0; $i < $length; $i++) {
        $secret .= $chars[random_int(0, strlen($chars) - 1)];
    }

    return $secret;
}

function two_factor_base32_decode(string $base32): string
{
    $base32 = strtoupper($base32);
    $base32 = preg_replace('/[^A-Z2-7]/', '', $base32) ?? '';

    $chars = two_factor_base32_chars();
    $bits = '';

    for ($i = 0; $i < strlen($base32); $i++) {
        $value = strpos($chars, $base32[$i]);

        if ($value === false) {
            continue;
        }

        $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
    }

    $binary = '';

    for ($i = 0; $i + 8 <= strlen($bits); $i += 8) {
        $binary .= chr(bindec(substr($bits, $i, 8)));
    }

    return $binary;
}

function two_factor_get_code(string $secret, ?int $timeSlice = null): string
{
    if ($timeSlice === null) {
        $timeSlice = (int)floor(time() / 30);
    }

    $secretKey = two_factor_base32_decode($secret);

    $time = pack('N*', 0) . pack('N*', $timeSlice);
    $hash = hash_hmac('sha1', $time, $secretKey, true);

    $offset = ord(substr($hash, -1)) & 0x0F;

    $value =
        ((ord($hash[$offset]) & 0x7F) << 24) |
        ((ord($hash[$offset + 1]) & 0xFF) << 16) |
        ((ord($hash[$offset + 2]) & 0xFF) << 8) |
        (ord($hash[$offset + 3]) & 0xFF);

    return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
}

function two_factor_verify_code(string $secret, string $code): bool
{
    $code = preg_replace('/\D/', '', $code) ?? '';

    if (strlen($code) !== 6) {
        return false;
    }

    $currentSlice = (int)floor(time() / 30);

    for ($i = -1; $i <= 1; $i++) {
        if (hash_equals(two_factor_get_code($secret, $currentSlice + $i), $code)) {
            return true;
        }
    }

    return false;
}

function two_factor_otpauth_url(string $email, string $secret, string $issuer = 'BenchBuddy'): string
{
    $label = rawurlencode($issuer . ':' . $email);

    return 'otpauth://totp/' . $label
        . '?secret=' . rawurlencode($secret)
        . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1'
        . '&digits=6'
        . '&period=30';
}

function two_factor_qr_url(string $email, string $secret): string
{
    $otpauth = two_factor_otpauth_url($email, $secret);

    return 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . rawurlencode($otpauth);
}
