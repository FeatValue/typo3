<?php

declare(strict_types=1);

namespace Featvalue\Typo3\Api;

use Featvalue\Typo3\Crypto\KeyStore;
use Featvalue\Typo3\Options;

final class HealthService
{
    public const HEALTH_PREFIX = 'featvalue-health-v1|';

    public static function signChallenge(string $challenge): string
    {
        $secret = KeyStore::decryptSecretKey();
        if ($secret === false) {
            throw new \RuntimeException('No private key found.');
        }
        $signature = sodium_crypto_sign_detached(self::HEALTH_PREFIX . $challenge, $secret);
        sodium_memzero($secret);

        return base64_encode($signature);
    }

    public static function verifyWebhook(string $raw, string $timestamp, string $signature): bool
    {
        $token = (string) Options::get(Options::CONNECTION_TOKEN);
        if ($token === '' || $signature === '' || !preg_match('/^\d+$/', $timestamp)) {
            return false;
        }
        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp . '|' . $raw, $token);

        return hash_equals($expected, $signature);
    }
}
