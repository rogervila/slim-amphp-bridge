<?php

declare(strict_types=1);

namespace Rogervila\SlimAmphpBridge\Example;

final readonly class Todo
{
    public function __construct(
        public string $id,
        public string $title,
        public bool $completed = false,
    ) {
    }
}
