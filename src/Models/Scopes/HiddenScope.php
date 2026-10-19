<?php

namespace SocialDept\AtpAppview\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Leaves out records whose account is deactivated, taken down or suspended,
 * and records moderation hides. withHidden() brings them back into a query,
 * onlyHidden() narrows to them.
 */
class HiddenScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->whereNull($model->qualifyColumn('hidden_at'))->whereNull($model->qualifyColumn('moderated_at'));
    }

    public function extend(Builder $builder): void
    {
        $builder->macro('withHidden', fn (Builder $builder) => $builder->withoutGlobalScope($this));

        $builder->macro('onlyHidden', fn (Builder $builder) => $builder->withoutGlobalScope($this)
            ->where(fn (Builder $query) => $query->whereNotNull($builder->getModel()->qualifyColumn('hidden_at'))
                ->orWhereNotNull($builder->getModel()->qualifyColumn('moderated_at'))));
    }
}
