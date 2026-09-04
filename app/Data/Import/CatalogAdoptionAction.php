<?php

namespace App\Data\Import;

enum CatalogAdoptionAction: string
{
    case Adopt = 'adopt';
    case AlreadyMapped = 'already_mapped';
    case Unmatched = 'unmatched';
    case SkipTombstone = 'skip_tombstone';
    case Conflict = 'conflict';
}
