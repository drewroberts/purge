<?php

namespace App\Filament\Resources\Purges\Pages;

use App\Filament\Resources\Purges\PurgeResource;
use App\Models\Purge;
use App\Services\PurgeService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;

/**
 * @property Purge $record
 */
class ViewPurge extends ViewRecord
{
    protected static string $resource = PurgeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('toggleSave')
                ->label(fn () => $this->record->save ? 'Mark as Unsaved' : 'Mark as Saved')
                ->icon(fn () => $this->record->save ? 'heroicon-o-bookmark-slash' : 'heroicon-o-bookmark')
                ->color(fn () => $this->record->save ? 'warning' : 'primary')
                ->requiresConfirmation()
                ->modalHeading(fn () => $this->record->save ? 'Mark Tweet as Unsaved?' : 'Mark Tweet as Saved?')
                ->modalDescription(fn () => $this->record->save
                    ? 'This will allow the tweet to be deleted during the next purge cycle.'
                    : 'This will protect the tweet from being deleted.'
                )
                ->action(function () {
                    $this->record->update(['save' => ! $this->record->save]);

                    Notification::make()
                        ->success()
                        ->title($this->record->save ? 'Tweet marked as saved' : 'Tweet marked as unsaved')
                        ->send();
                }),

            Actions\Action::make('manualPurge')
                ->label('Delete Tweet Now')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Delete This Tweet from Twitter?')
                ->modalDescription('This will immediately delete the tweet from Twitter. This action cannot be undone.')
                ->hidden(fn () => $this->record->save || $this->record->purged_at)
                ->action(function (PurgeService $purgeService) {
                    // Refresh the record to get latest state
                    $this->record->refresh();

                    /** @var \App\Models\User $user */
                    $user = Auth::user();

                    // Block acting on a purge bound to someone else's account.
                    if ($this->record->account && $this->record->account->user_id !== $user->id) {
                        throw new \RuntimeException(
                            'This purge is bound to an account you do not own.'
                        );
                    }

                    $account = $this->record->account ?? $purgeService->getDefaultAccount($user);

                    if (! $account) {
                        throw new \RuntimeException(
                            'No Twitter account available for deletion. '.
                            "Tweet ID: {$this->record->post_id}. ".
                            'Please ensure a Twitter account is connected and active.'
                        );
                    }

                    $success = $purgeService->processPurge($this->record, $user);

                    if ($success) {
                        Notification::make()
                            ->success()
                            ->title('Tweet deleted successfully')
                            ->body('The tweet has been deleted from Twitter.')
                            ->send();
                    } else {
                        // Refresh to get updated timestamps
                        $this->record->refresh();

                        throw new \RuntimeException(
                            'Failed to delete tweet from Twitter API. '.
                            "\n\n**Tweet Details:**\n".
                            "- Tweet ID: `{$this->record->post_id}`\n".
                            "- Status: `{$this->record->status}`\n".
                            '- Save Flag: `'.($this->record->save ? 'true' : 'false')."`\n".
                            '- Requested At: `'.($this->record->requested_at?->toDateTimeString() ?? 'null')."`\n".
                            '- Purged At: `'.($this->record->purged_at?->toDateTimeString() ?? 'null')."`\n".
                            "\n**Account Details:**\n".
                            '- Username: `'.($account->username ?? 'unknown')."`\n".
                            '- Account ID: `'.($account->id ?? 'unknown')."`\n".
                            '- Active: `'.($account->is_active ? 'true' : 'false')."`\n".
                            '- Has Access Token: `'.($account->access_token ? 'yes' : 'no')."`\n".
                            '- Has Secret: `'.($account->access_token_secret ? 'yes' : 'no')."`\n".
                            "\n**Possible Causes:**\n".
                            "- Tweet may have already been deleted\n".
                            "- Invalid or expired Twitter API credentials\n".
                            "- Twitter API rate limit exceeded\n".
                            "- Network or API connectivity issues\n".
                            "\n**Next Steps:**\n".
                            'Check `storage/logs/laravel.log` for detailed error messages from the Twitter API.'
                        );
                    }
                }),

            Actions\EditAction::make(),
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [
            ViewPurge\Widgets\VisitTweetWidget::class,
        ];
    }
}
