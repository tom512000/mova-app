<?php

declare(strict_types=1);

namespace App\Service\Letterboxd;

use App\DTO\Letterboxd\RssDiaryEntry;

interface LetterboxdRssClientInterface
{
    /**
     * @return RssDiaryEntry[]
     */
    public function fetchDiaryEntries(string $username): array;
}
