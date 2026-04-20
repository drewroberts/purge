<?php

namespace App\Filament\Resources\Purges\Pages;

use App\Filament\Resources\Purges\PurgeResource;
use App\Models\Purge;
use App\Services\PurgeService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class ListPurges extends ListRecords
{
    protected static string $resource = PurgeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import_csv')
                ->label('Import CSV')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('primary')
                ->fillForm([])
                ->schema([
                    FileUpload::make('csv_file')
                        ->label('CSV File')
                        ->acceptedFileTypes(['text/csv', 'application/csv', 'text/plain'])
                        ->maxSize(10240) // 10MB
                        ->required()
                        ->helperText('Upload a CSV file with columns: post_id, posted_at, text'),
                ])
                ->action(function (array $data) {
                    /** @var \App\Models\User $user */
                    $user = Auth::user();

                    // Every imported purge must be bound to the importing
                    // user's default active account. No orphan purges.
                    $defaultAccount = app(PurgeService::class)->getDefaultAccount($user);

                    if (! $defaultAccount) {
                        Notification::make()
                            ->danger()
                            ->title('No active Twitter account')
                            ->body('Connect an active Twitter account before importing tweets.')
                            ->send();

                        return;
                    }

                    $filePath = Storage::disk('local')->path($data['csv_file']);

                    if (! file_exists($filePath)) {
                        Notification::make()
                            ->danger()
                            ->title('File not found')
                            ->body('Path: '.$filePath)
                            ->send();

                        return;
                    }

                    // Read and validate CSV
                    $file = fopen($filePath, 'r');

                    if ($file === false) {
                        Notification::make()
                            ->danger()
                            ->title('Cannot open file')
                            ->send();

                        return;
                    }

                    // Read headers with proper CSV parsing (comma separator, double-quote enclosure)
                    $headers = fgetcsv($file, 0, ',', '"');

                    if ($headers === false) {
                        fclose($file);
                        Notification::make()
                            ->danger()
                            ->title('Invalid CSV file')
                            ->body('Cannot read CSV headers')
                            ->send();

                        return;
                    }

                    // Trim whitespace from headers and convert to lowercase for comparison
                    $headers = array_map(fn ($h) => is_string($h) ? trim($h) : '', $headers);
                    $headersLower = array_map('strtolower', $headers);

                    // Validate headers (case-insensitive)
                    $requiredHeaders = ['post_id', 'posted_at', 'text'];
                    $missingHeaders = [];

                    foreach ($requiredHeaders as $required) {
                        if (! in_array(strtolower($required), $headersLower)) {
                            $missingHeaders[] = $required;
                        }
                    }

                    if (! empty($missingHeaders)) {
                        fclose($file);
                        Notification::make()
                            ->danger()
                            ->title('Invalid CSV format')
                            ->body('Missing columns: '.implode(', ', $missingHeaders)."\nFound: ".implode(', ', $headers))
                            ->send();

                        return;
                    }

                    $imported = 0;
                    $skipped = 0;
                    $errors = [];
                    $rowNumber = 1; // Track row number for error messages

                    while (($row = fgetcsv($file, 0, ',', '"')) !== false) {
                        $rowNumber++;

                        // Skip empty rows
                        if (empty(array_filter($row))) {
                            continue;
                        }

                        // Check if row has same number of columns as headers
                        if (count($row) !== count($headers)) {
                            $skipped++;
                            if (count($errors) < 5) {
                                $errors[] = "Row {$rowNumber} has ".count($row).' columns, expected '.count($headers);
                            }

                            continue;
                        }

                        // Combine with original headers (preserving case)
                        $rowData = array_combine($headers, $row);

                        // Create case-insensitive lookup
                        $rowDataLower = array_change_key_case($rowData, CASE_LOWER);

                        // Validate required fields
                        if (! isset($rowDataLower['post_id']) || empty($rowDataLower['post_id'])) {
                            $skipped++;

                            continue;
                        }

                        try {
                            // Check if already exists (will fail due to unique constraint)
                            $exists = Purge::where('post_id', $rowDataLower['post_id'])->exists();

                            if ($exists) {
                                $skipped++;

                                continue;
                            }

                            Purge::create([
                                'post_id' => $rowDataLower['post_id'],
                                'posted_at' => ! empty($rowDataLower['posted_at']) ? $rowDataLower['posted_at'] : null,
                                'text' => $rowDataLower['text'] ?? null,
                                'save' => false,
                                'account_id' => $defaultAccount->id,
                            ]);

                            $imported++;
                        } catch (\Exception $e) {
                            $skipped++;
                            if (count($errors) < 5) {
                                $errors[] = "Row {$rowDataLower['post_id']}: ".$e->getMessage();
                            }
                        }
                    }

                    fclose($file);

                    // Clean up uploaded file
                    Storage::disk('local')->delete($data['csv_file']);

                    // Show notification
                    $notification = Notification::make()
                        ->title('CSV Import Complete')
                        ->body("Imported: {$imported} | Skipped: {$skipped}");

                    if (count($errors) > 0) {
                        $notification->warning()
                            ->body("Imported: {$imported} | Skipped: {$skipped}\n\nSome errors occurred:\n".implode("\n", $errors));
                    } else {
                        $notification->success();
                    }

                    $notification->send();
                }),
        ];
    }
}
