<?php

namespace App\Filament\Resources\Purges;

use App\Filament\Resources\Purges\Pages\ListPurges;
use App\Filament\Resources\Purges\Pages\ViewPurge;
use App\Filament\Resources\Purges\Schemas\PurgeForm;
use App\Filament\Resources\Purges\Tables\PurgesTable;
use App\Models\Purge;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class PurgeResource extends Resource
{
    protected static ?string $model = Purge::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-trash';

    protected static ?string $navigationLabel = 'Tweet Purges';

    protected static ?string $modelLabel = 'Purge';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return PurgeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PurgesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurges::route('/'),
            'view' => ViewPurge::route('/{record}'),
        ];
    }

    /**
     * Scope every Filament query to purges owned by the current user's
     * accounts. Unassigned purges are hidden from the UI entirely.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $userId = Auth::id();

        if ($userId === null) {
            return $query->whereRaw('0 = 1');
        }

        return $query->whereHas('account', fn (Builder $q) => $q->where('user_id', $userId));
    }
}
