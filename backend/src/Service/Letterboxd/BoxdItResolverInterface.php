<?php

declare(strict_types=1);

namespace App\Service\Letterboxd;

interface BoxdItResolverInterface
{
    public function resolve(string $code): ?string;
}
