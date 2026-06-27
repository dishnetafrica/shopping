<?php
namespace App\Services\Knowledge\Contracts;

use App\Services\Knowledge\Dto\ExtractionResult;

/** Turns owner text (for one capability's concern) into Facts + Actions. Pure where possible. */
interface Extractor
{
    /**
     * @param string $text     the owner message (or clause)
     * @param array  $profile  resolved owner profile (aliases, language, timezone)
     */
    public function extract(string $text, array $profile = []): ExtractionResult;
}
