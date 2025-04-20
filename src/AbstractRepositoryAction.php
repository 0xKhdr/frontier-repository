<?php

namespace Frontier\Repositories;

use Frontier\Actions\AbstractAction as FrontierAbstractAction;
use Frontier\Repositories\Contracts\Repository;

class AbstractRepositoryAction extends FrontierAbstractAction
{
    protected Repository $repository;
}
