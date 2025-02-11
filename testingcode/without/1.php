<?php
namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class PayPalService
{
    protected $client;
    protected $config;
    protected $token;

    public function __construct()
    {
        $this->config = Config::get('paypal');
        $this->client = new Client([
            'base_uri' => $this->config['base_url'],
            'headers' => [
                'Content-Type' => 'application/json',
                'PayPal-Request-Id' => uniqid(), // Idempotency key
            ],
            'timeout' => 15,
            'verify' => storage_path('certs/cacert.pem'),
        ]);
    }

    private function getAccessToken()
    {
        try {
            $authClient = new Client([
                'verify' => storage_path('certs/cacert.pem'),
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ]
            ]);

            $response = $authClient->post($this->config['token_url'], [
                'auth' => [
                    Crypt::decrypt($this->config['client_id']),
                    Crypt::decrypt($this->config['secret']),
                ],
                'form_params' => ['grant_type' => 'client_credentials'],
            ]);

            $this->token = json_decode($response->getBody(), true)['access_token'];

        } catch (RequestException $e) {
            Log::critical('PayPal auth failed: ' . $e->getMessage());
            throw new \RuntimeException('Payment system unavailable');
        }
    }

    private function makeAuthenticatedRequest($method, $endpoint, $data)
    {
        $this->getAccessToken(); // Fresh token for every request

        try {
            $response = $this->client->request($method, $endpoint, [
                'json' => $data,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token
                ],
                'http_errors' => false
            ]);

            if ($response->getStatusCode() === 401) {
                throw new \Exception("Unauthorized request");
            }

            return json_decode($response->getBody(), true);

        } catch (\Exception $e) {
            Log::error('PayPal API Error: ' . $e->getMessage());
            throw new \RuntimeException('Payment processing error');
        }
    }

    public function createPlan(array $data)
    {
        return $this->makeAuthenticatedRequest('POST', '/v1/billing/plans', $data);
    }

    public function createSubscription(array $data)
    {
        return $this->makeAuthenticatedRequest('POST', '/v1/billing/subscriptions', $data);
    }

    public function subscribe(Request $request)
    {
        try {
            $this->validateSubscriptionRequest($request);

            $plan = PayPalPlan::findOrFail($request->plan_id);
            $subscriptionData = $this->buildSubscriptionData($plan);

            $response = $this->paypalService->createSubscription($subscriptionData);
            $this->storeSubscription($response);

            return redirect()->away($response['links']['approve']);

        } catch (\Exception $e) {
            Log::error('Subscription failed: ' . $e->getMessage());
            return $this->handleSubscriptionError($e);
        }
    }

    private function validateSubscriptionRequest(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'plan_id' => 'required|uuid|exists:pay_pal_plans,id',
            'payment_nonce' => 'required|size:36',
            'risk_token' => 'required'
        ]);

        if ($validator->fails()) {
            throw new InvalidRequestException($validator->errors());
        }

        if (!$this->fraudService->validateRiskToken($request->risk_token)) {
            throw new PotentialFraudException('Invalid risk token');
        }
    }
    private function getAccessTokenWithLock()
    {
        $lock = Cache::lock('paypal_token_lock', 10);

        try {
            $lock->block(5);
            $this->getAccessToken();
            Cache::put('paypal_token', $this->token, 3500); // 58 minutes
        } finally {
            $lock->release();
        }
    }
}