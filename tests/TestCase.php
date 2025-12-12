<?php

declare(strict_types=1);

namespace Frontier\Repositories\Tests;

use Tests\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    // In a monorepo integration, the package is already loaded by the main app.
    // We don't need getPackageProviders() as auto-discovery handles it.
}
