<?php

// Hand-written cases the demo app does not cover.

namespace App\Filament\Resources;

use App\Models\Report;
use App\Models\Team;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Tables\Columns\ToggleColumn;
use Illuminate\Support\Facades\Gate;

class ReportResource extends Resource
{
    protected static ?string $model = Report::class;

    public static function boot(): void
    {
        static::skipAuthorization();
    }

    public static function actions(): array
    {
        return [
            Action::make('export')->action(fn () => null),
            Action::make('archive')->authorize('archive')->action(fn () => null),
            ToggleColumn::make('published')->disabled(fn () => true),
        ];
    }
}

class TeamResource extends Resource
{
    protected static ?string $model = Team::class;
}

class AuthServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Team::class, \App\Policies\Teams::class);
    }
}
