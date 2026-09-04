<?php

namespace App\Data\Import;

enum CatalogImportAction: string
{
    case Create = 'create';
    case Update = 'update';
    case Activate = 'activate';
    case Deactivate = 'deactivate';
    case Tombstone = 'tombstone';
    case Noop = 'noop';
    case Conflict = 'conflict';
}
