<?php

declare(strict_types=1);

namespace NowPayments;

/**
 * NOWPayments PHP SDK
 * Full-featured client for the NOWPayments cryptocurrency payment API
 *
 * @see https://documenter.getpostman.com/view/7907941/2s93JusNJt
 */
class NowPayments
{
    private HttpClient $client;
    private array $config;

    public function __construct(array $config)
    {
        // Accept both camelCase and snake_case config keys
        if (!isset($config['apiKey']) && isset($config['api_key'])) {
            $config['apiKey'] = $config['api_key'];
        }
        if (!isset($config['baseUrl']) && isset($config['base_url'])) {
            $config['baseUrl'] = $config['base_url'];
        }
        if (!isset($config['ipnSecret']) && isset($config['ipn_secret'])) {
            $config['ipnSecret'] = $config['ipn_secret'];
        }

        $apiKey = $config['apiKey'] ?? '';
        if (!is_string($apiKey) || trim($apiKey) === '') {
            throw new \InvalidArgumentException(
                'NOWPayments API key is required. Get yours at https://account.nowpayments.io'
            );
        }
        $config['apiKey'] = trim($apiKey);
        $this->config = array_merge([
            'sandbox' => false,
            'timeout' => 30,
            'baseUrl' => null,
            'ipnSecret' => null,
        ], $config);
        $this->client = new HttpClient($this->config);
    }

    /** Check if API is up and available */
    public function getStatus(): array
    {
        return $this->client->get('/v1/status');
    }

    /** Get list of available crypto currencies */
    public function getCurrencies(?bool $fixedRate = null): array
    {
        $params = $fixedRate !== null ? ['fixed_rate' => $fixedRate] : [];
        return $this->client->get('/v1/currencies', $params);
    }

    /** Get full currency details */
    public function getFullCurrencies(): array
    {
        return $this->client->get('/v1/full-currencies');
    }

    /** Get merchant checked currencies */
    public function getMerchantCoins(?bool $fixedRate = null): array
    {
        $params = $fixedRate !== null ? ['fixed_rate' => $fixedRate] : [];
        return $this->client->get('/v1/merchant/coins', $params);
    }

    /** Get JWT token (required for payouts, custody, etc.) */
    public function getAuthToken(string $email, string $password): array
    {
        return $this->client->post('/v1/auth', [
            'email' => $email,
            'password' => $password,
        ]);
    }

    /** Get single currency details */
    public function getCurrency(string $currency): array
    {
        $code = trim($currency);
        if ($code === '' || !preg_match('/^[a-z0-9]+$/i', $code)) {
            throw new \InvalidArgumentException('Currency code is required (e.g. "btc", "eth")');
        }
        return $this->client->get('/v1/currencies/' . rawurlencode($code));
    }

    /** Get estimated price in crypto for a fiat amount */
    public function getEstimatePrice(array $params): array
    {
        if (!isset($params['amount'], $params['currency_from'], $params['currency_to'])) {
            throw new \InvalidArgumentException('getEstimatePrice requires: amount, currency_from, currency_to');
        }
        return $this->client->get('/v1/estimate', [
            'amount' => $params['amount'],
            'currency_from' => $params['currency_from'],
            'currency_to' => $params['currency_to'],
        ]);
    }

    /** Get minimum payment amount for currency pair */
    public function getMinAmount(array $params): array
    {
        if (!isset($params['currency_from'], $params['currency_to'])) {
            throw new \InvalidArgumentException('getMinAmount requires: currency_from, currency_to');
        }
        $query = [
            'currency_from' => $params['currency_from'],
            'currency_to' => $params['currency_to'],
        ];
        if (isset($params['fiat_equivalent'])) {
            $query['fiat_equivalent'] = $params['fiat_equivalent'];
        }
        if (isset($params['is_fixed_rate'])) {
            $query['is_fixed_rate'] = $params['is_fixed_rate'];
        }
        if (isset($params['is_fee_paid_by_user'])) {
            $query['is_fee_paid_by_user'] = $params['is_fee_paid_by_user'];
        }
        return $this->client->get('/v1/min-amount', $query);
    }

    /** Create a new payment */
    public function createPayment(array $params): array
    {
        $body = $params;
        $originIp = null;
        if (isset($body['origin_ip'])) {
            $originIp = $body['origin_ip'];
            unset($body['origin_ip']);
        }
        if (isset($body['fixed_rate']) && !isset($body['is_fixed_rate'])) {
            $body['is_fixed_rate'] = $body['fixed_rate'];
            unset($body['fixed_rate']);
        } else {
            unset($body['fixed_rate']);
        }
        $headers = [];
        if (is_string($originIp) && trim($originIp) !== '') {
            $headers['origin-ip'] = trim($originIp);
        }
        return $this->client->post('/v1/payment', $body, null, $headers);
    }

    /** Get payment status by ID */
    public function getPaymentStatus($paymentId): array
    {
        if ($paymentId === null || trim((string) $paymentId) === '') {
            throw new \InvalidArgumentException('Payment ID is required');
        }
        return $this->client->get('/v1/payment/' . rawurlencode((string) $paymentId));
    }

