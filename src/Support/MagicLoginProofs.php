<?php

namespace LiveNetworks\LnStarter\Support;

use InvalidArgumentException;
use RuntimeException;

class MagicLoginProofs
{
    public function assertConfigured(): void
    {
        $current = $this->currentPepperId();
        $this->pepper($current);
    }

    public function currentPepperId(): string
    {
        $id = config('ln-starter.auth.peppers.current');

        if (!is_string($id) || $id === '') {
            throw new RuntimeException('LN-Starter auth pepper current ID is not configured.');
        }

        return $id;
    }

    public function pepper(string $id): string
    {
        $value = config('ln-starter.auth.peppers.keys.' . $id);

        if (!is_string($value) || $value === '') {
            throw new RuntimeException("LN-Starter auth pepper [{$id}] is not configured.");
        }

        if (str_starts_with($value, 'base64:')) {
            $decoded = base64_decode(substr($value, 7), true);
            if ($decoded === false) {
                throw new RuntimeException("LN-Starter auth pepper [{$id}] is not valid base64.");
            }
            $value = $decoded;
        }

        if (strlen($value) < 32) {
            throw new RuntimeException("LN-Starter auth pepper [{$id}] must contain at least 32 bytes.");
        }

        return $value;
    }

    public function canonicalEmail(string $email): string
    {
        $email = trim($email);
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        if ($domain !== '' && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii !== false) {
                $domain = $ascii;
            }
        }

        return mb_strtolower($local . '@' . $domain, 'UTF-8');
    }

    public function emailKey(string $email, ?string $pepperId = null): string
    {
        return $this->hmac('email', $this->canonicalEmail($email), $pepperId);
    }

    public function codeHash(string $attemptId, string $code, string $pepperId): string
    {
        if (!preg_match('/^\d{6}$/', $code)) {
            throw new InvalidArgumentException('Magic login code must contain exactly six digits.');
        }

        return $this->hmac('code', $attemptId . "\0" . $code, $pepperId);
    }

    public function rateKey(string $purpose, string $value): string
    {
        return $this->hmac('rate:' . $purpose, $value);
    }

    public function hashLinkToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function hashRequesterNonce(string $nonce): string
    {
        return hash('sha256', $nonce);
    }

    public function generateLinkToken(): string
    {
        return $this->base64Url(random_bytes(32));
    }

    public function generateRequesterNonce(): string
    {
        return $this->base64Url(random_bytes(32));
    }

    public function generateContextId(): string
    {
        return $this->base64Url(random_bytes(18));
    }

    public function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function hmac(string $purpose, string $value, ?string $pepperId = null): string
    {
        $pepperId ??= $this->currentPepperId();

        return hash_hmac('sha256', $purpose . "\0" . $value, $this->pepper($pepperId));
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
