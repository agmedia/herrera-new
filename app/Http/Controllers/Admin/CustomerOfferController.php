<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Catalog\Product\Product;
use App\Models\Sales\Offer\CustomerOffer;
use App\Models\User;
use App\Services\Admin\OrderManagerAccess;
use App\Services\Pricing\B2BAccessService;
use App\Services\Sales\CustomerOfferAccess;
use App\Services\Sales\CustomerOfferPdf;
use App\Services\Sales\CustomerOfferService;
use App\Support\Media\LegacyCatalogImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CustomerOfferController extends Controller
{
    public function __construct(private readonly CustomerOfferAccess $access) {}

    public function index(Request $request): View
    {
        $this->access->assertStaff($request->user());
        $offers = $this->access->scope(CustomerOffer::query(), $request->user())
            ->when($request->filled('search'), fn ($query) => $query->where(fn ($query) => $query->where('number', 'like', '%'.Str::limit($request->string('search')->toString(), 100, '').'%')->orWhere('title', 'like', '%'.Str::limit($request->string('search')->toString(), 100, '').'%')))
            ->latest('id')->paginate(20)->withQueryString();

        return view('admin.customer-offers.index', compact('offers'));
    }

    public function create(Request $request): View
    {
        $this->access->assertStaff($request->user(), 'manage');

        return view('admin.customer-offers.form', ['offer' => new CustomerOffer(['locale' => app()->getLocale(), 'valid_until' => now()->addDays(14), 'title' => __('customer_offers.default_title')])]);
    }

    public function store(Request $request, CustomerOfferService $service): RedirectResponse
    {
        $offer = $service->saveDraft($request->all(), $request->user());

        return redirect()->route('admin.customer-offers.edit', $offer)->with('status', __('customer_offers.saved'));
    }

    public function edit(Request $request, CustomerOffer $offer): View
    {
        $this->access->assertOffer($offer, $request->user());
        $offer->load('items', 'deliveries');

        return view('admin.customer-offers.form', compact('offer'));
    }

    public function update(Request $request, CustomerOffer $offer, CustomerOfferService $service): RedirectResponse
    {
        $service->saveDraft($request->all(), $request->user(), $offer);

        return redirect()->route('admin.customer-offers.edit', $offer)->with('status', __('customer_offers.saved'));
    }

    public function preview(Request $request, CustomerOffer $offer, CustomerOfferPdf $pdf): Response
    {
        $this->access->assertOffer($offer, $request->user());

        return response($pdf->html($offer, true))->header('Cache-Control', 'private, no-store');
    }

    public function pdf(Request $request, CustomerOffer $offer, CustomerOfferPdf $pdf): Response
    {
        $this->access->assertOffer($offer, $request->user());

        return $this->pdfResponse($offer, $pdf, $request->boolean('download'));
    }

    public function send(Request $request, CustomerOffer $offer, CustomerOfferService $service, CustomerOfferPdf $pdf): RedirectResponse
    {
        $request->validate(['confirm_send' => ['accepted']]);
        $delivery = $service->send($offer, $request->user(), $pdf);

        return redirect()->route('admin.customer-offers.edit', $offer)->with('status', __('customer_offers.delivery.'.$delivery->status));
    }

    public function customers(Request $request): JsonResponse
    {
        $this->access->assertStaff($request->user(), 'manage');
        $search = trim($request->string('search')->toString());
        if (mb_strlen($search) < 2) {
            return response()->json([]);
        }
        $like = '%'.Str::limit($search, 100, '').'%';
        $customers = app(OrderManagerAccess::class)->scopeCustomers(User::query(), $request->user())
            ->where('account_type', 'customer')->whereHas('b2bAccount', fn ($query) => $query->where('status', 'approved'))
            ->where(fn ($query) => $query->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhereHas('b2bAccount', fn ($query) => $query->where('company_name', 'like', $like)->orWhere('oib', 'like', $like)))
            ->with(['b2bAccount.customerGroup', 'profile', 'addresses'])->limit(20)->get()
            ->filter(fn ($user) => app(B2BAccessService::class)->canViewPrices($user))
            ->map(fn ($user) => ['id' => $user->id, 'name' => $user->customerDisplayName(), 'email' => $user->email])->values();

        return response()->json($customers)->header('Cache-Control', 'private, no-store');
    }

    public function products(Request $request): JsonResponse
    {
        $this->access->assertStaff($request->user(), 'manage');
        $request->validate(['customer_user_id' => ['required', 'integer', 'exists:users,id'], 'search' => ['nullable', 'string', 'max:100']]);
        $buyer = User::query()->findOrFail($request->integer('customer_user_id'));
        $this->access->assertCustomer($buyer, $request->user());
        app(B2BAccessService::class)->ensureCanPurchase($buyer);
        $search = trim($request->string('search')->toString());
        if (mb_strlen($search) < 2) {
            return response()->json([]);
        }
        $like = '%'.$search.'%';
        $products = Product::query()->where('is_active', true)->where(fn ($query) => $query->where('sku', 'like', $like)->orWhere('code', 'like', $like)
            ->orWhereHas('translations', fn ($query) => $query->where('name', 'like', $like))->orWhereHas('optionValues', fn ($query) => $query->where('sku', 'like', $like)))
            ->with(['translations', 'media', 'optionValues.optionValue.translations', 'optionValues.parentOptionValue.translations'])->orderBy('code')->limit(15)->get();
        $rows = [];
        foreach ($products as $product) {
            $translation = $product->translations->firstWhere('locale', app()->getLocale()) ?? $product->translations->first();
            $base = ['product_id' => $product->id, 'name' => $translation?->name ?: $product->code, 'description' => Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode((string) ($translation?->excerpt ?: $translation?->description))))), 500, '…'),
                'image_url' => LegacyCatalogImage::first($product), 'quantity' => max(1, (int) $product->minimum_order_quantity)];
            $options = $product->optionValues->filter(fn ($option) => $option->isVisibleOnStorefront());
            if ($options->isNotEmpty()) {
                foreach ($options->take(20) as $option) {
                    $rows[] = $base + ['product_option_value_id' => $option->id, 'sku' => $option->sku ?: $product->sku,
                        'option_label' => collect([$option->parentOptionValue, $option->optionValue])->filter()->map(fn ($value) => $value->translations->firstWhere('locale', app()->getLocale())?->name ?? $value->translations->first()?->name)->filter()->implode(' / ')];
                }
            } else {
                $rows[] = $base + ['product_option_value_id' => null, 'sku' => $product->sku ?: $product->code, 'option_label' => null];
            }
        }

        return response()->json(array_slice($rows, 0, 50))->header('Cache-Control', 'private, no-store');
    }

    public function buyerIndex(Request $request): View
    {
        $offers = CustomerOffer::query()->where('customer_user_id', $request->user()->id)->where('status', 'sent')->latest('sent_at')->paginate(20);

        return view('front.desktop.account.customer-offers', compact('offers'));
    }

    public function buyerPdf(Request $request, CustomerOffer $offer, CustomerOfferPdf $pdf): Response
    {
        $this->access->assertBuyer($offer, $request->user());

        return $this->pdfResponse($offer, $pdf, $request->boolean('download'));
    }

    private function pdfResponse(CustomerOffer $offer, CustomerOfferPdf $pdf, bool $download): Response
    {
        return response($pdf->render($offer))->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', ($download ? 'attachment' : 'inline').'; filename="'.$offer->number.'.pdf"')
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
