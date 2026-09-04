<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Front\Concerns\ResolvesFrontendView;
use App\Models\Sales\ContractWithdrawal;
use App\Models\Sales\Order\Order;
use App\Models\User\UserAddress;
use App\Services\Front\ContractWithdrawalNotificationService;
use App\Services\Front\StoreSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ReturnRequestController extends Controller
{
    use ResolvesFrontendView;

    private const DRAFT_SESSION_PREFIX = 'contract_withdrawal_drafts.';

    public function __construct(
        private readonly ContractWithdrawalNotificationService $notifications,
        private readonly StoreSettingsService $storeSettings,
    ) {}

    public function create(Request $request): View
    {
        return view($this->frontendView($request, 'returns.create'), [
            'prefill' => $this->prefill($request),
            'withdrawalSettings' => $this->storeSettings->withdrawal(),
        ]);
    }

    public function review(Request $request): View
    {
        $validated = $this->validateSubmission($request);
        $this->verifyCaptcha($request, $validated);

        $data = $this->normalize($validated);
        $token = (string) Str::uuid();

        $request->session()->put(self::DRAFT_SESSION_PREFIX.$token, [
            'data' => $data,
            'locale' => app()->getLocale(),
            'user_id' => $request->user()?->id,
            'expires_at' => now()->addMinutes(30)->timestamp,
        ]);

        return view($this->frontendView($request, 'returns.review'), [
            'draftToken' => $token,
            'withdrawal' => $data,
            'declaration' => $this->declaration($data['order_number']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'draft_token' => ['required', 'uuid'],
        ]);

        $token = (string) $validated['draft_token'];
        $draftKey = self::DRAFT_SESSION_PREFIX.$token;
        $draft = $request->session()->get($draftKey);

        if (! is_array($draft) || (int) ($draft['expires_at'] ?? 0) < now()->timestamp) {
            $request->session()->forget($draftKey);

            return redirect()
                ->route('returns.create', ['returnRequestSlug' => __('return_request.slug')])
                ->withErrors(['draft' => __('return_request.draft_expired')]);
        }

        $data = (array) ($draft['data'] ?? []);
        $declaration = $this->declaration((string) ($data['order_number'] ?? ''));
        $submittedAt = now();
        $snapshot = [
            'version' => '2026-06-19',
            'submitted_at' => $submittedAt->toIso8601String(),
            'confirmation_channel' => 'email',
            'data' => $data,
            'declaration' => $declaration,
        ];
        $snapshotJson = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $submissionKey = hash('sha256', $token);

        $withdrawal = DB::transaction(function () use (
            $request,
            $draft,
            $data,
            $declaration,
            $submittedAt,
            $snapshot,
            $snapshotJson,
            $submissionKey
        ): ContractWithdrawal {
            $existing = ContractWithdrawal::query()
                ->where('submission_key', $submissionKey)
                ->first();

            if ($existing) {
                return $existing;
            }

            $order = $this->resolveOrder(
                orderNumber: (string) $data['order_number'],
                email: (string) $data['email'],
                userId: isset($draft['user_id']) ? (int) $draft['user_id'] : null,
            );

            return ContractWithdrawal::query()->create([
                'reference' => $this->newReference(),
                'submission_key' => $submissionKey,
                'user_id' => $request->user()?->id,
                'order_id' => $order?->id,
                'order_number' => $data['order_number'],
                'full_name' => $data['full_name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?: null,
                'address_line' => $data['address_line'],
                'postal_code' => $data['postal_code'],
                'city' => $data['city'],
                'country_code' => $data['country_code'],
                'contract_date' => $data['contract_date'] ?: null,
                'received_date' => $data['received_date'] ?: null,
                'items' => $data['items'],
                'note' => $data['note'] ?: null,
                'declaration' => $declaration,
                'request_snapshot' => $snapshot,
                'snapshot_hash' => hash('sha256', $snapshotJson),
                'status' => ContractWithdrawal::STATUS_RECEIVED,
                'locale' => (string) ($draft['locale'] ?? app()->getLocale()),
                'submitted_at' => $submittedAt,
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 512, ''),
            ]);
        });

        $request->session()->forget($draftKey);
        $this->notifications->send($withdrawal);
        $withdrawal->refresh();

        $redirect = redirect()
            ->route('returns.create', ['returnRequestSlug' => __('return_request.slug')])
            ->with('status', __('return_request.sent_status', [
                'reference' => $withdrawal->reference,
            ]))
            ->with('withdrawal_reference', $withdrawal->reference);

        if (! $withdrawal->consumer_notified_at) {
            $redirect->with('warning', __('return_request.confirmation_email_failed'));
        }

        return $redirect;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateSubmission(Request $request): array
    {
        $captchaSettings = $this->storeSettings->captcha();
        $captchaEnabled = (bool) ($captchaSettings['recaptcha_v3_enabled'] ?? false)
            && trim((string) ($captchaSettings['recaptcha_v3_site_key'] ?? '')) !== ''
            && trim((string) ($captchaSettings['recaptcha_v3_secret_key'] ?? '')) !== '';

        return $request->validate(
            [
                'full_name' => ['required', 'string', 'min:2', 'max:191'],
                'email' => ['required', 'email', 'max:191'],
                'phone' => ['nullable', 'string', 'max:80'],
                'address_line' => ['required', 'string', 'min:3', 'max:255'],
                'postal_code' => ['required', 'string', 'max:32'],
                'city' => ['required', 'string', 'max:120'],
                'country_code' => ['required', 'string', 'size:2'],
                'order_number' => ['required', 'string', 'max:80'],
                'contract_date' => ['nullable', 'date', 'before_or_equal:today'],
                'received_date' => ['nullable', 'date', 'before_or_equal:today', 'after_or_equal:contract_date'],
                'items' => ['required', 'string', 'min:2', 'max:5000'],
                'note' => ['nullable', 'string', 'max:5000'],
                'recaptcha_token' => [$captchaEnabled ? 'required' : 'nullable', 'string', 'max:4096'],
            ],
            [
                'required' => __('return_request.validation.required'),
                'email' => __('return_request.validation.email'),
                'min.string' => __('return_request.validation.min_string'),
                'max.string' => __('return_request.validation.max_string'),
                'size' => __('return_request.validation.size'),
                'date' => __('return_request.validation.date'),
                'before_or_equal' => __('return_request.validation.not_future'),
                'after_or_equal' => __('return_request.validation.received_after_contract'),
            ],
            [
                'full_name' => __('return_request.form.full_name'),
                'email' => __('return_request.form.email'),
                'phone' => __('return_request.form.phone'),
                'address_line' => __('return_request.form.address_line'),
                'postal_code' => __('return_request.form.postal_code'),
                'city' => __('return_request.form.city'),
                'country_code' => __('return_request.form.country_code'),
                'order_number' => __('return_request.form.order_number'),
                'contract_date' => __('return_request.form.contract_date'),
                'received_date' => __('return_request.form.received_date'),
                'items' => __('return_request.form.items'),
                'note' => __('return_request.form.note'),
                'recaptcha_token' => __('return_request.validation.security_check'),
            ],
        );
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<string, string>
     */
    private function normalize(array $validated): array
    {
        return [
            'full_name' => trim((string) $validated['full_name']),
            'email' => strtolower(trim((string) $validated['email'])),
            'phone' => trim((string) ($validated['phone'] ?? '')),
            'address_line' => trim((string) $validated['address_line']),
            'postal_code' => trim((string) $validated['postal_code']),
            'city' => trim((string) $validated['city']),
            'country_code' => strtoupper(trim((string) $validated['country_code'])),
            'order_number' => trim((string) $validated['order_number']),
            'contract_date' => trim((string) ($validated['contract_date'] ?? '')),
            'received_date' => trim((string) ($validated['received_date'] ?? '')),
            'items' => trim((string) $validated['items']),
            'note' => trim((string) ($validated['note'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $validated
     */
    private function verifyCaptcha(Request $request, array $validated): void
    {
        $settings = $this->storeSettings->captcha();
        $enabled = (bool) ($settings['recaptcha_v3_enabled'] ?? false)
            && trim((string) ($settings['recaptcha_v3_site_key'] ?? '')) !== ''
            && trim((string) ($settings['recaptcha_v3_secret_key'] ?? '')) !== '';

        if (! $enabled) {
            return;
        }

        $minScore = max(0.0, min(1.0, (float) ($settings['recaptcha_v3_min_score'] ?? 0.5)));

        try {
            $response = Http::asForm()
                ->timeout(8)
                ->post('https://www.google.com/recaptcha/api/siteverify', [
                    'secret' => (string) $settings['recaptcha_v3_secret_key'],
                    'response' => (string) ($validated['recaptcha_token'] ?? ''),
                    'remoteip' => (string) $request->ip(),
                ]);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'recaptcha_token' => __('return_request.captcha_failed'),
            ]);
        }

        $json = $response->ok() ? (array) $response->json() : [];
        $action = (string) ($json['action'] ?? '');

        if (
            ! (bool) ($json['success'] ?? false)
            || (float) ($json['score'] ?? 0.0) < $minScore
            || ($action !== '' && $action !== 'contract_withdrawal_form')
        ) {
            throw ValidationException::withMessages([
                'recaptcha_token' => __('return_request.captcha_failed'),
            ]);
        }
    }

    private function declaration(string $orderNumber): string
    {
        return __('return_request.declaration', ['order' => $orderNumber]);
    }

    private function newReference(): string
    {
        do {
            $reference = 'JR-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        } while (ContractWithdrawal::query()->where('reference', $reference)->exists());

        return $reference;
    }

    private function resolveOrder(string $orderNumber, string $email, ?int $userId): ?Order
    {
        return Order::query()
            ->where('order_number', $orderNumber)
            ->where(function ($query) use ($email, $userId): void {
                $query->where('customer_email', $email);
                if ($userId) {
                    $query->orWhere('user_id', $userId);
                }
            })
            ->first();
    }

    /**
     * @return array<string, string>
     */
    private function prefill(Request $request): array
    {
        $user = $request->user();
        if (! $user) {
            return [];
        }

        $user->loadMissing(['profile', 'addresses']);
        $address = $user->addresses->firstWhere('type', UserAddress::TYPE_BILLING)
            ?? $user->addresses->first();

        return Arr::whereNotNull([
            'full_name' => trim(implode(' ', array_filter([
                $user->profile?->first_name,
                $user->profile?->last_name,
            ]))) ?: $user->name,
            'email' => $user->email,
            'phone' => $user->profile?->phone ?: $address?->phone,
            'address_line' => trim(implode(', ', array_filter([
                $address?->address_line_1,
                $address?->address_line_2,
            ]))),
            'postal_code' => $address?->postal_code,
            'city' => $address?->city,
            'country_code' => $address?->country_code ?: 'HR',
        ]);
    }
}
