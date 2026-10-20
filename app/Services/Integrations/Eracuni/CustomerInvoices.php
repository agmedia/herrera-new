<?php

namespace App\Services\Integrations\Eracuni;

use App\Models\User;
use App\Services\Integrations\Stock\StockSyncSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class CustomerInvoices
{
    public function forCustomer(User $user, array $filters): Collection
    {
        $user->loadMissing('b2bAccount', 'profile', 'addresses');
        $taxNumber = $this->taxNumber($user->b2bAccount?->oib ?: $user->profile?->oib ?: $user->addresses->firstWhere('type', 'billing')?->oib);
        if ($taxNumber === '') {
            throw new RuntimeException('Za povezivanje faktura potrebno je upisati OIB kupca.');
        }
        $settings = app(StockSyncSettingsService::class);
        if (! $settings->configured('eracuni')) {
            throw new RuntimeException('Pristupni podaci za e-Računi nisu konfigurirani.');
        }
        $connection = $settings->connection('eracuni');
        $from = CarbonImmutable::parse($filters['dateFrom']);
        $to = CarbonImmutable::parse($filters['dateTo']);
        $key = 'customer-invoices:'.hash('sha256', json_encode([$connection, $from->toDateString(), $to->toDateString()]));
        $rows = Cache::remember($key, 600, function () use ($connection, $from, $to) {
            $budget = 40;

            return $this->fetch($connection, $from, $to, $budget);
        });

        return collect($rows)->filter(fn ($row) => $this->taxNumber($row['buyerTaxNumber'] ?? '') === $taxNumber)
            ->where('documentCurrency', $filters['currency'])
            ->filter(fn ($row) => ($row['status'] ?? '') === 'IssuedInvoice')
            ->unique('documentID')->sortByDesc('date')->values();
    }

    private function taxNumber(?string $value): string
    {
        return preg_replace('/^HR/', '', strtoupper(preg_replace('/\s+/', '', trim($value ?? ''))));
    }

    private function fetch(array $connection, CarbonImmutable $from, CarbonImmutable $to, int &$budget): array
    {
        if (--$budget < 0) {
            throw new RuntimeException('Previše faktura za odabrano razdoblje. Odaberite kraće razdoblje.');
        }
        $url = preg_replace('~/(?:WarehouseGetArticleStockQuantity|ProductList)/?$~', '', rtrim($connection['url'], '/')).'/SalesInvoiceList';
        try {
            $data = Cache::lock('herrera-eracuni-api', 45)->block(10, fn () => Http::connectTimeout(10)->timeout(30)
                ->withOptions(['allow_redirects' => false])->acceptJson()->asForm()
                ->withBasicAuth($connection['username'], $connection['token'].'_'.$connection['password'])
                ->post($url, ['dateFrom' => $from->toDateString(), 'dateTo' => $to->toDateString()])
                ->throw()->json());
        } catch (Throwable) {
            throw new RuntimeException('Dohvat faktura iz e-Računi nije uspio. Pokušajte ponovno.');
        }
        $response = $data['response'] ?? $data;
        $rows = $response['result'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || ! empty($response['error']) || ! empty($data['error']) || ! empty($response['errors']) || ($response['success'] ?? true) === false || ($response['complete'] ?? true) === false || ($response['hasMore'] ?? false) === true) {
            throw new RuntimeException('e-Računi nije vratio valjan popis faktura.');
        }
        foreach ($rows as $row) {
            if (! is_array($row) || empty($row['documentID']) || ! isset($row['documentAmount'], $row['documentCurrency'], $row['date']) || ! is_numeric($row['documentAmount'])) {
                throw new RuntimeException('e-Računi je vratio nepotpune podatke faktura.');
            }
        }
        if (count($rows) >= 500) {
            if ($from->isSameDay($to)) {
                throw new RuntimeException('Dosegnuto je ograničenje od 500 faktura u jednom danu. Statistika nije potpuna i nije prikazana.');
            }
            $middle = $from->addDays(intdiv((int) $from->diffInDays($to), 2));

            return array_merge($this->fetch($connection, $from, $middle, $budget), $this->fetch($connection, $middle->addDay(), $to, $budget));
        }

        return $rows;
    }
}
