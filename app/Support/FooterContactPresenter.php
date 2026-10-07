<?php

namespace App\Support;

class FooterContactPresenter
{
    /** @return array<int, string> */
    public static function emails(array $footer): array
    {
        return collect([$footer['email_sales'] ?? '', $footer['email_support'] ?? ''])
            ->map(fn ($email) => trim((string) $email))
            ->filter()->unique(fn ($email) => strtolower($email))->values()->all();
    }

    /** @return array<int, string> */
    public static function addressBlocks(string $address): array
    {
        $blocks = preg_split('/\n\s*\n/u', str_replace(["\r\n", "\r"], "\n", trim($address))) ?: [];
        $result = [];
        $locations = [];
        foreach ($blocks as $block) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $block))));
            if (count($lines) === 2 && str_ends_with($lines[0], ':')) {
                $key = mb_strtolower($lines[1]);
                if (isset($locations[$key])) {
                    $index = $locations[$key];
                    [$label, $location] = explode("\n", $result[$index], 2);
                    $result[$index] = rtrim($label, ':').' / '.rtrim($lines[0], ':').":\n".$location;

                    continue;
                }
                $locations[$key] = count($result);
            }
            if ($lines !== []) {
                $result[] = implode("\n", $lines);
            }
        }

        return $result;
    }
}
