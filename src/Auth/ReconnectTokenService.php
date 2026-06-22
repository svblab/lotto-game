<?php

namespace Lotto\Auth;

class ReconnectTokenService
{
    /**
     * Генерирует криптографически стойкий токен переподключения.
     * Результат: 64 hex-символа (из 32 случайных байт).
     */
    public function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Проверяет валидность формата токена (строго 64 символа и только hex).
     */
    public function validateToken(string $token): bool
    {
        return strlen($token) === 64 && ctype_xdigit($token);
    }

    /**
     * Безопасно сравнивает два токена, предотвращая атаки по времени (Timing Attacks).
     */
    public function tokensEqual(string $token1, string $token2): bool
    {
        return hash_equals($token1, $token2);
    }
}