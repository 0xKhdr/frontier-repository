<?php

namespace Frontier\Actions;

use Exception;
use Throwable;

abstract class AbstractAction implements Contracts\Action
{
    /**
     * @throws Throwable
     */
    public static function exec(...$arguments): mixed
    {
        return app(static::class)->execute(...$arguments);
    }

    /**
     * @throws Throwable
     */
    public function execute(...$arguments): mixed
    {
        if (! method_exists($this, 'handle')) {
            throw new Exception(sprintf(
                '%s must implement handle method',
                static::class,
            ));
        }

        return $this->handle(...$arguments);
    }
}
