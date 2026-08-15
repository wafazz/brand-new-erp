<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Unit', 'Architecture', 'Concurrency');
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature', 'Isolation', 'Security');

require_once __DIR__.'/Helpers.php';
