<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Services\Front\NewsletterSignupService;
use App\Services\Front\StoreNotificationService;
use App\Services\Front\StoreSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class NewsletterController extends Controller
{
    private const RECAPTCHA_ACTION = 'newsletter_footer';

    public function __construct(
        private readonly NewsletterSignupService $newsletterSignups,
        private readonly StoreNotificationService $notifications,
        private readonly StoreSettingsService $storeSettings,
    ) {
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $captchaSettings = $this->storeSettings->captcha();
        $captchaEnabled = $this->recaptchaIsEnabled($captchaSettings);

        $validator = Validator::make(
            $request->all(),
            [
                'newsletter_email' => ['required', 'email', 'max:191'],
                'newsletter_accept_terms' => ['accepted'],
                'recaptcha_token' => [$captchaEnabled ? 'required' : 'nullable', 'string', 'max:4096'],
            ],
            [
                'newsletter_email.required' => __('ui.front.desktop.newsletter.validation.email_required'),
                'newsletter_email.email' => __('ui.front.desktop.newsletter.validation.email_invalid'),
                'newsletter_accept_terms.accepted' => __('ui.front.desktop.newsletter.validation.accept_terms'),
                'recaptcha_token.required' => __('ui.front.desktop.newsletter.validation.captcha_failed'),
            ],
            [
                'recaptcha_token' => __('ui.front.desktop.newsletter.validation.security_check'),
            ]
        );

        if ($validator->fails()) {
            if ($request->expectsJson() || $request->ajax()) {
                return new JsonResponse([
                    'message' => __('ui.front.desktop.newsletter.status.failed'),
                    'errors' => $validator->errors(),
                ], 422);
            }

            return redirect()
                ->back()
                ->withErrors($validator, 'newsletter')
                ->withInput();
        }

        if ($captchaEnabled) {
            try {
                $this->assertRecaptchaIsValid(
                    token: (string) ($validator->validated()['recaptcha_token'] ?? ''),
                    secret: (string) $captchaSettings['recaptcha_v3_secret_key'],
                    minScore: (float) ($captchaSettings['recaptcha_v3_min_score'] ?? 0.5),
                    expectedAction: self::RECAPTCHA_ACTION,
                    ip: (string) $request->ip()
                );
            } catch (ValidationException $exception) {
                if ($request->expectsJson() || $request->ajax()) {
                    return new JsonResponse([
                        'message' => __('ui.front.desktop.newsletter.validation.captcha_failed'),
                        'errors' => $exception->errors(),
                    ], 422);
                }

                return redirect()
                    ->back()
                    ->withErrors($exception->errors(), 'newsletter')
                    ->withInput();
            }
        }

        try {
            $result = $this->newsletterSignups->subscribe(
                email: (string) $validator->validated()['newsletter_email'],
                consentAccepted: true,
                user: $request->user(),
                source: \App\Models\User\NewsletterSignup::SOURCE_FOOTER,
                locale: (string) app()->getLocale(),
                ipAddress: (string) $request->ip(),
                userAgent: (string) $request->userAgent(),
                referrer: (string) $request->headers->get('referer', '')
            );
        } catch (Throwable) {
            if ($request->expectsJson() || $request->ajax()) {
                return new JsonResponse([
                    'message' => __('ui.front.desktop.newsletter.status.failed'),
                    'errors' => [
                        'newsletter_email' => [__('ui.front.desktop.newsletter.status.failed')],
                    ],
                ], 422);
            }

            return redirect()
                ->back()
                ->withErrors([
                    'newsletter_email' => __('ui.front.desktop.newsletter.status.failed'),
                ], 'newsletter')
                ->withInput();
        }

        $message = $result['synced']
            ? __('ui.front.desktop.newsletter.status.subscribed')
            : __('ui.front.desktop.newsletter.status.saved_with_sync_issue');

        if ($result['synced']) {
            $this->notifications->sendNewsletterCoupon(
                (string) $validator->validated()['newsletter_email'],
                (string) app()->getLocale(),
                'BALI10'
            );
        }

        if ($request->expectsJson() || $request->ajax()) {
            return new JsonResponse([
                'message' => $message,
                'type' => $result['synced'] ? 'success' : 'warning',
            ]);
        }

        return redirect()
            ->back()
            ->with('status', $message);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function recaptchaIsEnabled(array $settings): bool
    {
        return (bool) ($settings['recaptcha_v3_enabled'] ?? false)
            && trim((string) ($settings['recaptcha_v3_site_key'] ?? '')) !== ''
            && trim((string) ($settings['recaptcha_v3_secret_key'] ?? '')) !== '';
    }

    private function assertRecaptchaIsValid(
        string $token,
        string $secret,
        float $minScore,
        string $expectedAction,
        string $ip
    ): void {
        $minScore = max(0.0, min(1.0, $minScore));

        try {
            $response = Http::asForm()
                ->timeout(8)
                ->post('https://www.google.com/recaptcha/api/siteverify', [
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $ip,
                ]);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'recaptcha_token' => __('ui.front.desktop.newsletter.validation.captcha_failed'),
            ]);
        }

        if (! $response->ok()) {
            throw ValidationException::withMessages([
                'recaptcha_token' => __('ui.front.desktop.newsletter.validation.captcha_failed'),
            ]);
        }

        $json = $response->json();
        $success = (bool) ($json['success'] ?? false);
        $score = (float) ($json['score'] ?? 0.0);
        $action = (string) ($json['action'] ?? '');

        if (! $success || $score < $minScore || ($action !== '' && $action !== $expectedAction)) {
            throw ValidationException::withMessages([
                'recaptcha_token' => __('ui.front.desktop.newsletter.validation.captcha_failed'),
            ]);
        }
    }
}
