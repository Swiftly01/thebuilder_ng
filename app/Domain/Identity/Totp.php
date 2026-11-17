<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use InvalidArgumentException;

final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(int $bytes = 20): string
    {
        return $this->base32Encode(random_bytes($bytes));
    }

    public function code(string $secret, ?int $timestamp = null): string
    {
        $period = (int) config('mfa.period_seconds', 30);

        return $this->codeForTimestep($secret, intdiv($timestamp ?? time(), $period));
    }

    public function matchingTimestep(string $secret, string $code, ?int $timestamp = null): ?int
    {
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return null;
        }

        $period = (int) config('mfa.period_seconds', 30);
        $current = intdiv($timestamp ?? time(), $period);

        foreach ([$current - 1, $current, $current + 1] as $timestep) {
            if (hash_equals($this->codeForTimestep($secret, $timestep), $code)) {
                return $timestep;
            }
        }

        return null;
    }

    public function provisioningUri(string $secret, string $email): string
    {
        $issuer = (string) config('mfa.issuer', 'The Builder');
        $label = rawurlencode($issuer.':'.$email);

        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&period=%d&digits=%d',
            $label,
            $secret,
            rawurlencode($issuer),
            (int) config('mfa.period_seconds', 30),
            (int) config('mfa.digits', 6),
        );
    }

    private function codeForTimestep(string $secret, int $timestep): string
    {
        $binarySecret = $this->base32Decode($secret);
        $counter = pack('N2', intdiv($timestep, 4_294_967_296), $timestep % 4_294_967_296);
        $hash = hash_hmac('sha1', $counter, $binarySecret, true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);
        $modulus = 10 ** (int) config('mfa.digits', 6);

        return str_pad((string) ($binary % $modulus), (int) config('mfa.digits', 6), '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $value): string
    {
        $bits = '';

        foreach (str_split($value) as $character) {
            $bits .= str_pad(decbin(ord($character)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $encoded;
    }

    private function base32Decode(string $value): string
    {
        $bits = '';

        foreach (str_split(strtoupper(rtrim($value, '='))) as $character) {
            $position = strpos(self::ALPHABET, $character);

            if ($position === false) {
                throw new InvalidArgumentException('Invalid base32 secret.');
            }

            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $decoded .= chr(bindec($chunk));
            }
        }

        return $decoded;
    }
}
