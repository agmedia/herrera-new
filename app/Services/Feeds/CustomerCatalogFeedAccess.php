<?php

namespace App\Services\Feeds;

use App\Models\User;
use App\Models\User\CustomerCatalogFeed;
use App\Services\Admin\OrderManagerAccess;
use App\Services\Pricing\B2BAccessService;
use App\Services\Pricing\PriceCatalogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CustomerCatalogFeedAccess
{
    public function authorize(User $customer, User $actor, string $operation = 'view'): void
    {
        app(PriceCatalogService::class)->authorize($actor, $operation);
        abort_unless($customer->account_type === 'customer' && ! $customer->can('admin.access'), 403);
        app(OrderManagerAccess::class)->assertCustomer($customer, $actor);
    }

    /** @param array<int, int> $productIds */
    public function saveAssortment(User $customer, User $actor, array $productIds, bool $includePurchased): CustomerCatalogFeed
    {
        $this->authorize($customer, $actor, 'update');
        Validator::make(['products' => $productIds], [
            'products' => ['array', 'max:5000'], 'products.*' => ['required', 'integer', 'distinct', 'exists:products,id'],
        ])->validate();

        $feed = DB::transaction(function () use ($customer, $actor, $productIds, $includePurchased): CustomerCatalogFeed {
            User::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $feed = CustomerCatalogFeed::query()->firstOrCreate(['user_id' => $customer->id], ['created_by' => $actor->id]);
            $feed->update(['include_purchased_products' => $includePurchased, 'updated_by' => $actor->id]);
            $feed->products()->sync($productIds);

            return $feed;
        });
        $this->audit($feed, $actor, 'assortment_updated', ['products' => count($productIds), 'include_purchased_products' => $includePurchased]);

        return $feed;
    }

    /** The plaintext is returned only once and is never persisted or audited. */
    public function issueToken(User $customer, User $actor): string
    {
        $this->authorize($customer, $actor, 'update');
        if (! app(B2BAccessService::class)->approvedAccount($customer)) {
            throw ValidationException::withMessages(['feed' => 'XML pristup zahtijeva odobren i važeći B2B račun s aktivnom cjenovnom grupom.']);
        }
        $token = bin2hex(random_bytes(32));
        $feed = DB::transaction(function () use ($customer, $actor, $token): CustomerCatalogFeed {
            $feed = CustomerCatalogFeed::query()->where('user_id', $customer->id)->lockForUpdate()->first();
            if (! $feed || (! $feed->include_purchased_products && ! $feed->products()->exists())) {
                throw ValidationException::withMessages(['feed' => 'Najprije odobrite proizvode za XML izvoz.']);
            }
            $feed->update(['token_hash' => hash('sha256', $token), 'is_enabled' => true, 'token_issued_at' => now(), 'revoked_at' => null, 'updated_by' => $actor->id]);

            return $feed;
        });
        $this->audit($feed, $actor, 'token_rotated');

        return $token;
    }

    public function revoke(User $customer, User $actor): void
    {
        $this->authorize($customer, $actor, 'update');
        $feed = CustomerCatalogFeed::query()->where('user_id', $customer->id)->first();
        if ($feed) {
            $feed->update(['is_enabled' => false, 'token_hash' => null, 'revoked_at' => now(), 'updated_by' => $actor->id]);
            $this->audit($feed, $actor, 'access_revoked');
        }
    }

    public function resolveToken(string $token): ?CustomerCatalogFeed
    {
        if (strlen($token) !== 64 || ! ctype_xdigit($token)) {
            return null;
        }
        $feed = CustomerCatalogFeed::query()->with('user.b2bAccount.customerGroup')
            ->where('is_enabled', true)->where('token_hash', hash('sha256', $token))->first();
        if (! $feed || $feed->user?->account_type !== 'customer' || $feed->user->can('admin.access')
            || ! app(B2BAccessService::class)->approvedAccount($feed->user)) {
            return null;
        }

        return $feed;
    }

    private function audit(CustomerCatalogFeed $feed, User $actor, string $event, array $properties = []): void
    {
        activity('customer_catalog_feeds')->performedOn($feed)->causedBy($actor)->event($event)
            ->withProperties(['feed_id' => $feed->id, 'customer_id' => $feed->user_id] + $properties)->log('Customer XML export permissions updated.');
    }
}
