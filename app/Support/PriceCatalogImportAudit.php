<?php

namespace App\Support;

/** Import reconciliation applies to the source snapshot, not the intentionally editable working copies. */
class PriceCatalogImportAudit
{
    public static function summarize(array $catalog, int $entryCount, ?array $parent = null): array
    {
        $metadata = $catalog['metadata'] ?? [];
        $imported = ! in_array($catalog['source_system'] ?? null, [null, 'manual'], true);
        $cloneId = $metadata['cloned_from'] ?? null;
        $parentImported = ! in_array($parent['source_system'] ?? null, [null, 'manual'], true);
        $validClone = $cloneId !== null && $parent !== null && (int) $cloneId === (int) $parent['id']
            && (int) $cloneId !== (int) $catalog['id']
            && ($parent['metadata']['import_complete'] ?? ! $parentImported) === true
            && ($parent['source_system'] ?? null) === ($catalog['source_system'] ?? null)
            && ($parent['source_snapshot'] ?? null) === ($catalog['source_snapshot'] ?? null)
            && ($parent['source_checksum'] ?? null) === ($catalog['source_checksum'] ?? null)
            && (! $imported || (is_string($catalog['source_checksum'] ?? null) && $catalog['source_checksum'] !== ''));
        $complete = ($metadata['import_complete'] ?? ! $imported) === true;
        $requiresSourceCount = $imported && ! $validClone;
        $expectedMatches = isset($metadata['import_expected_entries'])
            ? (int) $metadata['import_expected_entries'] === $entryCount : null;

        return [
            'id' => (int) $catalog['id'], 'status' => $catalog['status'], 'entries' => $entryCount,
            'import_complete' => $complete,
            'expected_entries_match' => $expectedMatches,
            'cloned_from' => $cloneId,
            'source_reconciliation_required' => $requiresSourceCount,
            'integrity_passed' => $complete && ($cloneId === null || $validClone)
                && (! $requiresSourceCount || $expectedMatches === true),
        ];
    }
}