    /** Get paginated list of payments. JWT recommended per API docs. */
    public function getPayments(?array $params = null, ?string $jwtToken = null): array
    {
        return $this->client->get('/v1/payment/', $params ?? [], $jwtToken);
    }

    /** Update payment estimate */
    public function updatePaymentEstimate($paymentId): array
    {
        $id = (string) $paymentId;
        if (trim($id) === '') {
            throw new \InvalidArgumentException('Payment ID is required');
        }
        return $this->client->post('/v1/payment/' . rawurlencode($id) . '/update-merchant-estimate', null);
    }

    /** Create an invoice */
    public function createInvoice(array $params): array
    {
        $body = $params;
        $headers = [];
        if (isset($body['origin_ip']) && is_string($body['origin_ip']) && trim($body['origin_ip']) !== '') {
            $headers['origin-ip'] = trim($body['origin_ip']);
            unset($body['origin_ip']);
        }
        return $this->client->post('/v1/invoice', $body, null, $headers);
    }

    /** Create payment for existing invoice */
    public function createInvoicePayment(array $params): array
    {
        return $this->client->post('/v1/invoice-payment', $params);
    }

    /** List all recurring payments */
    public function getSubscriptions(?array $params = null): array
    {
        return $this->client->get('/v1/subscriptions', $params ?? []);
    }

    /** Get single recurring payment */
    public function getSubscription(string $id): array
    {
        return $this->client->get('/v1/subscriptions/' . rawurlencode($id));
    }

    /** Cancel recurring payment */
    public function deleteSubscription(string $id, ?string $jwtToken = null): array
    {
        return $this->client->delete('/v1/subscriptions/' . rawurlencode($id), $jwtToken);
    }

    /** List subscription plans */
    public function getSubscriptionPlans(?array $params = null): array
    {
        return $this->client->get('/v1/subscriptions/plans', $params ?? []);
    }

    /** Get single subscription plan */
    public function getSubscriptionPlan(string $id): array
    {
        return $this->client->get('/v1/subscriptions/plans/' . rawurlencode($id));
    }

    /** Update subscription plan */
    public function updateSubscriptionPlan(string $id, array $updates): array
    {
        return $this->client->patch('/v1/subscriptions/plans/' . rawurlencode($id), $updates);
    }

    /** List sub-partners */
    public function getSubPartners(?array $params = null, ?string $jwtToken = null): array
    {
        return $this->client->get('/v1/sub-partner', $params ?? [], $jwtToken);
    }

    /** Get sub-partner balance */
    public function getSubPartnerBalance(string $subPartnerId): array
    {
        return $this->client->get('/v1/sub-partner/balance/' . rawurlencode($subPartnerId));
    }

    /** List transfers */
    public function getTransfers(?array $params = null, ?string $jwtToken = null): array
    {
        return $this->client->get('/v1/sub-partner/transfers', $params ?? [], $jwtToken);
    }

    /** Get single transfer */
    public function getTransfer(string $id, ?string $jwtToken = null): array
    {
        return $this->client->get('/v1/sub-partner/transfer/' . rawurlencode($id), [], $jwtToken);
    }

    /** Create mass payout */
    public function createPayout(array $params, string $jwtToken): array
    {
        if (trim($jwtToken) === '') {
            throw new \InvalidArgumentException('JWT token is required for createPayout. Call getAuthToken first.');
        }
        return $this->client->post('/v1/payout', $params, $jwtToken);
    }

    /** Verify payout with 2FA code */
    public function verifyPayout(string $payoutId, string $verificationCode, string $jwtToken): array
    {
        if (trim($jwtToken) === '') {
            throw new \InvalidArgumentException('JWT token is required for verifyPayout. Call getAuthToken first.');
        }
        return $this->client->post('/v1/payout/' . rawurlencode($payoutId) . '/verify', [
            'verification_code' => $verificationCode,
        ], $jwtToken);
    }

    /** Get payout status */
    public function getPayoutStatus(string $payoutId, ?string $jwtToken = null): array
    {
        return $this->client->get('/v1/payout/' . rawurlencode($payoutId), [], $jwtToken);
    }

    /** List payouts */
    public function getPayouts(?array $params = null): array
    {
        return $this->client->get('/v1/payout', $params ?? []);
    }

    /** Validate payout address */
    public function validatePayoutAddress(array $params): array
    {
        return $this->client->post('/v1/payout/validate-address', $params);
    }

    /** Estimate network fee for a payout */
    public function getPayoutFee(string $currency, float $amount): array
    {
        if (trim($currency) === '') {
            throw new \InvalidArgumentException('Currency is required (e.g. "btc", "eth")');
        }
        if (!is_finite($amount) || $amount <= 0) {
            throw new \InvalidArgumentException('Amount must be a positive number');
        }
        return $this->client->get('/v1/payout/fee', [
            'currency' => $currency,
            'amount' => $amount,
        ]);
    }

