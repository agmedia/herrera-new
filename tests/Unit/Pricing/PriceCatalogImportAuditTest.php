<?php

namespace Tests\Unit\Pricing;

use App\Support\PriceCatalogImportAudit;
use PHPUnit\Framework\TestCase;

class PriceCatalogImportAuditTest extends TestCase
{
    public function test_original_import_still_requires_exact_count_and_completion(): void
    {
        $source = $this->source();
        $this->assertTrue(PriceCatalogImportAudit::summarize($source, 10)['integrity_passed']);
        $this->assertFalse(PriceCatalogImportAudit::summarize($source, 9)['integrity_passed']);
        unset($source['metadata']['import_expected_entries']);
        $this->assertFalse(PriceCatalogImportAudit::summarize($source, 10)['integrity_passed']);
        $source['metadata']['import_complete'] = false;
        $this->assertFalse(PriceCatalogImportAudit::summarize($source, 10)['integrity_passed']);
    }

    public function test_verified_working_copy_can_have_intentional_price_changes(): void
    {
        $source = $this->source();
        $copy = array_replace($source, ['id' => 2, 'status' => 'draft', 'metadata' => ['cloned_from' => 1, 'import_complete' => true]]);
        $report = PriceCatalogImportAudit::summarize($copy, 25, $source);
        $this->assertTrue($report['integrity_passed']);
        $this->assertFalse($report['source_reconciliation_required']);
        $this->assertNull($report['expected_entries_match']);
        $copy['status'] = 'active';
        $this->assertTrue(PriceCatalogImportAudit::summarize($copy, 25, $source)['integrity_passed']);
    }

    public function test_invalid_clone_lineage_is_not_mistaken_for_a_completed_import(): void
    {
        $source = $this->source();
        $copy = array_replace($source, ['id' => 2, 'metadata' => ['cloned_from' => 1, 'import_complete' => true]]);
        $this->assertFalse(PriceCatalogImportAudit::summarize($copy, 10)['integrity_passed']);
        $copy['source_checksum'] = 'different';
        $this->assertFalse(PriceCatalogImportAudit::summarize($copy, 10, $source)['integrity_passed']);
        $copy['source_checksum'] = $source['source_checksum'];
        $source['metadata']['import_complete'] = false;
        $this->assertFalse(PriceCatalogImportAudit::summarize($copy, 10, $source)['integrity_passed']);
    }

    public function test_manual_draft_is_not_treated_as_an_unfinished_import(): void
    {
        $catalog = ['id' => 3, 'status' => 'draft', 'source_system' => null, 'metadata' => []];
        $report = PriceCatalogImportAudit::summarize($catalog, 2);
        $this->assertTrue($report['integrity_passed']);
        $this->assertFalse($report['source_reconciliation_required']);
        $copy = array_replace($catalog, ['id' => 4, 'metadata' => ['cloned_from' => 3]]);
        $this->assertTrue(PriceCatalogImportAudit::summarize($copy, 4, $catalog)['integrity_passed']);
    }

    private function source(): array
    {
        return ['id' => 1, 'status' => 'active', 'source_system' => 'herrera-opencart', 'source_snapshot' => 'source', 'source_checksum' => 'exact-original-hash', 'metadata' => ['import_complete' => true, 'import_expected_entries' => 10]];
    }
}
