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

    public function create(string $key, int $poll, string $amount, string $returnUrl, string $title = ''): array
    {
        return $this->api('/v2/checkout/orders', 'POST', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => 'poll-' . $poll,
                'description' => self::description($title),
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

    /**
     * Bestellung für die PayPal-Buttons. Ohne festgelegte Zahlungsquelle, damit PayPal
     * im Popup auch Karte oder Lastschrift anbieten kann.
     */
    public function createForButtons(string $key, int $poll, string $amount, string $title = ''): array
    {
        return $this->api('/v2/checkout/orders', 'POST', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => 'poll-' . $poll,
                'description' => self::description($title),
                'amount' => ['currency_code' => 'EUR', 'value' => $amount],
            ]],
            'application_context' => [
                'shipping_preference' => 'NO_SHIPPING',
                'brand_name' => 'myFeedback Kompass',
            ],
        ], 'b-' . $key);
    }

    /** Text, den der Kunde in seinem PayPal-Konto sieht. Ohne interne Nummer, höchstens 127 Zeichen. */
    private static function description(string $title): string
    {
        $text = 'myFeedback Kompass – ' . (trim($title) !== '' ? trim($title) : 'Befragung');
        return mb_strlen($text) > 127 ? mb_substr($text, 0, 126) . '…' : $text;
    }

    /** Legt das Abo-Produkt und einen Monatsplan an. Gibt [product_id, plan_id] zurück. */
    public function createPlan(string $amount): array
    {
        $product = $this->api('/v1/catalogs/products', 'POST', [
            'name' => 'myFeedback Kompass – Befragung im Abo',
            'description' => 'Laufende Kundenbefragung, monatlich verlängert',
            'type' => 'SERVICE',
            'category' => 'SOFTWARE',
        ], 'p-' . hash('sha256', 'product-' . $amount));
        $plan = $this->api('/v1/billing/plans', 'POST', [
            'product_id' => $product['id'],
            'name' => 'Befragung im Abo · ' . str_replace('.', ',', $amount) . ' € pro Monat',
            'status' => 'ACTIVE',
            'billing_cycles' => [[
                'frequency' => ['interval_unit' => 'MONTH', 'interval_count' => 1],
                'tenure_type' => 'REGULAR',
                'sequence' => 1,
                'total_cycles' => 0,
                'pricing_scheme' => ['fixed_price' => ['value' => $amount, 'currency_code' => 'EUR']],
            ]],
            'payment_preferences' => [
                'auto_bill_outstanding' => true,
                'setup_fee_failure_action' => 'CANCEL',
                'payment_failure_threshold' => 2,
            ],
        ], 'l-' . hash('sha256', 'plan-' . $amount . '-' . $product['id']));
        return [$product['id'], $plan['id']];
    }

    public function subscription(string $id): array
    {
        return $this->api('/v1/billing/subscriptions/' . rawurlencode($id), 'GET');
    }

    /** Abbuchungen eines Abos im Zeitraum (ISO-8601, UTC) */
    public function subscriptionTransactions(string $id, string $from, string $to): array
    {
        return $this->api('/v1/billing/subscriptions/' . rawurlencode($id) . '/transactions?' . http_build_query([
            'start_time' => $from,
            'end_time' => $to,
        ]), 'GET')['transactions'] ?? [];
    }

    public function cancelSubscription(string $id, string $reason): void
    {
        $this->api('/v1/billing/subscriptions/' . rawurlencode($id) . '/cancel', 'POST', ['reason' => $reason]);
    }

    public function environment(): string
    {
        return str_contains(self::API, 'sandbox') ? 'sandbox' : 'live';
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
        if ($status === 204) {
            return [];
        }
        $data = is_string($response) ? json_decode($response, true) : null;
        if ($status < 200 || $status >= 300 || !is_array($data)) {
            throw new RuntimeException('PayPal API HTTP ' . $status);
        }
        return $data;
    }
}
