<?php

declare(strict_types=1);

namespace MyFeedback\Wizard;

use RuntimeException;

final class PayPalSandbox
{
    private const API = 'https://api-m.sandbox.paypal.com';

    private ?string $token = null;

    public function __construct(private readonly array $settings)
    {
    }

    public static function configured(array $settings): bool
    {
        return extension_loaded('curl')
            && is_string($settings['client_id']) && trim($settings['client_id']) !== ''
            && is_string($settings['secret']) && trim($settings['secret']) !== ''
            && is_string($settings['amount'])
            && preg_match('/\A(?:0|[1-9][0-9]{0,7})\.[0-9]{2}\z/', $settings['amount'])
            && $settings['amount'] !== '0.00';
    }

    public function create(string $key, int $poll, string $amount, string $returnUrl): array
    {
        return $this->api('/v2/checkout/orders', 'POST', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => 'poll-' . $poll,
                'description' => 'myFeedback Kompass – Befragung Nr. ' . $poll,
                'amount' => ['currency_code' => 'EUR', 'value' => $amount],
            ]],
            'payment_source' => ['paypal' => ['experience_context' => [
                'shipping_preference' => 'NO_SHIPPING',
                'user_action' => 'PAY_NOW',
                'return_url' => $returnUrl,
                'cancel_url' => $returnUrl . '&cancel=1',
            ]]],
        ], 'o-' . $key);
    }

    public function order(string $id): array
    {
        return $this->api('/v2/checkout/orders/' . rawurlencode($id), 'GET');
    }

    public function capture(string $id, string $key): void
    {
        $this->api('/v2/checkout/orders/' . rawurlencode($id) . '/capture', 'POST', new \stdClass(), 'c-' . $key);
    }

    public function captureDetails(string $id): array
    {
        return $this->api('/v2/payments/captures/' . rawurlencode($id), 'GET');
    }

    private function api(string $path, string $method, array|object|null $body = null, ?string $key = null): array
    {
        if ($this->token === null) {
            $auth = $this->request('/v1/oauth2/token', 'POST', [
                'Content-Type: application/x-www-form-urlencoded',
            ], 'grant_type=client_credentials', true);
            if (!is_string($auth['access_token'] ?? null) || !preg_match('/\A[!-~]+\z/', $auth['access_token'])) {
                throw new RuntimeException('Missing PayPal access token');
            }
            $this->token = $auth['access_token'];
        }

        $headers = ['Authorization: Bearer ' . $this->token, 'Content-Type: application/json'];
        if ($key !== null) {
            $headers[] = 'PayPal-Request-Id: ' . $key;
        }
        return $this->request($path, $method, $headers,
            $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function request(string $path, string $method, array $headers, ?string $body, bool $authenticate = false): array
    {
        $curl = curl_init(self::API . $path);
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 10,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        if ($authenticate) {
            $options[CURLOPT_USERPWD] = $this->settings['client_id'] . ':' . $this->settings['secret'];
            $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
        }
        curl_setopt_array($curl, $options);
        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $data = is_string($response) ? json_decode($response, true) : null;
        if ($status < 200 || $status >= 300 || !is_array($data)) {
            throw new RuntimeException('PayPal API HTTP ' . $status);
        }
        return $data;
    }
}
