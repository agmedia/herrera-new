<?php

namespace App\Services\Integrations\Eracuni;

use RuntimeException;

/** A locally generated reason and a bounded request locator, never a provider body. */
class EracuniCompanySalesException extends RuntimeException
{
    public readonly array $context;

    public function __construct(string $message, array $context = [])
    {
        $safe = [];
        if (in_array($context['method'] ?? null, array_unique([...EracuniCompanySalesClient::SOURCES, ...EracuniCompanySalesClient::FINANCIAL_SOURCES]), true)) {
            $safe['method'] = $context['method'];
        }
        if (in_array($context['pass'] ?? null, ['document', 'supply', 'issued'], true)) {
            $safe['pass'] = $context['pass'];
        }
        foreach (['from', 'to'] as $key) {
            if (is_string($context[$key] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $context[$key])) {
                $safe[$key] = $context[$key];
            }
        }
        if ((is_string($context['document_id'] ?? null) || is_int($context['document_id'] ?? null))
            && preg_match('/^[A-Za-z0-9_.-]{1,160}$/D', (string) $context['document_id'])) {
            $safe['document_id'] = (string) $context['document_id'];
        }
        foreach (['line_position', 'request_count'] as $key) {
            if (is_int($context[$key] ?? null) && $context[$key] > 0 && $context[$key] <= 1000000) {
                $safe[$key] = $context[$key];
            }
        }
        $this->context = $safe;
        parent::__construct($message);
    }
}
