<?php namespace Lotto\Auth; /** * Class SessionService * Centralized 
 service for generating, validating, and safely comparing session 
 tokens. */
final class SessionService { /** * Generates a new cryptographically 
     secure session token. * * @return string 32-character hexadecimal 
     string * @throws \Exception If an appropriate source of randomness 
     cannot be found. */
    public function generateToken(): string { return 
        bin2hex(random_bytes(16));
    }
    /** * Validates whether the given token format is correct 
     (32-character hex string). * * @param string $token * @return bool 
     */
    public function isValidToken(string $token): bool { if 
        (strlen($token) !== 32) {
            return false;
        }
        return preg_match('/^[a-f0-9]{32}$/', $token) === 1;
        /** * return ctype_xdigit($token);*/
    }
    /** * Performs a timing-attack resistant comparison of two session 
     tokens. * * @param string $tokenA * @param string $tokenB * @return 
     bool */
    public function tokensEqual(string $tokenA, string $tokenB): bool { 
        return hash_equals($tokenA, $tokenB);
    }
}

