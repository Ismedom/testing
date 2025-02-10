<?php

namespace App\Providers;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\ServiceProvider;
use Log;

class PaypalProvider extends ServiceProvider
{
    public $client;
    private $clientId;
    private $clientSecret;
    private $baseUrl;
    public $accessToken;

    public function __construct()
    {
        $this->clientId = config('paypal.client_id');
        $this->clientSecret = config('paypal.client_secret');
        $this->baseUrl = config('paypal.mode') === 'sandbox'
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';

        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'verify' => true,
            'timeout' => 30,
        ]);
    }
    public function getAccessToken()
    {
        try {
            $response = $this->client->post('/v1/oauth2/token', [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'auth' => [$this->clientId, $this->clientSecret],
                'form_params' => [
                    'grant_type' => 'client_credentials'
                ]
            ]);

            $data = json_decode($response->getBody(), true);
            $this->accessToken = $data['access_token'];
            return $this->accessToken;

        } catch (RequestException $e) {
            Log::error('PayPal Authentication Error: ' . $e->getMessage());
            throw new Exception('Failed to authenticate with PayPal');
        }
    }

    public function createOrder(array $orderData)
    {
        try {
            if (!$this->accessToken) {
                $this->getAccessToken();
            }

            $response = $this->client->post('/v2/checkout/orders', [
                'headers' => [
                    'Authorization' => "Bearer {$this->accessToken}",
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'intent' => 'CAPTURE',
                    'purchase_units' => [
                        [
                            'amount' => [
                                'currency_code' => $orderData['currency'] ?? 'USD',
                                'value' => number_format($orderData['amount'], 2, '.', '')
                            ],
                            'description' => $orderData['description'] ?? '',
                            'reference_id' => $orderData['reference_id'] ?? uniqid()
                        ]
                    ]
                ]
            ]);

            return json_decode($response->getBody(), true);

        } catch (RequestException $e) {
            Log::error('PayPal Create Order Error: ' . $e->getMessage());
            $this->handlePayPalError($e);
        }
    }

    public function capturePayment($orderId)
    {
        try {
            if (!$this->accessToken) {
                $this->getAccessToken();
            }

            $response = $this->client->post("/v2/checkout/orders/{$orderId}/capture", [
                'headers' => [
                    'Authorization' => "Bearer {$this->accessToken}",
                    'Content-Type' => 'application/json',
                ]
            ]);

            return json_decode($response->getBody(), true);

        } catch (RequestException $e) {
            Log::error('PayPal Capture Payment Error: ' . $e->getMessage());
            $this->handlePayPalError($e);
        }
    }

    public function handlePayPalError(RequestException $e)
    {
        $response = $e->getResponse();
        if ($response) {
            $errorData = json_decode($response->getBody(), true);
            $errorMessage = $errorData['message'] ?? 'Unknown PayPal error';
            $errorCode = $errorData['debug_id'] ?? '';

            Log::error("PayPal Error (Debug ID: {$errorCode}): {$errorMessage}");

            switch ($response->getStatusCode()) {
                case 401:
                    throw new Exception('PayPal authentication failed');
                case 400:
                    throw new Exception('Invalid request to PayPal: ' . $errorMessage);
                case 422:
                    throw new Exception('Payment validation failed: ' . $errorMessage);
                default:
                    throw new Exception('PayPal service error: ' . $errorMessage);
            }
        }

        throw new Exception('Failed to communicate with PayPal');
    }

}
