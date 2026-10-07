<?php

namespace App\Services\Integrations\Eprel;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Services\Integrations\Msan\EprelClient;
use App\Services\Integrations\Msan\EprelDeclarationWriter;
use App\Services\Integrations\Msan\EprelMatchConflictException;
use App\Services\Integrations\Msan\EprelProductIdentity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

class EprelCatalogProductMatcher
{
    public function __construct(private readonly EprelClient $client, private readonly EprelDeclarationWriter $writer) {}

    /** Local classification is a search scope, never a claim that a label is mandatory. */
    public function criteria(Product $product): array
    {
        $product->loadMissing(['manufacturer.translations', 'categories.translations', 'energyDeclarations', 'packages']);
        $groups = $this->strings([$product->eprel_lookup_product_group, $product->eprel_product_group]);
        $groups = array_values(array_filter($groups, fn ($g) => isset(EprelClient::productGroupOptions()[strtolower($g)])));
        $brands = $this->strings($product->manufacturer?->translations->pluck('name')->all() ?? []);
        if ($groups === []) {
            $root = Category::query()->where('scope', 'catalog')->where(function ($q): void {
                $q->where('code', 'herrera-oc-category-787')->orWhereHas('translations', fn ($t) => $t->where('locale', 'hr')->where('name', 'Rasvjeta'));
            })->first();
            if ($root && $product->categories->contains(fn ($c) => $c->scope === 'catalog' && $c->_lft >= $root->_lft && $c->_rgt <= $root->_rgt)) {
                $groups = ['lightsources'];
            } elseif ($this->hasCategory($product, 'herrera-oc-category-1025', 'Električni bojleri')) {
                $groups = ['waterheaters'];
            } elseif (($this->hasCategory($product, 'herrera-oc-category-824', 'Extra popusti')
                || $this->hasCategory($product, 'herrera-oc-category-1236', 'Stropni ventilatori'))
                && $this->hasImportedLightingEvidence($product)) {
                $groups = ['lightsources'];
            } elseif ($this->hasCategory($product, 'herrera-oc-category-1083', 'Mali kućanski aparati')
                && in_array('esper', array_map(mb_strtolower(...), $brands), true)
                && $this->productNames($product)->contains(fn ($name) => preg_match('/^mini pećnica\b/u', $name) === 1)) {
                $groups = ['ovens'];
            }
        }
        $models = $this->strings([data_get($product->payload, 'opencart.model'), data_get($product->payload, 'model'), $product->sku, $product->code]);
        $models = array_values(array_filter($models, fn ($m) => EprelClient::isValidModelIdentifier($m) && ! preg_match('/^herrera-oc-product-|\-oc-\d+$/', $m)));
        $gtins = $this->strings([$product->barcode, data_get($product->payload, 'opencart.ean'), ...$product->packages->pluck('barcode')->all()]);
        $gtins = array_values(array_filter($gtins, EprelClient::isValidGtinIdentifier(...)));
        $registrations = $this->strings([$product->eprel_registration_number, ...$product->energyDeclarations->pluck('eprel_registration_number')->all()]);
        $registrations = array_values(array_filter($registrations, EprelClient::isValidRegistrationNumber(...)));

        return ['groups' => array_map('strtolower', $groups), 'models' => $models, 'gtins' => $gtins, 'brands' => $brands, 'registrations' => $registrations];
    }

    private function hasCategory(Product $product, string $code, string $name): bool
    {
        return $product->categories->contains(fn ($category) => $category->scope === 'catalog'
            && ($category->code === $code || $category->translations->contains(fn ($translation) => $translation->locale === 'hr' && $translation->name === $name)));
    }

