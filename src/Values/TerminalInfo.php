<?php

declare(strict_types=1);

namespace Whisp\Values;

class TerminalInfo
{
    public function __construct(
        public readonly string $term,
        public readonly int $widthChars,
        public readonly int $heightRows,
        public readonly int $widthPixels,
        public readonly int $heightPixels,
        public readonly array $modes,
    ) {}
}
