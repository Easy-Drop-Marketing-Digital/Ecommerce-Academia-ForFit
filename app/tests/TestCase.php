<?php

namespace Tests;

use App\Models\Setting;
use App\Services\Tracking\ContentId;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Configurações ficam em memória por requisição; cada teste começa limpo
        Setting::flushMemo();
        ContentId::reset();
    }
}