    private function productNames(Product $product): \Illuminate\Support\Collection
    {
        $product->loadMissing(['translations' => fn ($query) => $query->select(['id', 'product_id', 'locale', 'name'])->where('locale', 'hr')]);

        return $product->translations->where('locale', 'hr')->pluck('name')->map(fn ($name) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $name) ?? '')));
    }

    /** Izvorni podatak i naziv samo određuju pretragu; nisu službena deklaracija. */
    private function hasImportedLightingEvidence(Product $product): bool
    {
        // Tehničke retke učitavamo samo za ciljane kategorije, ne za sve alate i odjeću.
        $product->loadMissing(['technicalSpecificationRows' => fn ($query) => $query
            ->select(['id', 'product_id', 'source', 'item_name', 'values'])
            ->where('source', 'herrera-opencart')
            ->whereIn('item_name', ['Razina energetske učinkovitosti', 'Klasa energetske učinkovitosti EEi'])]);
        $classes = [];
        foreach ($product->technicalSpecificationRows as $row) {
            if ($row->source !== 'herrera-opencart' || ! in_array($row->item_name, ['Razina energetske učinkovitosti', 'Klasa energetske učinkovitosti EEi'], true)) {
                continue;
            }
            foreach ((array) $row->values as $value) {
                if (is_scalar($value)) {
                    $class = mb_strtoupper(preg_replace('/\s+/u', '', (string) $value) ?? '');
                    if (preg_match('/^[A-G]$/D', $class) === 1) {
                        $classes[$class] = true;
                    }
                }
            }
        }
        if (count($classes) !== 1) {
            return false;
        }

        return $this->productNames($product)->contains(fn ($name) => preg_match('/^(?:žarulja\s+led\b|(?:led\s+)?(?:(?:stropna|zidna|linearna|panelna|podna|viseća|nadgradna|ugradna)\s+){0,2}svjetiljka\b|ventilator\s+stropni\s+(?:s|sa)\s+(?:osvjetljenjem|rasvjetom)\b)/u', $name) === 1);
    }

    public function match(Product $product, array $expectedCriteria, string $expectedIdentity, ?callable $canWrite = null): array
    {
        if (! EprelProductIdentity::matches($product, $expectedIdentity) || ! $this->sameCriteria($this->criteria($product), $expectedCriteria)) {
            throw new EprelMatchConflictException('Šifra, EAN, marka ili kategorija promijenjeni su nakon pokretanja paketa.');
        }
        if (count($expectedCriteria['gtins']) > 2 || count($expectedCriteria['models']) > 3 || count($expectedCriteria['registrations']) > 1 || count($expectedCriteria['groups']) > 1 || count($expectedCriteria['brands']) > 2) {
            throw new EprelMatchConflictException('Previše različitih identifikatora; potreban je pregled artikla.');
        }
        $requests = 0;
        $client = $this->client->withRequestGuard(function () use (&$requests): void {
            if (++$requests > 10) {
                throw new EprelMatchConflictException('Artikl zahtijeva previše provjera za skupni dohvat; provjerite ga pojedinačno.');
            }
            $lock = Cache::lock('eprel-catalog:request-quota-lock', 5);
            if (! $lock->get()) {
                throw new EprelBatchThrottleException(5);
            }
            try {
                if (RateLimiter::tooManyAttempts('eprel-catalog:requests', 20)) {
                    throw new EprelBatchThrottleException(max(5, RateLimiter::availableIn('eprel-catalog:requests')));
                }
                RateLimiter::hit('eprel-catalog:requests', 60);
            } finally {
                $lock->release();
            }
        });
        $matches = [];
        foreach ($expectedCriteria['registrations'] as $registration) {
            if ($result = $client->findByRegistrationNumber($registration)) {
                $matches[$result['eprel_registration_number']] = $result;
            }
        }
        foreach ($expectedCriteria['gtins'] as $gtin) {
            if ($result = $client->findByGtinIdentifier($gtin)) {
                // A contradictory model is reviewable, not a licence to replace the product identity.
                if ($result['model_identifier'] && $expectedCriteria['models'] !== [] && ! in_array($result['model_identifier'], $expectedCriteria['models'], true)) {
                    throw new EprelMatchConflictException('EAN je pronađen, ali službeni model razlikuje se od šifre artikla.');
                }
                $matches[$result['eprel_registration_number']] = $result;
            }
        }
        if (count($matches) > 1) {
            throw new EprelMatchConflictException('Identifikatori upućuju na različite službene EPREL zapise.');
        }
        if ($matches === []) {
            if ($expectedCriteria['groups'] === []) {
                return ['status' => 'needs_group', 'message' => 'Nema potvrđene EPREL grupe za pretragu modela.'];
            }
            if ($expectedCriteria['brands'] === []) {
                return ['status' => 'needs_brand', 'message' => 'Za točnu pretragu modela nedostaje marka.'];
            }
            foreach ($expectedCriteria['models'] as $model) {
                foreach ($expectedCriteria['brands'] as $brand) {
                    if ($result = $client->findByModelIdentifier($expectedCriteria['groups'][0], $model, [$brand])) {
                        $matches[$result['eprel_registration_number']] = $result;
                    }
                }
            }
        }
        if (count($matches) > 1) {
            throw new EprelMatchConflictException('Šifre upućuju na više EPREL modela; ništa nije spremljeno.');
        }
        if ($matches === []) {
            return ['status' => 'not_found', 'message' => 'Nije pronađeno potpuno točno podudaranje.'];
        }
        $data = reset($matches);
        $this->writer->store((int) $product->id, $data, EprelDeclarationWriter::ORIGIN_CATALOG_BATCH, ['product_identity' => $expectedIdentity], function (Product $locked) use ($expectedCriteria, $canWrite): bool {
            return $locked->is_active && $this->sameCriteria($this->criteria($locked), $expectedCriteria) && (! $canWrite || $canWrite());
        });

        return ['status' => 'matched', 'message' => 'Točna službena deklaracija je spremljena.', 'registration' => $data['eprel_registration_number']];
    }

    private function strings(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($v) => is_scalar($v) ? trim((string) $v) : '', $values), fn ($v) => $v !== '')));
    }

    public function sameCriteria(array $left, array $right): bool
    {
        // MySQL JSON normalizes object-key order; identifier values stay strict.
        ksort($left);
        ksort($right);

        return $left === $right;
    }
}
