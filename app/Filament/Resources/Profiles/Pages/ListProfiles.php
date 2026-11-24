<?php

declare(strict_types=1);

namespace App\Filament\Resources\Profiles\Pages;

use App\Filament\Resources\Profiles\ProfileResource;
use App\Services\ProfileExcelImportService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use pxlrbt\FilamentExcel\Actions\Pages\ExportAction;
use pxlrbt\FilamentExcel\Columns\Column;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

class ListProfiles extends ListRecords
{
    protected static string $resource = ProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import_excel')
                ->label('Importar Excel')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('success')
                ->modalHeading('Importar Estudiantes desde Excel')
                ->modalDescription('Suba un archivo Excel (.xlsx, .xls) con las columnas: Código de estudiante, Tipo de alumno, Ap. Paterno, Ap. Materno, Nombres, Especialidad, Modalidad, Turno y Ciclo.')
                ->modalSubmitActionLabel('Iniciar importación')
                ->form([
                    FileUpload::make('excel_file')
                        ->label('Archivo Excel')
                        ->helperText('Formatos aceptados: .xlsx, .xls, .csv')
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/vnd.ms-excel',
                            'text/csv',
                        ])
                        ->disk('local')
                        ->directory('temp-excel-imports')
                        ->required()
                        ->storeFiles(),
                ])
                ->action(function (array $data, ProfileExcelImportService $importService): void {
                    $filePath = $data['excel_file'] ?? null;

                    if (blank($filePath)) {
                        Notification::make()
                            ->danger()
                            ->title('Archivo requerido')
                            ->body('Debe seleccionar un archivo válido para importar.')
                            ->send();

                        return;
                    }

                    $result = $importService->import((string) $filePath);

                    if ($result['errors'] !== [] && $result['imported'] === 0 && $result['updated'] === 0) {
                        Notification::make()
                            ->danger()
                            ->title('Error en la importación')
                            ->body(implode(' ', array_slice($result['errors'], 0, 3)))
                            ->persistent()
                            ->send();

                        return;
                    }

                    $message = "Se crearon {$result['imported']} nuevo(s) estudiante(s) y se actualizaron {$result['updated']}.";

                    Notification::make()
                        ->success()
                        ->title('Importación finalizada con éxito')
                        ->body($message)
                        ->send();
                }),

            ExportAction::make()
                ->label('Exportar Excel')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->exports([
                    ExcelExport::make('perfiles')
                        ->withFilename(fn () => 'estudiantes-uch-'.date('Y-m-d'))
                        ->withColumns([
                            Column::make('student_code')->heading('Código de estudiante'),
                            Column::make('student_type')->heading('Tipo de alumno'),
                            Column::make('last_name')->heading('Ap. Paterno'),
                            Column::make('maternal_last_name')->heading('Ap. Materno'),
                            Column::make('first_name')->heading('Nombres'),
                            Column::make('program')->heading('Especialidad'),
                            Column::make('modality')->heading('Modalidad'),
                            Column::make('shift')->heading('Turno'),
                            Column::make('cycle')->heading('Ciclo'),
                            Column::make('user_type')->heading('Tipo de usuario'),
                            Column::make('phone')->heading('Celular'),
                        ]),
                ]),

            CreateAction::make(),
        ];
    }
}
