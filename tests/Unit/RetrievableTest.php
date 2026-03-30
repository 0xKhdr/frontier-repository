<?php

declare(strict_types=1);

use Frontier\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Helper: create a concrete BaseRepository with protected helpers exposed.
 */
function makeRetrievableRepo(): BaseRepository
{
    $model = Mockery::mock(Model::class);
    $builder = Mockery::mock(Builder::class);

    $model->shouldReceive('newQuery')->andReturn($builder);
    $model->shouldReceive('getTable')->andReturn('users');
    $builder->shouldReceive('getModel')->andReturn($model);

    return new class($model) extends BaseRepository
    {
        public function exposePrefixColumn(string $column): string
        {
            return $this->prefixColumn($column);
        }

        public function exposeValidateRawExpression(string $expr): string
        {
            return $this->validateRawExpression($expr);
        }
    };
}

describe('Retrievable — column prefixing', function (): void {
    it('prefixes a plain column with the table name', function (): void {
        $repo = makeRetrievableRepo();
        expect($repo->exposePrefixColumn('name'))->toBe('users.name');
    });

    it('does not double-prefix a column that already contains a dot', function (): void {
        $repo = makeRetrievableRepo();
        expect($repo->exposePrefixColumn('orders.total'))->toBe('orders.total');
    });

    it('strips the @ marker and returns the bare column without a table prefix', function (): void {
        // Bug fix: the regex previously rejected @ before the @ check was reached.
        $repo = makeRetrievableRepo();
        expect($repo->exposePrefixColumn('@raw_column'))->toBe('raw_column');
    });

    it('allows the wildcard * column', function (): void {
        $repo = makeRetrievableRepo();
        expect($repo->exposePrefixColumn('*'))->toBe('users.*');
    });

    it('throws for invalid column names', function (): void {
        $repo = makeRetrievableRepo();
        expect(fn () => $repo->exposePrefixColumn('"; DROP TABLE users; --'))
            ->toThrow(InvalidArgumentException::class);
    });
});

describe('Retrievable — raw expression validation', function (): void {
    it('allows benign expressions', function (): void {
        $repo = makeRetrievableRepo();
        expect($repo->exposeValidateRawExpression('FIELD(status, "active", "pending")'))->toBeString();
        expect($repo->exposeValidateRawExpression('name IS NULL, name'))->toBeString();
    });

    it('blocks DELETE', function (): void {
        $repo = makeRetrievableRepo();
        expect(fn () => $repo->exposeValidateRawExpression('delete from users'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('blocks DROP', function (): void {
        $repo = makeRetrievableRepo();
        expect(fn () => $repo->exposeValidateRawExpression('drop table users'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('allows UNION in raw expressions (legitimate ORDER BY usage)', function (): void {
        $repo = makeRetrievableRepo();
        // UNION is no longer blocked — it has legitimate uses in ORDER BY context
        // (e.g. FIELD() expressions, subquery ordering) and is not a DML/DDL risk.
        expect($repo->exposeValidateRawExpression('FIELD(status, "active", "union_member")'))->toBeString();
    });

    it('blocks TRUNCATE (newly added keyword)', function (): void {
        $repo = makeRetrievableRepo();
        expect(fn () => $repo->exposeValidateRawExpression('truncate table users'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('blocks EXEC (newly added keyword)', function (): void {
        $repo = makeRetrievableRepo();
        expect(fn () => $repo->exposeValidateRawExpression('exec xp_cmdshell'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('allows CREATE in raw expressions (not dangerous in ORDER BY context)', function (): void {
        $repo = makeRetrievableRepo();
        // CREATE is no longer blocked — not an ORDER BY injection vector.
        // Expressions like aliases mentioning "create" are now permissible.
        expect($repo->exposeValidateRawExpression('COALESCE(created_at, updated_at)'))->toBeString();
    });

    it('blocks GRANT (newly added keyword)', function (): void {
        $repo = makeRetrievableRepo();
        expect(fn () => $repo->exposeValidateRawExpression('grant all privileges'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('is case-insensitive for blocked keywords', function (): void {
        $repo = makeRetrievableRepo();
        expect(fn () => $repo->exposeValidateRawExpression('DROP table users'))
            ->toThrow(InvalidArgumentException::class);
        expect(fn () => $repo->exposeValidateRawExpression('Drop Table users'))
            ->toThrow(InvalidArgumentException::class);
    });
});
