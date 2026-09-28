<?php

declare(strict_types=1);

namespace Featvalue\Typo3\Crypto;

use Featvalue\Typo3\Options;

final class KeyStore
{
    public static function ensureKeypair(): void
    {
        if ((string) Options::get(Options::SITE_UUID) !== '') {
            return;
        }
        if (!function_exists('sodium_crypto_sign_keypair')) {
            throw new \RuntimeException('FeatValue requires the PHP sodium extension.');
        }
        $kp = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($kp);
        $public = sodium_crypto_sign_publickey($kp);
        $encKey = self::encryptionKey();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($secret, $nonce, $encKey);
        Options::set(Options::SITE_UUID, self::uuid4());
        Options::set(Options::PUBLIC_KEY, base64_encode($public));
        Options::set(Options::SECRET_KEY, base64_encode($nonce . $cipher));
        sodium_memzero($secret);
        sodium_memzero($encKey);
    }

    public static function decryptSecretKey(): string|false
    {
        $enc = (string) Options::get(Options::SECRET_KEY);
        if ($enc === '') {
            return false;
        }
        $raw = base64_decode($enc, true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return false;
        }
        $encKey = self::encryptionKey();
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $secret = sodium_crypto_secretbox_open($cipher, $nonce, $encKey);
        sodium_memzero($encKey);

        return $secret === false ? false : $secret;
    }

    private static function encryptionKey(): string
    {
        $key = (string) ($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '');
        if ($key === '') {
            throw new \RuntimeException('TYPO3 encryptionKey is empty.');
        }

        return substr(hash('sha256', $key, true), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    private static function uuid4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
