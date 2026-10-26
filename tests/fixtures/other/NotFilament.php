<?php

// Classes that share Filament's short names, and a link-only action: none of these is a finding.

namespace App\Filament\Resources;

use App\Actions\Action as AppAction;
use App\Support\SelectColumn;
use Filament\Actions\Action;

class Links
{
    public function actions(): array
    {
        return [
            AppAction::make('sync'),
            SelectColumn::make('status'),
            Action::make('docs')->url('https://example.test/docs')->openUrlInNewTab(),
            Action::make('lock')->disabled(fn () => true)->action(fn () => null),
        ];
    }
}
