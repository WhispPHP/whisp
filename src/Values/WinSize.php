<?php

declare(strict_types=1);

namespace Whisp\Values;

class Winsize
{
    public function __construct(
        public readonly int $rows,      // Number of rows (in cells)
        public readonly int $cols,      // Number of columns (in cells)
        public readonly int $widthPixels = 0, // Width in pixels
        public readonly int $heightPixels = 0  // Height in pixels
    ) {}
}
