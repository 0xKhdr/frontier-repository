<?php

declare(strict_types=1);

namespace Frontier\Repositories\Actions;

use Frontier\Repositories\BaseAction;

class CountAction extends BaseAction
{
    public function handle(array $conditions): int
    {
        return $this->repository->count($conditions);
    }
}
