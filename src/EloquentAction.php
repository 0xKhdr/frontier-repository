<?php

namespace Frontier\Actions;

use Illuminate\Database\Eloquent\Model;

abstract class EloquentAction extends AbstractAction
{
    protected Model $model;
}