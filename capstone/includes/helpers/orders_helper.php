<?php
declare(strict_types=1);

function ordersValidateDate(string $date): bool
{
    $parts = explode('-', $date);
    if (count($parts) !== 3) {
        return false;
    }

    return checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0]);
}

function ordersValidateTime(string $time): bool
{
    return (bool)preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/', $time);
}

function ordersHasValidCoordinates(?float $lat, ?float $lng): bool
{
    if ($lat === null || $lng === null) {
        return false;
    }

    if (!is_finite($lat) || !is_finite($lng)) {
        return false;
    }

    if (abs($lat) > 90 || abs($lng) > 180) {
        return false;
    }

    return !(abs($lat) < 0.000001 && abs($lng) < 0.000001);
}
