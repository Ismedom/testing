<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class PayPalService
{
    protected $client;
    protected $config;

    public function __construct()
    {
        $this->config = Config::get('paypal');
        $this->client = new Client([
            'base_uri' => $this->config['base_url'],
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->getAccessToken(),
                'PayPal-Request-Id' => uniqid(), // Idempotency key
            ],
            'timeout' => 15,
            'verify' => storage_path('certs/cacert.pem'), // Custom CA bundle
        ]);
    }

    private function getAccessToken()
    {
        return Cache::remember('paypal_access_token', 3500, function () {
            $client = new Client(['verify' => storage_path('certs/cacert.pem')]);

            try {
                $response = $client->post($this->config['token_url'], [
                    'auth' => [
                        Crypt::decrypt($this->config['client_id']),
                        Crypt::decrypt($this->config['secret']),
                    ],
                    'form_params' => ['grant_type' => 'client_credentials'],
                ]);

                return json_decode($response->getBody(), true)['access_token'];

            } catch (RequestException $e) {
                Log::critical('PayPal auth failed: ' . $e->getMessage());
                throw new \RuntimeException('Payment system unavailable');
            }
        });
    }

    public function createPlan(array $data)
    {
        return $this->retryableRequest('POST', '/v1/billing/plans', $data);
    }

    private function retryableRequest($method, $endpoint, $data, $retries = 3)
    {
        try {
            $response = $this->client->request($method, $endpoint, [
                'json' => $data,
                'http_errors' => false
            ]);

            if ($response->getStatusCode() >= 500) {
                throw new \Exception("PayPal server error");
            }

            return json_decode($response->getBody(), true);

        } catch (\Exception $e) {
            if ($retries > 0) {
                sleep(3 - $retries); // Simple backoff
                return $this->retryableRequest($method, $endpoint, $data, $retries - 1);
            }
            throw $e;
        }
    }

    public function verifyWebhookSignature($transmissionId, $timestamp, $webhookId, $body, $signature)
    {
        // Implementation of PayPal's webhook signature verification
// https://developer.paypal.com/docs/api/webhooks/v1/#verify-webhook-signature
    }
}