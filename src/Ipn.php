<?php

declare(strict_types=1);

namespace NowPayments;

/**
 * IPN (Instant Payment Notification) verification utilities.
 * Matches official docs: sort keys recursively, then HMAC-SHA512.
 *
 * @see https://nowpayments.io/help/payments/api
 */
class Ipn
{
    /**
     * Recursively sort object keys (matches NOWPayments IPN spec).
     *
     * @param array<string, mixed> $obj
     * @return array<string, mixed>
     */
    public static function sortObject(array $obj): array
    {
        ksort($obj);
        $result = [];
        foreach ($obj as $key => $val) {
            if ($val !== null && is_array($val) && !self::isList($val)) {
                $result[$key] = self::sortObject($val);
            } else {
                $result[$key] = $val;
            }
        }
        return $result;
    }

    /**
     * Verify IPN callback signature from NOWPayments.
     * Alias for verifySignature (used by NowPayments::verifyIpn).
     *
     * @param string|array<string, mixed> $payload Raw request body (string or parsed array)
     * @param string $signature Value from x-nowpayments-sig header
     * @param string $ipnSecret Your IPN Secret from Dashboard → Store Settings
     * @return bool True if signature is valid, false otherwise
     */
    public static function verify($payload, string $signature, string $ipnSecret): bool
    {
        return self::verifySignature($payload, $signature, $ipnSecret);
    }

    /**
     * Verify IPN callback signature from NOWPayments.
     *
     * @param string|array<string, mixed> $payload Raw request body (string or parsed array)
     * @param string $signature Value from x-nowpayments-sig header
     * @param string $ipnSecret Your IPN Secret from Dashboard → Store Settings
     * @return bool True if signature is valid, false otherwise
     */
    public static function verifySignature($payload, string $signature, string $ipnSecret): bool
    {
        if (trim($signature) === '' || trim($ipnSecret) === '') {
            return false;
        }

        try {
            $obj = self::parsePayload($payload);
            if ($obj === null) {
                return false;
            }
            $jsonString = json_encode(self::sortObject($obj), JSON_UNESCAPED_SLASHES);
            if ($jsonString === false) {
                return false;
            }

            $computedSig = hash_hmac('sha512', $jsonString, trim($ipnSecret), false);
            $sigHex = strtolower(trim($signature));
            if (str_contains($sigHex, '=')) {
                $sigHex = substr($sigHex, strrpos($sigHex, '=') + 1);
            }
            $sigHex = preg_replace('/[^a-f0-9]/', '', $sigHex);
            if ($sigHex === null || $sigHex === '') {
                return false;
            }
            if (strlen($sigHex) !== strlen($computedSig)) {
                return false;
            }

            return hash_equals($computedSig, $sigHex);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Create IPN signature for testing (e.g., mocking callbacks).
     *
     * @param array<string, mixed> $payload
     */
    public static function createSignature(array $payload, string $ipnSecret): string
    {
        $jsonString = json_encode(self::sortObject($payload), JSON_UNESCAPED_SLASHES);
        return hash_hmac('sha512', $jsonString, trim($ipnSecret), false);
    }

    /**
     * @param string|array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private static function parsePayload($payload): ?array
    {
        if (is_array($payload)) {
            return $payload;
        }
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            return is_array($decoded) ? $decoded : null;
        }
        return null;
    }

    /**
     * Check if array is a list (sequential keys 0,1,2...).
     *
     * @param array<mixed> $arr
     */
    private static function isList(array $arr): bool
    {
        if ($arr === []) {
            return true;
        }
        return array_keys($arr) === range(0, count($arr) - 1);
    }
}
