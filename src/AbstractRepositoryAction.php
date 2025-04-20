<?php

namespace Frontier\Repositories;

use Frontier\Actions\AbstractAction;
use Frontier\Repositories\Contracts\Repository;

class AbstractRepositoryAction extends AbstractAction
{
    protected Repository $repository;
}