    /** Cancel a scheduled payout */
    public function cancelPayout(string $payoutId, string $jwtToken): void
    {
        if (trim($jwtToken) === '') {
            throw new \InvalidArgumentException('JWT token is required for cancelPayout. Call getAuthToken first.');
        }
        $this->client->post('/v1/payout/w_id/cancel', ['payout_id' => $payoutId], $jwtToken);
    }

    /** Get crypto currencies for fiat cashout */
    public function getFiatPayoutsCryptoCurrencies(?array $params = null, ?string $jwtToken = null): array
    {
        return $this->client->get('/v1/fiat-payouts/crypto-currencies', $params ?? [], $jwtToken);
    }

    /** Get payment methods for fiat payout */
    public function getFiatPayoutsPaymentMethods(?array $params = null, ?string $jwtToken = null): array
    {
        return $this->client->get('/v1/fiat-payouts/payment-methods', $params ?? [], $jwtToken);
    }

    /** List fiat payouts */
    public function getFiatPayouts(?array $params = null, ?string $jwtToken = null): array
    {
        return $this->client->get('/v1/fiat-payouts', $params ?? [], $jwtToken);
    }

    /** Get custody balance */
    public function getBalance(?string $jwtToken = null): array
    {
        return $this->client->get('/v1/balance', [], $jwtToken);
    }

    /** Create new sub-partner */
    public function createSubPartner(string $name, string $jwtToken): array
    {
        if (trim($jwtToken) === '') {
            throw new \InvalidArgumentException('JWT token is required for createSubPartner. Call getAuthToken first.');
        }
        return $this->client->post('/v1/sub-partner/balance', ['name' => $name], $jwtToken);
    }

    /** Create sub-partner payment (deposit) */
    public function createSubPartnerPayment(array $params, string $jwtToken): array
    {
        if (trim($jwtToken) === '') {
            throw new \InvalidArgumentException('JWT token is required for createSubPartnerPayment. Call getAuthToken first.');
        }
        $body = $params;
        if (array_key_exists('is_fixed_rate', $body) && !array_key_exists('fixed_rate', $body)) {
            $body['fixed_rate'] = $body['is_fixed_rate'];
            unset($body['is_fixed_rate']);
        }
        return $this->client->post('/v1/sub-partner/payment', $body, $jwtToken);
    }

    /** Create subscription */
    public function createSubscription(array $params, string $jwtToken): array
    {
        if (trim($jwtToken) === '') {
            throw new \InvalidArgumentException('JWT token is required for createSubscription. Call getAuthToken first.');
        }
        return $this->client->post('/v1/subscriptions', $params, $jwtToken);
    }

    /** Transfer between user accounts */
    public function createTransfer(array $params, string $jwtToken): array
    {
        if (trim($jwtToken) === '') {
            throw new \InvalidArgumentException('JWT token is required for createTransfer. Call getAuthToken first.');
        }
        return $this->client->post('/v1/sub-partner/transfer', $params, $jwtToken);
    }

    /** Write off from user to master account */
    public function writeOff(array $params, string $jwtToken): array
    {
        if (trim($jwtToken) === '') {
            throw new \InvalidArgumentException('JWT token is required for writeOff. Call getAuthToken first.');
        }
        return $this->client->post('/v1/sub-partner/write-off', $params, $jwtToken);
    }

    /** Deposit from master to user account */
    public function deposit(array $params, string $jwtToken): array
    {
        if (trim($jwtToken) === '') {
            throw new \InvalidArgumentException('JWT token is required for deposit. Call getAuthToken first.');
        }
        return $this->client->post('/v1/sub-partner/deposit', $params, $jwtToken);
    }

    /** Create conversion within custody account */
    public function createConversion(array $params, string $jwtToken): array
    {
        if (trim($jwtToken) === '') {
            throw new \InvalidArgumentException('JWT token is required for createConversion. Call getAuthToken first.');
        }
        return $this->client->post('/v1/conversion', $params, $jwtToken);
    }

    /** Get conversion status */
    public function getConversionStatus(string $conversionId, string $jwtToken): array
    {
        if (trim($jwtToken) === '') {
            throw new \InvalidArgumentException('JWT token is required for getConversionStatus. Call getAuthToken first.');
        }
        return $this->client->get('/v1/conversion/' . rawurlencode($conversionId), [], $jwtToken);
    }

    /** List conversions */
    public function getConversions(?array $params = null, ?string $jwtToken = null): array
    {
        return $this->client->get('/v1/conversion', $params ?? [], $jwtToken);
    }

    /** Verify IPN webhook signature */
    public function verifyIpn($payload, string $signature): bool
    {
        $secret = $this->config['ipnSecret'] ?? null;
        if (!is_string($secret) || trim($secret) === '') {
            throw new \RuntimeException(
                'IPN secret not configured. Pass ipnSecret in constructor or use Ipn::verifySignature() with explicit secret.'
            );
        }
        return Ipn::verify($payload, $signature, $secret);
    }
}
