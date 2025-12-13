<?php

declare(strict_types=1);

namespace Frontier\Repositories\Tests;

use Frontier\Repositories\Providers\ServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app)
    {
        return [
            ServiceProvider::class,
        ];
    }
}
