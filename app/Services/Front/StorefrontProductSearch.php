<?php

namespace App\Services\Front;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\Cache;

class StorefrontProductSearch
{
    public const NAME_FULLTEXT_INDEX = 'product_translations_name_search_fulltext';

    public const NAME_PREFIX_INDEX = 'product_translations_locale_name_search_idx';

    public const VARIANT_SKU_INDEX = 'catalog_product_option_sku_search_idx';

    private ?bool $nameFulltextAvailable = null;

    public function __construct(private readonly StorefrontSearchPolicy $policy) {}

    public function normalize(mixed $search): string
    {
        return $this->policy->normalize($search);
    }

    public function literalLike(string $search): string
    {
        return $this->policy->literalLike($search);
    }

    /** Apply the same bounded word forms to a translated category name. */
    public function applyNameSearch(Builder $query, string $column, string $locale, string $fallbackLocale, string $search): void
    {
        $search = $this->normalize($search);
        if ($search === '') {
            return;
        }

        $terms = preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $this->applyNameTerms($query->getQuery(), $column, $terms, $this->nameTermForms($terms, [$locale, $fallbackLocale]));
    }

    public function apply(Builder $query, string $locale, string $fallbackLocale, string $search): void
    {
        $search = $this->normalize($search);
        if ($search === '') {
            return;
        }

        $database = $query->getConnection();
        $locales = array_values(array_unique([$locale, $fallbackLocale]));
        $terms = preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $nameTermForms = $this->nameTermForms($terms, $locales);
        $normalized = mb_strtolower($search);
        $literal = $this->literalLike($normalized);

        $names = $database->table('product_translations as search_names')
            ->selectRaw('search_names.product_id AS product_id')
            ->selectRaw(
                "CASE WHEN LOWER(search_names.name) = ? THEN 1
                    WHEN LOWER(search_names.name) LIKE ? ESCAPE '!' THEN 2
                    WHEN LOWER(search_names.name) LIKE ? ESCAPE '!' THEN 3
                    WHEN LOWER(search_names.name) LIKE ? ESCAPE '!' THEN 4
                    ELSE 5 END AS search_rank",
                [$normalized, $literal.' %', $literal.'%', '%'.$literal.'%'],
            )
            ->whereIn('search_names.locale', $locales);

        $fulltextTerms = $this->eligibleFulltextTerms($query, $terms, $nameTermForms);
        if (count($terms) === 1 && mb_strlen($terms[0]) < 3 && preg_match('/^\p{L}+$/u', $terms[0])) {
            $names->whereRaw("search_names.name LIKE ? ESCAPE '!'", [$this->literalLike($terms[0]).'%']);
        } elseif ($fulltextTerms !== []) {
            // Each required word may match one known grammatical form. Keeping
            // full words here preserves FULLTEXT narrowing without broad stems.
            $booleanSearch = implode(' ', array_map(function (string $term) use ($nameTermForms): string {
                $forms = array_map(fn (string $form): string => mb_strtolower($form).'*', $nameTermForms[$term]);

                return count($forms) === 1 ? '+'.$forms[0] : '+('.implode(' ', $forms).')';
            }, $fulltextTerms));
            $names->whereRaw('MATCH(search_names.name) AGAINST(? IN BOOLEAN MODE)', [$booleanSearch]);
            $this->applyNameTerms($names, 'search_names.name', array_values(array_diff($terms, $fulltextTerms)), $nameTermForms);
        } else {
            $this->applyNameTerms($names, 'search_names.name', $terms, $nameTermForms);
        }

        // Each candidate branch can use its own index; the catalog does not run
        // description or variant EXISTS searches for every active product.
        $candidates = $names;
        foreach (['code', 'sku', 'barcode'] as $column) {
            $identifier = $database->table('products as search_identifiers')
                ->selectRaw('search_identifiers.id AS product_id')
                ->selectRaw('CASE WHEN LOWER(search_identifiers.'.$column.') = ? THEN 0 ELSE 6 END AS search_rank', [$normalized])
                ->where('search_identifiers.is_active', true)
                ->whereRaw("search_identifiers.{$column} LIKE ? ESCAPE '!'", [$this->literalLike($search).'%']);
            $candidates->unionAll($identifier);
        }

        $variants = $database->table('catalog_product_option_values as search_variants')
            ->selectRaw('search_variants.product_id AS product_id')
            ->selectRaw('CASE WHEN LOWER(search_variants.sku) = ? THEN 0 ELSE 6 END AS search_rank', [$normalized])
            ->where('search_variants.is_active', true)
            ->whereRaw("search_variants.sku LIKE ? ESCAPE '!'", [$this->literalLike($search).'%']);
        $candidates->unionAll($variants);

        $brands = $database->table('catalog_manufacturer_translations as search_brand_names')
            ->join('catalog_manufacturers as search_brands', 'search_brands.id', '=', 'search_brand_names.manufacturer_id')
            ->join('products as search_brand_products', 'search_brand_products.manufacturer_id', '=', 'search_brands.id')
            ->selectRaw('search_brand_products.id AS product_id, 7 AS search_rank')
            ->where('search_brands.is_active', true)
            ->where('search_brand_products.is_active', true)
            ->whereIn('search_brand_names.locale', $locales);
        foreach ($terms as $term) {
            $brandPrefix = $this->literalLike($term).'%';
            $brands->where(function (QueryBuilder $brandQuery) use ($brandPrefix): void {
                $brandQuery->whereRaw("search_brand_names.name LIKE ? ESCAPE '!'", [$brandPrefix])
                    ->orWhereRaw("search_brand_names.name LIKE ? ESCAPE '!'", ['% '.$brandPrefix]);
            });
        }
        $candidates->unionAll($brands);

        $rankedCandidates = $database->query()
            ->fromSub($candidates, 'product_search_candidates')
            ->select('product_id')
            ->selectRaw('MIN(search_rank) AS search_rank')
            ->groupBy('product_id');

        $query->joinSub($rankedCandidates, 'storefront_search_matches', function (JoinClause $join): void {
            $join->on('storefront_search_matches.product_id', '=', 'products.id');
        });
    }

