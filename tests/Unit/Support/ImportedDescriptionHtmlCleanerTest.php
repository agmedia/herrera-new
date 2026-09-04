<?php

namespace Tests\Unit\Support;

use App\Support\ImportedDescriptionHtmlCleaner;
use PHPUnit\Framework\TestCase;

class ImportedDescriptionHtmlCleanerTest extends TestCase
{
    public function test_it_removes_span_tags_and_inline_styles_but_keeps_useful_html(): void
    {
        $cleaner = new ImportedDescriptionHtmlCleaner;

        $cleaned = $cleaner->clean(
            '<style>.x{color:red}</style><p style="color:red">Hello <span style="font-weight:bold">world</span></p><ul><li><span>One</span></li></ul>'
        );

        $this->assertSame('<p>Hello world</p><ul><li>One</li></ul>', $cleaned);
    }

    public function test_it_decodes_html_entities_before_cleaning(): void
    {
        $cleaner = new ImportedDescriptionHtmlCleaner;

        $cleaned = $cleaner->clean('&lt;p style=&quot;color:red&quot;&gt;A &lt;span&gt;B&lt;/span&gt;&lt;/p&gt;');

        $this->assertSame('<p>A B</p>', $cleaned);
    }

    public function test_it_removes_executable_elements_attributes_and_unsafe_urls(): void
    {
        $cleaner = new ImportedDescriptionHtmlCleaner;

        $cleaned = $cleaner->clean(
            '<p onclick="alert(1)">Safe</p><img src="javascript:alert(2)" srcset="javascript:alert(3) 1x" onerror="alert(4)"><a href="java&#x0A;script:alert(5)">Link</a><iframe srcdoc="bad"></iframe><a href="https://example.com/item">Good</a>'
        );

        $this->assertSame(
            '<p>Safe</p><img><a>Link</a><a href="https://example.com/item">Good</a>',
            $cleaned,
        );
    }
}
