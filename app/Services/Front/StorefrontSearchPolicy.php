<?php

namespace App\Services\Front;

use Illuminate\Validation\ValidationException;

class StorefrontSearchPolicy
{
    public function normalize(mixed $query): string
    {
        if ($query === null || $query === '') {
            return '';
        }

        if (! is_string($query) || ! mb_check_encoding($query, 'UTF-8')) {
            $this->reject('invalid_query');
        }

        $maxCharacters = max(1, (int) config('storefront-search.max_characters', 100));
        if (strlen($query) > max(1, (int) config('storefront-search.max_bytes', 400))
            || mb_strlen($query) > $maxCharacters) {
            $this->reject('too_long', ['max' => $maxCharacters]);
        }

        $query = str_replace(["\r", "\n", "\t"], ' ', $query);
        if (preg_match('/[\p{Cc}\p{Cf}]/u', $query)) {
            $this->reject('invalid_query');
        }

        $query = trim((string) preg_replace('/[\p{Z}\s]+/u', ' ', $query));
        if ($query === '') {
            return '';
        }

        // Nonblank punctuation must never become an unfiltered catalog request.
        if (! preg_match('/[\p{L}\p{N}]/u', $query)) {
            $this->reject('invalid_query');
        }

        if (count(explode(' ', $query)) > max(1, (int) config('storefront-search.max_terms', 12))) {
            $this->reject('too_many_terms');
        }

        if (substr_count($query, '%') + substr_count($query, '_')
            > max(0, (int) config('storefront-search.max_wildcard_characters', 4))) {
            $this->reject('too_many_wildcards');
        }

        return $query;
    }

    public function validatePage(mixed $page): void
    {
        if ($page === null || $page === '') {
            return;
        }

        $maxPage = max(1, (int) config('storefront-search.max_search_page', 1000));
        if ((! is_string($page) && ! is_int($page))
            || ! preg_match('/^[0-9]{1,10}$/D', (string) $page)
            || (int) $page < 1 || (int) $page > $maxPage) {
            throw ValidationException::withMessages(['page' => __('search.invalid_page', ['max' => $maxPage])]);
        }
    }

    /** Use the returned pattern with SQL LIKE ? ESCAPE '!'. */
    public function literalLike(string $query): string
    {
        return strtr($query, ['!' => '!!', '%' => '!%', '_' => '!_']);
    }

    private function reject(string $message, array $replace = []): never
    {
        throw ValidationException::withMessages(['q' => __('search.'.$message, $replace)]);
    }
}