    public function orderByRelevance(Builder $query, string $search): void
    {
        if ($this->normalize($search) !== '') {
            $query->orderBy('storefront_search_matches.search_rank');
        }
    }

    /**
     * @param  array<int, string>  $terms
     * @param  array<string, array<int, string>>  $nameTermForms
     */
    private function applyNameTerms(QueryBuilder $query, string $column, array $terms, array $nameTermForms): void
    {
        foreach ($terms as $term) {
            $query->where(function (QueryBuilder $wordQuery) use ($column, $term, $nameTermForms): void {
                foreach ($nameTermForms[$term] as $form) {
                    $wordQuery->orWhereRaw("{$column} LIKE ? ESCAPE '!'", ['%'.$this->literalLike($form).'%']);
                }
            });
        }
    }

    /**
     * @param  array<int, string>  $terms
     * @param  array<int, string>  $locales
     * @return array<string, array<int, string>>
     */
    private function nameTermForms(array $terms, array $locales): array
    {
        $knownForms = [];
        if (in_array('hr', $locales, true)) {
            foreach ((array) config('storefront-search.croatian_word_forms', []) as $group) {
                foreach ($group as $form) {
                    $knownForms[$form] = $group;
                }
            }
        }

        $result = [];
        foreach ($terms as $term) {
            $normalized = mb_strtolower($term);
            $result[$term] = [$term];
            foreach ($knownForms[$normalized] ?? [] as $form) {
                if ($form !== $normalized) {
                    $result[$term][] = $form;
                }
            }
        }

        return $result;
    }

    /**
     * @param  array<int, string>  $terms
     * @param  array<string, array<int, string>>  $nameTermForms
     * @return array<int, string>
     */
    private function eligibleFulltextTerms(Builder $query, array $terms, array $nameTermForms): array
    {
        if ($query->getConnection()->getDriverName() !== 'mysql') {
            return [];
        }

        $available = $this->nameFulltextAvailable ??= $query->getConnection()->getSchemaBuilder()->hasIndex('product_translations', self::NAME_FULLTEXT_INDEX);
        if (! $available) {
            return [];
        }

        $connection = $query->getConnection();
        $limits = Cache::remember('front:search:fulltext-limits:'.sha1($connection->getName().'|'.$connection->getDatabaseName()), now()->addHour(), function () use ($connection): array {
            $variables = $connection->selectOne('SELECT @@innodb_ft_min_token_size AS minimum, @@innodb_ft_max_token_size AS maximum, @@innodb_ft_enable_stopword AS stopwords_enabled');

            return ['minimum' => (int) $variables->minimum, 'maximum' => (int) $variables->maximum, 'stopwords_enabled' => (bool) $variables->stopwords_enabled];
        });

        // Eligible words narrow candidates through the index; short words and
        // stopwords remain literal residual conditions on those candidates.
        $stopwords = ['about', 'are', 'com', 'for', 'from', 'how', 'that', 'the', 'this', 'und', 'was', 'what', 'when', 'where', 'who', 'will', 'with', 'www'];

        return array_values(array_filter($terms, function (string $term) use ($limits, $stopwords, $nameTermForms): bool {
            // A short alternative might not exist in this server's FULLTEXT
            // index; use the literal fallback for that whole word group.
            foreach ($nameTermForms[$term] as $form) {
                if (mb_strlen($form) < $limits['minimum'] || mb_strlen($form) > $limits['maximum']
                    || ! preg_match('/^[\p{L}\p{N}]+$/u', $form)
                    || ($limits['stopwords_enabled'] && in_array(mb_strtolower($form), $stopwords, true))) {
                    return false;
                }
            }

            return true;
        }));
    }
}
