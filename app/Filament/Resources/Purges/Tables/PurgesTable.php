<?php

namespace App\Filament\Resources\Purges\Tables;

use App\Models\Purge;
use App\Services\PurgeService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class PurgesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('post_id')
                    ->label('Tweet ID')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('text')
                    ->label('Tweet Text')
                    ->limit(50)
                    ->tooltip(fn (Purge $record): string => $record->text ?? '')
                    ->searchable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('posted_at')
                    ->label('Posted')
                    ->date()
                    ->sortable(),

                Tables\Columns\IconColumn::make('save')
                    ->label('Saved')
                    ->boolean()
                    ->trueIcon('heroicon-o-shield-check')
                    ->falseIcon('heroicon-o-trash')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->sortable(),

                Tables\Columns\TextColumn::make('account.username')
                    ->label('Account')
                    ->default('Default')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Purged' => 'success',
                        'Requested' => 'warning',
                        'Saved' => 'info',
                        'Pending' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderBy('purged_at', $direction)
                            ->orderBy('requested_at', $direction);
                    }),

                Tables\Columns\TextColumn::make('requested_at')
                    ->label('Requested')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('purged_at')
                    ->label('Purged')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Imported')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'requested' => 'Requested',
                        'purged' => 'Purged',
                        'saved' => 'Saved',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'pending' => $query->where('save', false)
                                ->whereNull('requested_at'),
                            'requested' => $query->whereNotNull('requested_at')
                                ->whereNull('purged_at'),
                            'purged' => $query->whereNotNull('purged_at'),
                            'saved' => $query->where('save', true),
                            default => $query,
                        };
                    }),

                Tables\Filters\TernaryFilter::make('save')
                    ->label('Saved/Protected')
                    ->placeholder('All tweets')
                    ->trueLabel('Saved only')
                    ->falseLabel('Not saved'),

                Tables\Filters\SelectFilter::make('account_id')
                    ->relationship(
                        'account',
                        'username',
                        fn (Builder $query) => $query->where('user_id', Auth::id())
                    )
                    ->label('Account'),
            ])
            ->recordActions([
                Action::make('toggle_save')
                    ->label(fn (Purge $record): string => $record->save ? 'Unprotect' : 'Protect')
                    ->icon(fn (Purge $record): string => $record->save ? 'heroicon-o-trash' : 'heroicon-o-shield-check')
                    ->color(fn (Purge $record): string => $record->save ? 'danger' : 'success')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Purge $record): string => $record->save ? 'Unprotect Tweet?' : 'Protect Tweet?')
                    ->modalDescription(fn (Purge $record): string => $record->save
                            ? 'This tweet will become eligible for deletion again.'
                            : 'This tweet will be protected from deletion.'
                    )
                    ->action(function (Purge $record) {
                        $record->update(['save' => ! $record->save]);

                        Notification::make()
                            ->success()
                            ->title($record->save ? 'Tweet protected' : 'Tweet unprotected')
                            ->send();
                    }),
            ])
            ->toolbarActions([
                Action::make('view_stats')
                    ->label('Stats')
                    ->icon('heroicon-o-chart-bar')
                    ->color('info')
                    ->action(function () {
                        /** @var \App\Models\User $user */
                        $user = Auth::user();
                        // UI callers must scope stats; never display global counts.
                        $stats = app(PurgeService::class)->getStats($user);

                        Notification::make()
                            ->info()
                            ->title('Purge Statistics')
                            ->body(
                                "Total: {$stats['total']}\n".
                                "Pending: {$stats['pending']}\n".
                                "Requested: {$stats['requested']}\n".
                                "Purged: {$stats['purged']}\n".
                                "Saved: {$stats['saved']}"
                            )
                            ->persistent()
                            ->send();
                    }),
            ])
            ->defaultSort('posted_at', 'asc')
            ->emptyStateHeading('No tweets imported')
            ->emptyStateDescription('Import a CSV file to start managing tweet deletions.');
    }
}
