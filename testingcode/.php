<?php

namespace App\Http\Controllers;

use App\Services\PayPal\PayPalSubscriptionService;
use App\Http\Requests\SubscriptionRequest;
use App\Exceptions\PayPalException;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class PayPalController extends Controller
{
    protected $subscriptionService;

    public function __construct(PayPalSubscriptionService $subscriptionService)
    {
        $this->subscriptionService = $subscriptionService;
    }

    public function createSubscriptionPlan(): JsonResponse
    {
        try {
            $plan = $this->subscriptionService->createPlan();
            return response()->json($plan, 201);
        } catch (PayPalException $e) {
            Log::error('PayPal Plan Creation Failed', [
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ]);
            return response()->json(['error' => 'Failed to create subscription plan'], 500);
        }
    }

    public function subscribe(SubscriptionRequest $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $subscription = $this->subscriptionService->createSubscription(
                $request->validated()['plan_id'],
                $user
            );

            return response()->json([
                'redirect_url' => $subscription['approve_url'],
                'subscription_id' => $subscription['id']
            ]);
        } catch (PayPalException $e) {
            Log::error('PayPal Subscription Creation Failed', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
                'plan_id' => $request->plan_id
            ]);
            return response()->json(['error' => 'Subscription creation failed'], 500);
        }
    }

    public function handleWebhook(Request $request): JsonResponse
    {
        try {
            if (!$this->subscriptionService->validateWebhookSignature($request)) {
                throw new PayPalException('Invalid webhook signature');
            }

            $this->subscriptionService->processWebhookEvent($request->all());
            return response()->json(['status' => 'processed']);
        } catch (\Exception $e) {
            Log::error('PayPal Webhook Processing Failed', [
                'error' => $e->getMessage(),
                'payload' => $request->all()
            ]);
            return response()->json(['error' => 'Webhook processing failed'], 500);
        }
    }
}

// PayPalSubscriptionService.php
namespace App\Services\PayPal;

use App\Models\User;
use App\Models\Subscription;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use App\Exceptions\PayPalException;
use Illuminate\Support\Facades\Crypt;

class PayPalSubscriptionService
{
    protected $config;
    protected $baseUrl;
    protected $accessToken;

    public function __construct()
    {
        $this->config = Config::get('paypal');
        $this->baseUrl = $this->config['mode'] === 'sandbox'
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';
    }

    protected function getAccessToken(): string
    {
        return Cache::remember('paypal_access_token', 3500, function () {
            $response = Http::withBasicAuth(
                $this->config['client_id'],
                $this->config['secret']
            )->asForm()->post($this->baseUrl . '/v1/oauth2/token', [
                        'grant_type' => 'client_credentials'
                    ]);

            if (!$response->successful()) {
                throw new PayPalException('Failed to obtain access token');
            }

            return $response->json()['access_token'];
        });
    }

    protected function makeRequest(string $method, string $endpoint, array $data = []): array
    {
        $response = Http::withToken($this->getAccessToken())
                    ->withHeaders([
                        'PayPal-Request-Id' => uniqid('pp_', true),
                        'Prefer' => 'return=representation'
                    ])
            ->$method($this->baseUrl . $endpoint, $data);

        if (!$response->successful()) {
            throw new PayPalException(
                'PayPal API request failed: ' . $response->body(),
                $response->status()
            );
        }

        return $response->json();
    }

    public function createPlan(): array
    {
        $planData = [
            'name' => 'Premium Plan',
            'description' => 'Monthly Premium Subscription',
            'status' => 'ACTIVE',
            'billing_cycles' => [
                [
                    'frequency' => [
                        'interval_unit' => 'MONTH',
                        'interval_count' => 1
                    ],
                    'tenure_type' => 'REGULAR',
                    'sequence' => 1,
                    'total_cycles' => 0,
                    'pricing_scheme' => [
                        'fixed_price' => [
                            'value' => '29.99',
                            'currency_code' => 'USD'
                        ]
                    ]
                ]
            ],
            'payment_preferences' => [
                'auto_bill_outstanding' => true,
                'setup_fee_failure_action' => 'CANCEL',
                'payment_failure_threshold' => 3
            ]
        ];

        return $this->makeRequest('post', '/v1/billing/plans', $planData);
    }

