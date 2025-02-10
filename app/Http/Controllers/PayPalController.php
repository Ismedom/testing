<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Config;

class PayPalController extends Controller
{
    private $client;

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => Config::get('paypal.mode') == 'sandbox' ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com',
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->getAccessToken(),
            ],
        ]);
    }

    private function getAccessToken()
    {
        $client = new Client();
        $response = $client->post(Config::get('paypal.mode') == 'sandbox' ? 'https://api-m.sandbox.paypal.com/v1/oauth2/token' : 'https://api-m.paypal.com/v1/oauth2/token', [
            'auth' => [Config::get('paypal.client_id'), Config::get('paypal.secret')],
            'form_params' => [
                'grant_type' => 'client_credentials',
            ],
        ]);

        return json_decode($response->getBody(), true)['access_token'];
    }

    public function createSubscriptionPlan()
    {
        $planData = [
            "name" => "Basic Plan",
            "description" => "A basic plan",
            "status" => "ACTIVE",
            "billing_cycles" => [
                [
                    "frequency" => [
                        "interval_unit" => "MONTH",
                        "interval_count" => 1,
                    ],
                    "tenure_type" => "REGULAR",
                    "sequence" => 1,
                    "total_cycles" => 0,
                    "pricing_scheme" => [
                        "fixed_price" => [
                            "value" => "10.00",
                            "currency_code" => "USD",
                        ],
                    ],
                ],
            ],
            "payment_preferences" => [
                "auto_bill_outstanding" => true,
                "setup_fee" => [
                    "value" => "0",
                    "currency_code" => "USD",
                ],
                "setup_fee_failure_action" => "CONTINUE",
                "payment_failure_threshold" => 3,
            ],
        ];

        $response = $this->client->post('/v1/billing/plans', [

            'json' => $planData,
        ]);

        return response()->json(json_decode($response->getBody(), true));
    }

    public function subscribe(Request $request)
    {
        $planId = $request->input('plan_id');

        $subscriptionData = [
            "plan_id" => $planId,
            "start_time" => now()->addMinutes(5)->format('Y-m-d\TH:i:s\Z'),
            "subscriber" => [
                "name" => [
                    "given_name" => "John",
                    "surname" => "Doe",
                ],
                "email_address" => "john.doe@example.com",
            ],
            "application_context" => [
                "brand_name" => "Your Brand Name",
                "locale" => "en-US",
                "shipping_preference" => "NO_SHIPPING",
                "user_action" => "SUBSCRIBE_NOW",
                "payment_method" => [
                    "payer_selected" => "PAYPAL",
                    "payee_preferred" => "IMMEDIATE_PAYMENT_REQUIRED",
                ],
                "return_url" => route('paypal.subscription.success'),
                "cancel_url" => route('paypal.subscription.cancel'),
            ],
        ];

        $response = $this->client->post('/v1/billing/subscriptions', [
            'json' => $subscriptionData,
        ]);

        $subscription = json_decode($response->getBody(), true);

        return redirect($subscription['links'][1]['href']);
    }

    public function showSubscribeForm()
    {

        $planId = '';

        return view('subscribe', ['planId' => $planId]);
    }

    public function subscriptionSuccess()
    {
        return view('paypal.subscription-success');
    }

    public function subscriptionCancel()
    {
        return view('paypal.subscription-cancel');
    }
}