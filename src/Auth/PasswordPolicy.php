<?php

declare(strict_types=1);

namespace Lotto\Auth;

/**
 * PasswordPolicy — ADR-033
 *
 * Admin password rotation rules (WS admin_change_password).
 * Registration length rules (6–64) are intentionally separate and unchanged.
 */
final class PasswordPolicy
{
    public const ADMIN_MIN_CHARS = 10;
    public const ADMIN_MAX_BYTES = 72;

    /**
     * @return string|null null if valid; otherwise a specific English reason
     */
    public static function validateAdminPassword(string $password): ?string
    {
        if ($password === '') {
            return 'Password must not be empty';
        }

        if (!self::isValidUtf8($password)) {
            return 'Password must be valid UTF-8';
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $password) === 1) {
            return 'Password must not contain control characters';
        }

        $byteLen = strlen($password);
        if ($byteLen > self::ADMIN_MAX_BYTES) {
            return 'Password must be at most 72 bytes';
        }

        $charLen = function_exists('mb_strlen') ? (int) mb_strlen($password, 'UTF-8') : $byteLen;
        if ($charLen < self::ADMIN_MIN_CHARS) {
            return 'Password must be at least 10 characters';
        }

        if (preg_match('/\p{L}/u', $password) !== 1) {
            return 'Password must contain at least one letter';
        }

        if (preg_match('/\p{N}/u', $password) !== 1) {
            return 'Password must contain at least one digit';
        }

        return null;
    }

    private static function isValidUtf8(string $value): bool
    {
        if (function_exists('mb_check_encoding')) {
            return mb_check_encoding($value, 'UTF-8');
        }

        return preg_match('//u', $value) === 1;
    }
}
