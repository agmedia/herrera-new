<?php

namespace Tests\Unit\Support;

use App\Support\KozoCroatianTextNormalizer;
use PHPUnit\Framework\TestCase;

class KozoCroatianTextNormalizerTest extends TestCase
{
    public function test_it_repairs_common_croatian_diacritic_corruptions(): void
    {
        $normalizer = new KozoCroatianTextNormalizer();

        $normalized = $normalizer->normalize(
            'Za opuštanje u pidžama izra?enih od pamuka, zahvaljuju?i meko?i i prozra?nosti mogu?e je osje?ati ugodu tijekom ve?eri.'
        );

        $this->assertSame(
            'Za opuštanje u pidžama izrađenih od pamuka, zahvaljujući mekoći i prozračnosti moguće je osjećati ugodu tijekom večeri.',
            $normalized
        );
    }

    public function test_it_repairs_mixed_question_mark_words_without_blind_global_replacement(): void
    {
        $normalizer = new KozoCroatianTextNormalizer();

        $normalized = $normalizer->normalize('?ešljani pamuk dodatno je obra?ena vlakna pa su gla?a i ?vrš?a od obi?nog pamuka.');

        $this->assertSame('Češljani pamuk dodatno je obrađena vlakna pa su glađa i čvršća od običnog pamuka.', $normalized);
    }

    public function test_it_reports_only_unresolved_tokens(): void
    {
        $normalizer = new KozoCroatianTextNormalizer();

        $tokens = $normalizer->unresolvedTokens('Sve je dobro osim foo?bar i baz?');

        $this->assertSame(['foo?bar', 'baz?'], $tokens);
    }
}