    public function createSubscription(string $planId, User $user): array
    {
        $subscriptionData = [
            'plan_id' => $planId,
            'start_time' => now()->addMinutes(5)->format('Y-m-d\TH:i:s\Z'),
            'subscriber' => [
                'name' => [
                    'given_name' => $user->first_name,
                    'surname' => $user->last_name
                ],
                'email_address' => $user->email
            ],
            'application_context' => [
                'brand_name' => config('app.name'),
                'locale' => 'en-US',
                'shipping_preference' => 'NO_SHIPPING',
                'user_action' => 'SUBSCRIBE_NOW',
                'return_url' => route('paypal.subscription.success'),
                'cancel_url' => route('paypal.subscription.cancel')
            ]
        ];

        $response = $this->makeRequest('post', '/v1/billing/subscriptions', $subscriptionData);

        // Store subscription details in database
        Subscription::create([
            'user_id' => $user->id,
            'paypal_subscription_id' => $response['id'],
            'plan_id' => $planId,
            'status' => 'PENDING',
            'metadata' => Crypt::encrypt(json_encode($response))
        ]);

        return [
            'id' => $response['id'],
            'approve_url' => collect($response['links'])
                ->firstWhere('rel', 'approve')['href']
        ];
    }

    public function validateWebhookSignature(Request $request): bool
    {
        $webhookId = config('paypal.webhook_id');
        $requestHeaders = $request->header();

        $verificationParams = [
            'auth_algo' => $requestHeaders['paypal-auth-algo'][0],
            'cert_url' => $requestHeaders['paypal-cert-url'][0],
            'transmission_id' => $requestHeaders['paypal-transmission-id'][0],
            'transmission_sig' => $requestHeaders['paypal-transmission-sig'][0],
            'transmission_time' => $requestHeaders['paypal-transmission-time'][0],
            'webhook_id' => $webhookId,
            'webhook_event' => $request->getContent()
        ];

        $response = $this->makeRequest(
            'post',
            '/v1/notifications/verify-webhook-signature',
            $verificationParams
        );

        return $response['verification_status'] === 'SUCCESS';
    }

    public function processWebhookEvent(array $payload): void
    {
        $eventType = $payload['event_type'];
        $subscriptionId = $payload['resource']['id'];

        $subscription = Subscription::where('paypal_subscription_id', $subscriptionId)
            ->firstOrFail();

        switch ($eventType) {
            case 'BILLING.SUBSCRIPTION.ACTIVATED':
                $subscription->update(['status' => 'ACTIVE']);
                break;
            case 'BILLING.SUBSCRIPTION.CANCELLED':
                $subscription->update(['status' => 'CANCELLED']);
                break;
            case 'BILLING.SUBSCRIPTION.SUSPENDED':
                $subscription->update(['status' => 'SUSPENDED']);
                break;
            // Add more event handlers as needed
        }
    }
}

// PayPalException.php
namespace App\Exceptions;

class PayPalException extends \Exception
{
    public function report()
    {
        Log::error('PayPal Error', [
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'trace' => $this->getTraceAsString()
        ]);
    }
}

// SubscriptionRequest.php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubscriptionRequest extends FormRequest
{
    public function rules()
    {
        return [
            'plan_id' => 'required|string'
        ];
    }
}

// Migration for subscriptions table
namespace Database\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSubscriptionsTable extends Migration
{
    public function up()
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('paypal_subscription_id')->unique();
            $table->string('plan_id');
            $table->string('status');
            $table->text('metadata')->nullable();
            $table->timestamps();
            $table->index(['paypal_subscription_id', 'status']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('subscriptions');
    }
}