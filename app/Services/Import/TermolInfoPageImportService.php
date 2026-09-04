<?php

namespace App\Services\Import;

use App\Models\Content\Page\InfoPage;
use App\Models\User;
use App\Services\Settings\SystemSettingsService;
use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TermolInfoPageImportService
{
    private const SOURCE_USER_AGENT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

    /**
     * @var array<string, array{
     *     title:string,
     *     slug:string,
     *     excerpt:string,
     *     source_url:string,
     *     source_class:string,
     *     layout:string,
     *     sort_order:int
     * }>
     */
    private const SOURCE_PAGES = [
        'about-us' => [
            'title' => 'O nama',
            'slug' => 'o-nama',
            'excerpt' => 'Upoznajte Termol, naše iskustvo, ponudu i podatke o tvrtki.',
            'source_url' => 'https://www.termol.hr/o-nama.aspx',
            'source_class' => 'HR_VK_TERMOL_bnm869_ABOUTUS',
            'layout' => 'default',
            'sort_order' => 10,
        ],
        'payment-methods' => [
            'title' => 'Načini plaćanja',
            'slug' => 'nacini-placanja',
            'excerpt' => 'Gotovinsko, kartično i obročno plaćanje u poslovnici i webshopu.',
            'source_url' => 'https://www.termol.hr/nacini-placanja.aspx',
            'source_class' => 'HR_VK_TERMOL_bnm869_PAYMENT_METHODS',
            'layout' => 'default',
            'sort_order' => 20,
        ],
        'shipping-returns' => [
            'title' => 'Načini dostave',
            'slug' => 'nacini-dostave',
            'excerpt' => 'Uvjeti, rokovi i troškovi dostave naručene robe.',
            'source_url' => 'https://www.termol.hr/nacini-dostave.aspx',
            'source_class' => 'HR_VK_TERMOL_bnm869_SHIPPING_METHODS',
            'layout' => 'default',
            'sort_order' => 30,
        ],
        'returns-claims' => [
            'title' => 'Povrati i reklamacije',
            'slug' => 'povrati-i-reklamacije',
            'excerpt' => 'Uvjeti jednostranog raskida ugovora, povrata robe i reklamacija.',
            'source_url' => 'https://www.termol.hr/WebContent.aspx?token=HR_VK_TERMOL_bnm869_POVRATI_REKLAMACIJE',
            'source_class' => 'HR_VK_TERMOL_bnm869_POVRATI_REKLAMACIJE',
            'layout' => 'legal',
            'sort_order' => 40,
        ],
        'terms-of-use' => [
            'title' => 'Uvjeti korištenja',
            'slug' => 'uvjeti-koristenja',
            'excerpt' => 'Opći uvjeti korištenja Termol webshopa i kupoprodaje.',
            'source_url' => 'https://www.termol.hr/uvjeti-koristenja.aspx',
            'source_class' => 'HR_VK_TERMOL_bnm869_TERMS_CONDITIONS',
            'layout' => 'legal',
            'sort_order' => 50,
        ],
        'privacy-policy' => [
            'title' => 'Privatnost podataka',
            'slug' => 'privatnost-podataka',
            'excerpt' => 'Politika privatnosti i zaštite osobnih podataka.',
            'source_url' => 'https://www.termol.hr/privatnost-podataka.aspx',
            'source_class' => 'HR_VK_TERMOL_bnm869_PRIVACY_INFORMATION',
            'layout' => 'legal',
            'sort_order' => 60,
        ],
    ];

    public function __construct(
        private readonly SystemSettingsService $settings,
    ) {}

    /**
     * @return array{pages_imported:int,footer_columns_configured:int}
     */
    public function import(): array
    {
        $sourceBodies = [];

        foreach (self::SOURCE_PAGES as $code => $page) {
            $response = Http::accept('text/html')
                ->withUserAgent(self::SOURCE_USER_AGENT)
                ->timeout(30)
                ->retry(2, 250)
                ->get($page['source_url']);

            if (! $response->successful()) {
                throw new RuntimeException(sprintf(
                    'Termol info page "%s" returned HTTP %d.',
                    $page['source_url'],
                    $response->status()
                ));
            }

            $sourceBodies[$code] = $this->extractAndCleanBody(
                $response->body(),
                $page['source_class'],
                $page['title']
            );
        }

        $pageIds = DB::transaction(function () use ($sourceBodies): array {
            $userId = User::query()->value('id');
            $ids = [];

            foreach (self::SOURCE_PAGES as $code => $pageData) {
                $page = InfoPage::query()->updateOrCreate(
                    ['code' => $code],
                    [
                        'layout' => $pageData['layout'],
                        'is_active' => true,
                        'show_in_footer' => true,
                        'published_at' => now(),
                        'sort_order' => $pageData['sort_order'],
                        'payload' => [
                            'source' => 'termol.hr',
                            'source_url' => $pageData['source_url'],
                            'source_snapshot_date' => '2026-07-24',
                        ],
                        'created_by' => $userId,
                        'updated_by' => $userId,
                    ]
                );

                $page->translations()->updateOrCreate(
                    ['locale' => 'hr'],
                    [
                        'title' => $pageData['title'],
                        'slug' => $pageData['slug'],
                        'excerpt' => $pageData['excerpt'],
                        'body_html' => $sourceBodies[$code],
                        'meta_title' => $pageData['title'].' | Termol',
                        'meta_description' => $pageData['excerpt'],
                        'payload' => [
                            'source' => 'termol.hr',
                            'source_url' => $pageData['source_url'],
                        ],
                    ]
                );

                $ids[$code] = (int) $page->id;
            }

            return $ids;
        });

        $this->configureStoreSettings($pageIds);

        return [
            'pages_imported' => count($pageIds),
            'footer_columns_configured' => 3,
        ];
    }

    /**
     * @param  array<string, int>  $pageIds
     */
    private function configureStoreSettings(array $pageIds): void
    {
        $socialUrls = [
            'https://www.facebook.com/termoldoo/',
            'https://www.instagram.com/termol_vinkovci/?hl=hr',
            'https://www.youtube.com/channel/UCXZ13uQmTVvnVZvhhmPjMvQ',
        ];

        $this->settings->putMany([
            'store_brand_name' => 'Termol',
            'store_footer_phone' => '+385 91 600 1958',
            'store_footer_email_sales' => 'webshop@termol.hr',
            'store_footer_email_support' => 'info@termol.hr',
            'store_footer_hours' => 'PON–PET 08:00–16:00, SUB 09:00–14:00',
            'store_footer_hours_translations' => [
                'hr' => 'PON–PET 08:00–16:00, SUB 09:00–14:00',
                'en' => 'MON–FRI 08:00–16:00, SAT 09:00–14:00',
            ],
            'store_footer_contact_title' => 'Kontakt i podrška',
            'store_footer_contact_title_translations' => [
                'hr' => 'Kontakt i podrška',
                'en' => 'Contact and support',
            ],
            'store_footer_contact_intro' => 'Webshop upiti i informacije',
            'store_footer_contact_intro_translations' => [
                'hr' => 'Webshop upiti i informacije',
                'en' => 'Webshop inquiries and information',
            ],
            'store_footer_col_1_title' => 'Termol',
            'store_footer_col_1_title_translations' => [
                'hr' => 'Termol',
                'en' => 'Termol',
            ],
            'store_footer_col_1_category_ids' => [],
            'store_footer_col_1_page_ids' => [$pageIds['about-us']],
            'store_footer_col_1_custom_links' => "Novosti|/blog\nKontakt|/contact",
            'store_footer_col_1_custom_links_translations' => [
                'hr' => "Novosti|/blog\nKontakt|/contact",
                'en' => "News|/blog\nContact|/contact",
            ],
            'store_footer_col_2_title' => 'Kupovina',
            'store_footer_col_2_title_translations' => [
                'hr' => 'Kupovina',
                'en' => 'Shopping',
            ],
            'store_footer_col_2_category_ids' => [],
            'store_footer_col_2_page_ids' => [
                $pageIds['payment-methods'],
                $pageIds['shipping-returns'],
                $pageIds['returns-claims'],
            ],
            'store_footer_col_2_custom_links' => 'Raskid ugovora|/forma-za-povrat-i-reklamacije',
            'store_footer_col_2_custom_links_translations' => [
                'hr' => 'Raskid ugovora|/forma-za-povrat-i-reklamacije',
                'en' => 'Withdraw from contract|/returns-and-claims',
            ],
            'store_footer_col_3_title' => 'Informacije',
            'store_footer_col_3_title_translations' => [
                'hr' => 'Informacije',
                'en' => 'Information',
            ],
            'store_footer_col_3_category_ids' => [],
            'store_footer_col_3_page_ids' => [
                $pageIds['terms-of-use'],
                $pageIds['privacy-policy'],
            ],
            'store_footer_col_3_custom_links' => '',
            'store_footer_col_3_custom_links_translations' => [],
            'store_footer_bottom_link_page_ids' => [
                $pageIds['terms-of-use'],
                $pageIds['privacy-policy'],
            ],
            'store_footer_bottom_copyright_text' => 'Sva prava pridržana.',
            'store_footer_bottom_copyright_text_translations' => [
                'hr' => 'Sva prava pridržana.',
                'en' => 'All rights reserved.',
            ],
            'store_social_facebook_url' => $socialUrls[0],
            'store_social_instagram_url' => $socialUrls[1],
            'store_social_youtube_url' => $socialUrls[2],
            'store_footer_social_facebook_enabled' => true,
            'store_footer_social_instagram_enabled' => true,
            'store_footer_social_tiktok_enabled' => false,
            'store_footer_social_youtube_enabled' => true,
            'store_email_contact_to' => 'info@termol.hr',
            'store_email_orders_to' => 'webshop@termol.hr',
            'store_schema_org_type' => 'Store',
            'store_schema_business_name' => 'TERMOL za trgovinu i usluge d.o.o.',
            'store_schema_business_phone' => '+385 91 600 1958',
            'store_schema_business_email' => 'info@termol.hr',
            'store_schema_address_street' => 'Lapovačka 11A',
            'store_schema_address_city' => 'Vinkovci',
            'store_schema_address_region' => 'Vukovarsko-srijemska županija',
            'store_schema_address_postal_code' => '32100',
            'store_schema_address_country' => 'HR',
            'store_schema_same_as' => implode("\n", $socialUrls),
        ]);
    }

    private function extractAndCleanBody(string $html, string $sourceClass, string $pageTitle): string
    {
        if (! class_exists(DOMDocument::class)) {
            throw new RuntimeException('The DOM extension is required to import Termol info pages.');
        }

        $previousUseInternalErrors = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $encodedHtml = mb_encode_numericentity(
            $html,
            [0x80, 0x10FFFF, 0, 0xFFFFFF],
            'UTF-8'
        );

        try {
            $loaded = $document->loadHTML($encodedHtml);
            if (! $loaded) {
                throw new RuntimeException('Termol info page HTML could not be parsed.');
            }

            $xpath = new DOMXPath($document);
            $query = sprintf(
                '//*[contains(concat(" ", normalize-space(@class), " "), " %s ")]',
                $sourceClass
            );
            $root = $xpath->query($query)->item(0);

            if (! $root instanceof DOMElement) {
                throw new RuntimeException(sprintf(
                    'Termol info page content container "%s" was not found.',
                    $sourceClass
                ));
            }

            $this->removeComments($root);
            foreach (['script', 'style', 'link', 'meta', 'img', 'iframe', 'object', 'embed', 'form', 'noscript'] as $tagName) {
                $this->removeElementsByTagName($root, $tagName);
            }

            $this->removeDuplicateTitle($root, $pageTitle);
            $this->unwrapUnsupportedElements($root);
            $this->cleanElementAttributes($root);
            $this->removeEmptyBlocks($root);

            $body = $this->normalizeHtml($this->innerHtml($root));
            if ($body === '') {
                throw new RuntimeException(sprintf(
                    'Termol info page "%s" did not contain importable text.',
                    $pageTitle
                ));
            }

            return $body;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseInternalErrors);
        }
    }

    private function removeComments(DOMNode $node): void
    {
        $children = [];
        foreach ($node->childNodes as $childNode) {
            $children[] = $childNode;
        }

        foreach ($children as $childNode) {
            if ($childNode instanceof DOMComment) {
                $node->removeChild($childNode);

                continue;
            }

            $this->removeComments($childNode);
        }
    }

    private function removeElementsByTagName(DOMElement $root, string $tagName): void
    {
        $nodes = [];
        foreach ($root->getElementsByTagName($tagName) as $node) {
            $nodes[] = $node;
        }

        foreach (array_reverse($nodes) as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    private function removeDuplicateTitle(DOMElement $root, string $pageTitle): void
    {
        $normalizedTitle = $this->normalizedText($pageTitle);

        foreach (['h1', 'h2'] as $tagName) {
            $headings = [];
            foreach ($root->getElementsByTagName($tagName) as $heading) {
                $headings[] = $heading;
            }

            foreach ($headings as $heading) {
                if ($this->normalizedText($heading->textContent) === $normalizedTitle) {
                    $heading->parentNode?->removeChild($heading);

                    return;
                }
            }
        }
    }

    private function unwrapUnsupportedElements(DOMElement $root): void
    {
        $allowed = [
            'a',
            'b',
            'blockquote',
            'br',
            'em',
            'h2',
            'h3',
            'h4',
            'hr',
            'i',
            'li',
            'ol',
            'p',
            'strong',
            'u',
            'ul',
        ];
        $nodes = [];

        foreach ($root->getElementsByTagName('*') as $node) {
            if (! in_array(strtolower($node->nodeName), $allowed, true)) {
                $nodes[] = $node;
            }
        }

        foreach (array_reverse($nodes) as $node) {
            $parent = $node->parentNode;
            if (! $parent) {
                continue;
            }

            while ($node->firstChild) {
                $parent->insertBefore($node->firstChild, $node);
            }

            $parent->removeChild($node);
        }
    }

    private function cleanElementAttributes(DOMNode $node): void
    {
        if ($node instanceof DOMElement) {
            $attributes = [];
            foreach ($node->attributes as $attribute) {
                $attributes[] = $attribute->nodeName;
            }

            foreach ($attributes as $attributeName) {
                if ($node->nodeName !== 'a' || $attributeName !== 'href') {
                    $node->removeAttribute($attributeName);
                }
            }

            if ($node->nodeName === 'a') {
                $href = trim($node->getAttribute('href'));
                if (! preg_match('#^(https?://|mailto:|tel:|/)#i', $href)) {
                    $node->removeAttribute('href');
                }
            }
        }

        $children = [];
        foreach ($node->childNodes as $childNode) {
            $children[] = $childNode;
        }

        foreach ($children as $childNode) {
            $this->cleanElementAttributes($childNode);
        }
    }

    private function removeEmptyBlocks(DOMElement $root): void
    {
        $blockTags = ['p', 'h2', 'h3', 'h4', 'li', 'ul', 'ol', 'blockquote'];

        do {
            $removed = false;
            $nodes = [];
            foreach ($root->getElementsByTagName('*') as $node) {
                if (in_array(strtolower($node->nodeName), $blockTags, true)) {
                    $nodes[] = $node;
                }
            }

            foreach (array_reverse($nodes) as $node) {
                if ($this->normalizedText($node->textContent) !== '') {
                    continue;
                }

                $node->parentNode?->removeChild($node);
                $removed = true;
            }
        } while ($removed);
    }

    private function normalizedText(string $value): string
    {
        $value = str_replace("\u{00A0}", ' ', $value);

        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }

    private function innerHtml(DOMElement $element): string
    {
        $html = '';
        foreach ($element->childNodes as $childNode) {
            $html .= $element->ownerDocument?->saveHTML($childNode) ?? '';
        }

        return $html;
    }

    private function normalizeHtml(string $html): string
    {
        $normalized = str_replace("\u{00A0}", ' ', $html);
        $normalized = preg_replace('/>\s+</u', '><', $normalized) ?? $normalized;

        return trim($normalized);
    }
}
