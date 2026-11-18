<?php

namespace App\Services\Tracking\Platforms;

interface Platform
{
    public function name(): string;

    public function enabled(): bool;

    public function batchSize(): int;

    /** @return array{ok:bool, status:int, body:array} */
    public function send(array $events): array;
}
