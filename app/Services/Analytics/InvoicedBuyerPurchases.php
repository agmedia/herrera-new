<?php

namespace App\Services\Analytics;

use App\Models\User\B2BAccount;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class InvoicedBuyerPurchases
{
    public function latestSnapshot(): ?object
    {
        return DB::table('sales_opportunity_snapshots')->where('status', 'completed')->latest('id')->first();
    }

    public function matching(B2BAccount $account, object $snapshot, ?string $staffTaxFallback = null, bool $allowBuyerCode = false): ?Builder
    {
        $identity = app(SalesOpportunitySync::class)->buyerIdentity([
            'buyerTaxNumber' => $account->oib ?: $account->vat_id ?: $staffTaxFallback,
            'buyerCountry' => $account->country_code ?: 'HR',
            'buyerDocumentID' => $account->erp_customer_id,
            'buyerCode' => $allowBuyerCode ? data_get($account->payload, 'eracuni.buyerCode') : null,
        ]);
        if (! $identity['known_buyer']) {
            return null;
        }
        $aliases = DB::table('sales_opportunity_buyer_aliases')->where('snapshot_id', $snapshot->id)
            ->whereIn('alias_key', array_filter([$identity['tax_key'], $identity['document_key']]))->pluck('buyer_key', 'alias_key');
        $canonicalKeys = [];
        if ($identity['tax_key']) {
            $canonicalKeys = array_filter([$identity['tax_key'], $aliases[$identity['tax_key']] ?? null]);
            $documentRoot = $identity['document_key'] ? ($aliases[$identity['document_key']] ?? null) : null;
            if ($documentRoot && in_array($documentRoot, [...$canonicalKeys, $identity['document_key']], true)) {
                $canonicalKeys[] = $documentRoot;
            }
        } elseif ($identity['document_key'] && isset($aliases[$identity['document_key']])) {
            $canonicalKeys[] = $aliases[$identity['document_key']];
        }
        return DB::table('sales_opportunity_purchases')->where('snapshot_id', $snapshot->id)
            ->where('known_buyer', true)->where(function ($query) use ($identity, $canonicalKeys): void {
                $query->whereIn('buyer_key', $canonicalKeys);
                if ($identity['tax_key']) {
                    $query->orWhere('tax_key', $identity['tax_key']);
                } elseif (! $identity['document_key'] && $identity['code_key']) {
                    // Codes can be reused for cash buyers, so never join them
                    // to another customer's stronger tax/ERP-ID identity.
                    $query->orWhere(fn ($code) => $code->where('buyer_key', $identity['code_key'])->whereNull('tax_key')->whereNull('document_key'));
                }
            });
    }
}
