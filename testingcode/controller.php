<?php
namespace App\Http\Controllers;

use App\Models\PayPalPlan;
use App\Models\PayPalSubscription;
use App\Services\PayPalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PayPalController extends Controller
{
    protected $paypalService;

    public function __construct(PayPalService $paypalService)
    {
        $this->paypalService = $paypalService;
        $this->middleware(['auth', 'verified']); // Require authenticated users
        $this->middleware('throttle:60,1'); // Rate limiting
    }

    public function createSubscriptionPlan()
    {
        try {
            DB::beginTransaction();

            $planData = [
                "name" => "Basic Plan",
                "description" => Crypt::encrypt("A basic plan"), // Encrypt sensitive description
                "status" => "ACTIVE",
                // ... rest of plan data ...
            ];

            $response = $this->paypalService->createPlan($planData);

            $plan = PayPalPlan::create([
                'paypal_plan_id' => Crypt::encrypt($response['id']),
                'name' => hash('sha256', $planData['name']), // Pseudonymize name
                'description_hash' => hash('sha256', $planData['description']),
                'status' => $response['status'],
                'encrypted_data' => Crypt::encrypt(json_encode($response)),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'plan_id' => $plan->id,
                'paypal_plan_id' => Crypt::decrypt($plan->paypal_plan_id)
            ], 201, [
                'Content-Security-Policy' => "default-src 'self'",
                'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Plan creation failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Plan creation failed. Please try again.'
            ], 500);
        }
    }

    public function subscribe(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'plan_id' => 'required|uuid|exists:pay_pal_plans,id',
            'agree_terms' => 'required|accepted'
        ], [
            'plan_id.exists' => 'Invalid subscription plan',
            'agree_terms.accepted' => 'You must accept the terms and conditions'
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        try {
            $plan = PayPalPlan::findOrFail($request->plan_id);
            $paypalPlanId = Crypt::decrypt($plan->paypal_plan_id);

            $subscriptionData = [
                "plan_id" => $paypalPlanId,
                "subscriber" => [
                    "email_address" => Crypt::encrypt(auth()->user()->email),
                    // ... other subscriber data ...
                ],
                "application_context" => [
                    "return_url" => route('paypal.subscription.success'),
                    "cancel_url" => route('paypal.subscription.cancel'),
                ]
            ];

            $response = $this->paypalService->createSubscription($subscriptionData);

            $subscription = PayPalSubscription::create([
                'user_id' => auth()->id(),
                'paypal_plan_id' => $plan->id,
                'paypal_subscription_id' => Crypt::encrypt($response['id']),
                'status' => 'PENDING',
                'metadata' => Crypt::encrypt(json_encode($response)),
            ]);

            return redirect()->away($response['links']['approve']);

        } catch (\Exception $e) {
            Log::error('Subscription failed: ' . $e->getMessage());
            return redirect()->back()
                ->with('error', 'Subscription setup failed. Please try again.');
        }
    }

    public function subscriptionSuccess(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'subscription_id' => 'required',
                'token' => 'required|size:40'
            ]);

            if ($validator->fails() || !$this->validateWebhookSignature($request)) {
                throw new \Exception('Invalid request signature');
            }

            $subscription = $this->paypalService->getSubscriptionDetails(
                $request->subscription_id
            );

            if ($subscription['status'] !== 'ACTIVE') {
                throw new \Exception('Subscription not active');
            }

            PayPalSubscription::where(
                'paypal_subscription_id',
                Crypt::encrypt($request->subscription_id)
            )
                ->update(['status' => 'ACTIVE']);

            return view('subscription-success')
                ->with('subscription', $subscription);

        } catch (\Exception $e) {
            Log::warning('Invalid success attempt: ' . $e->getMessage());
            return redirect()->route('home')
                ->with('error', 'Subscription verification failed');
        }
    }

    private function validateWebhookSignature(Request $request): bool
    {
        $transmissionId = $request->header('PAYPAL-TRANSMISSION-ID');
        $timestamp = $request->header('PAYPAL-TRANSMISSION-TIME');
        $signature = $request->header('PAYPAL-TRANSMISSION-SIG');
        $webhookId = config('paypal.webhook_id');

        return $this->paypalService->verifyWebhookSignature(
            $transmissionId,
            $timestamp,
            $webhookId,
            $request->getContent(),
            $signature
        );
    }

    public function handleWebhook(Request $request)
    {
        try {
            if (!$this->validateWebhookSignature($request)) {
                Log::warning('Invalid webhook signature');
                abort(403);
            }

            $eventType = $request->event_type;
            $resource = $request->resource;

            $this->paypalService->processWebhookEvent($eventType, $resource);

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            Log::error('Webhook handling failed: ' . $e->getMessage());
            return response()->json(['error' => 'Processing failed'], 500);
        }
    }
}