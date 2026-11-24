<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Equipment;
use App\Models\Profile;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportHistoryExcelService
{
    /**
     * Exporta el historial de préstamos de un estudiante en un archivo XLSX descargable.
     */
    public function exportStudentHistory(Profile $profile): StreamedResponse
    {
        $profile->loadMissing(['borrowings.equipment', 'borrowings.additionalEquipment', 'borrowings.registeredBy']);
        $borrowings = $profile->borrowings()->orderByDesc('borrowing_date')->get();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Historial de Préstamos');

        // Estilos base
        $sheet->setShowGridLines(true);

        // Título Principal
        $sheet->mergeCells('A1:I1');
        $sheet->setCellValue('A1', 'UNIVERSIDAD DE CIENCIAS Y HUMANIDADES - FICHA DE PRÉSTAMOS');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->setColor(new Color('FFFFFFFF'));
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0A2D5F');
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(30);

        // Bloque de datos del estudiante
        $hasActive = $profile->hasActiveBorrowing() ? 'SÍ (Préstamo en curso)' : 'NO (Sin deudas)';

        $infoData = [
            ['Código de estudiante:', $profile->student_code, 'Carrera / Especialidad:', $profile->program ?? 'No especificado'],
            ['Estudiante:', $profile->full_name, 'Ciclo académico:', $profile->cycle ? 'Ciclo '.$profile->cycle : 'No registrado'],
            ['Tipo de usuario:', $profile->user_type ?? 'Estudiante', 'Modalidad / Turno:', trim(($profile->modality ?? '').' / '.($profile->shift ?? ''), ' /') ?: 'No registrado'],
            ['Total préstamos:', (string) $borrowings->count(), '¿Tiene préstamo activo?:', $hasActive],
        ];

        $currentInfoRow = 3;
        foreach ($infoData as $row) {
            $sheet->setCellValue('A'.$currentInfoRow, $row[0]);
            $sheet->setCellValue('B'.$currentInfoRow, $row[1]);
            $sheet->setCellValue('D'.$currentInfoRow, $row[2]);
            $sheet->setCellValue('E'.$currentInfoRow, $row[3]);

            $sheet->getStyle('A'.$currentInfoRow)->getFont()->setBold(true);
            $sheet->getStyle('D'.$currentInfoRow)->getFont()->setBold(true);
            $currentInfoRow++;
        }

        // Encabezados de la tabla de préstamos
        $tableHeaderRow = 8;
        $headers = [
            'ID',
            'Equipo Principal',
            'Cód. Patrimonial',
            'Dispositivos Adicionales',
            'Fecha Préstamo',
            'Fecha Límite',
            'Fecha Devolución',
            'Estado',
            'Observaciones / Incidencias',
        ];

        $colLetter = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($colLetter.$tableHeaderRow, $header);
            $colLetter++;
        }

        $sheet->getStyle("A{$tableHeaderRow}:I{$tableHeaderRow}")->getFont()->setBold(true)->setColor(new Color('FFFFFFFF'));
        $sheet->getStyle("A{$tableHeaderRow}:I{$tableHeaderRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0A2D5F');
        $sheet->getStyle("A{$tableHeaderRow}:I{$tableHeaderRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension($tableHeaderRow)->setRowHeight(24);

        // Llenar datos de los préstamos
        $dataRow = $tableHeaderRow + 1;

        if ($borrowings->isEmpty()) {
            $sheet->mergeCells("A{$dataRow}:I{$dataRow}");
            $sheet->setCellValue("A{$dataRow}", 'No registra préstamos en el sistema.');
            $sheet->getStyle("A{$dataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $dataRow++;
        } else {
            foreach ($borrowings as $b) {
                $mainEquip = $b->equipment ? "{$b->equipment->type} {$b->equipment->brand} {$b->equipment->model}" : 'Sin equipo principal';
                $patrimonial = $b->equipment?->patrimonial_code ?? '-';

                $additionals = $b->additionalEquipment->map(fn ($e) => "[{$e->patrimonial_code}] {$e->brand} {$e->model}")->implode(', ');
                if ($additionals === '') {
                    $additionals = '-';
                }

                $borrowingDate = $b->borrowing_date ? Carbon::parse($b->borrowing_date)->format('d/m/Y H:i') : '-';
                $dueDate = $b->due_date ? Carbon::parse($b->due_date)->format('d/m/Y H:i') : '-';
                $returnDate = $b->return_date ? Carbon::parse($b->return_date)->format('d/m/Y H:i') : 'Pendiente';

                $sheet->setCellValue('A'.$dataRow, (string) $b->getKey());
                $sheet->setCellValue('B'.$dataRow, $mainEquip);
                $sheet->setCellValue('C'.$dataRow, $patrimonial);
                $sheet->setCellValue('D'.$dataRow, $additionals);
                $sheet->setCellValue('E'.$dataRow, $borrowingDate);
                $sheet->setCellValue('F'.$dataRow, $dueDate);
                $sheet->setCellValue('G'.$dataRow, $returnDate);
                $sheet->setCellValue('H'.$dataRow, $b->status);
                $sheet->setCellValue('I'.$dataRow, $b->observations ?? '-');

                // Alinear fechas e ID
                $sheet->getStyle("A{$dataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle("C{$dataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle("E{$dataRow}:H{$dataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                // Estilo condicional según estado
                $statusColor = match ($b->status) {
                    'Devuelto' => 'FF16A34A', // Verde
                    'En curso' => 'FF2563EB', // Azul
                    'Vencido' => 'FFDC2626',  // Rojo
                    default => 'FF6B7280',
                };
                $sheet->getStyle("H{$dataRow}")->getFont()->setBold(true)->setColor(new Color($statusColor));

                $dataRow++;
            }
        }

        // Borde en tabla
        $lastRow = $dataRow - 1;
        $sheet->getStyle("A{$tableHeaderRow}:I{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('FFD1D5DB'));

        // Auto-ajustar anchos de columnas
        foreach (range('A', 'I') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $safeCode = preg_replace('/[^A-Za-z0-9_-]/', '', $profile->student_code ?? 'estudiante');
        $filename = "historial_estudiante_{$safeCode}_".date('Ymd_His').'.xlsx';

        return new StreamedResponse(function () use ($spreadsheet): void {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'max-age=0',
        ]);
    }

    /**
     * Exporta la trazabilidad de alumnos que usaron un equipo en un archivo XLSX descargable.
     */
    public function exportEquipmentHistory(Equipment $equipment): StreamedResponse
    {
        $equipment->loadMissing(['borrowings.profile', 'borrowings.registeredBy', 'additionalBorrowings.profile', 'additionalBorrowings.registeredBy']);

        // Combinar préstamos principales y adicionales
        $primaryBorrowings = $equipment->borrowings()->with(['profile', 'registeredBy'])->get();
        $additionalBorrowings = $equipment->additionalBorrowings()->with(['profile', 'registeredBy'])->get();

        $allBorrowings = $primaryBorrowings->concat($additionalBorrowings)->unique('id')->sortByDesc('borrowing_date');

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Historial de Uso');

        $sheet->setShowGridLines(true);

        // Título Principal
        $sheet->mergeCells('A1:J1');
        $sheet->setCellValue('A1', 'UNIVERSIDAD DE CIENCIAS Y HUMANIDADES - TRAZABILIDAD DE EQUIPO');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->setColor(new Color('FFFFFFFF'));
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0A2D5F');
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(30);

        // Bloque de datos del equipo
        $infoData = [
            ['Código Patrimonial:', $equipment->patrimonial_code, 'Tipo de Equipo:', $equipment->type],
            ['Marca y Modelo:', "{$equipment->brand} {$equipment->model}", 'Número de Serie:', $equipment->serial_number ?: 'Sin número de serie'],
            ['Estado Actual:', $equipment->status, 'Total de Préstamos:', (string) $allBorrowings->count()],
            ['Observaciones:', $equipment->observations ?: 'Sin observaciones técnicas', '', ''],
        ];

        $currentInfoRow = 3;
        foreach ($infoData as $row) {
            $sheet->setCellValue('A'.$currentInfoRow, $row[0]);
            $sheet->setCellValue('B'.$currentInfoRow, $row[1]);
            $sheet->setCellValue('D'.$currentInfoRow, $row[2]);
            $sheet->setCellValue('E'.$currentInfoRow, $row[3]);

            $sheet->getStyle('A'.$currentInfoRow)->getFont()->setBold(true);
            $sheet->getStyle('D'.$currentInfoRow)->getFont()->setBold(true);
            $currentInfoRow++;
        }

        // Encabezados de la tabla de alumnos
        $tableHeaderRow = 8;
        $headers = [
            'ID Préstamo',
            'Fecha Préstamo',
            'Fecha Devolución',
            'Duración de Uso',
            'Cód. Estudiante',
            'Estudiante / Solicitante',
            'Especialidad / Carrera',
            'Ciclo',
            'Estado Préstamo',
            'Observaciones / Daños',
        ];

        $colLetter = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($colLetter.$tableHeaderRow, $header);
            $colLetter++;
        }

        $sheet->getStyle("A{$tableHeaderRow}:J{$tableHeaderRow}")->getFont()->setBold(true)->setColor(new Color('FFFFFFFF'));
        $sheet->getStyle("A{$tableHeaderRow}:J{$tableHeaderRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0A2D5F');
        $sheet->getStyle("A{$tableHeaderRow}:J{$tableHeaderRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension($tableHeaderRow)->setRowHeight(24);

        $dataRow = $tableHeaderRow + 1;

        if ($allBorrowings->isEmpty()) {
            $sheet->mergeCells("A{$dataRow}:J{$dataRow}");
            $sheet->setCellValue("A{$dataRow}", 'Este equipo aún no ha sido prestado.');
            $sheet->getStyle("A{$dataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $dataRow++;
        } else {
            foreach ($allBorrowings as $b) {
                $profile = $b->profile;
                $studentCode = $profile?->student_code ?? $b->student_code;
                $studentName = $profile?->full_name ?? 'Sin nombre registrado';
                $program = $profile?->program ?? 'No especificado';
                $cycle = $profile?->cycle ? 'Ciclo '.$profile->cycle : '-';

                $borrowingDate = $b->borrowing_date ? Carbon::parse($b->borrowing_date) : null;
                $returnDate = $b->return_date ? Carbon::parse($b->return_date) : null;

                $durationText = '-';
                if ($borrowingDate !== null && $returnDate !== null) {
                    $diffInMinutes = (int) $borrowingDate->diffInMinutes($returnDate);
                    $hours = intdiv($diffInMinutes, 60);
                    $mins = $diffInMinutes % 60;
                    $durationText = "{$hours}h {$mins}m";
                } elseif ($borrowingDate !== null && $b->status === 'En curso') {
                    $durationText = 'En uso actual';
                }

                $sheet->setCellValue('A'.$dataRow, (string) $b->getKey());
                $sheet->setCellValue('B'.$dataRow, $borrowingDate ? $borrowingDate->format('d/m/Y H:i') : '-');
                $sheet->setCellValue('C'.$dataRow, $returnDate ? $returnDate->format('d/m/Y H:i') : 'Pendiente');
                $sheet->setCellValue('D'.$dataRow, $durationText);
                $sheet->setCellValue('E'.$dataRow, $studentCode);
                $sheet->setCellValue('F'.$dataRow, $studentName);
                $sheet->setCellValue('G'.$dataRow, $program);
                $sheet->setCellValue('H'.$dataRow, $cycle);
                $sheet->setCellValue('I'.$dataRow, $b->status);
                $sheet->setCellValue('J'.$dataRow, $b->observations ?? '-');

                $sheet->getStyle("A{$dataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle("B{$dataRow}:E{$dataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle("H{$dataRow}:I{$dataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                $statusColor = match ($b->status) {
                    'Devuelto' => 'FF16A34A',
                    'En curso' => 'FF2563EB',
                    'Vencido' => 'FFDC2626',
                    default => 'FF6B7280',
                };
                $sheet->getStyle("I{$dataRow}")->getFont()->setBold(true)->setColor(new Color($statusColor));

                $dataRow++;
            }
        }

        $lastRow = $dataRow - 1;
        $sheet->getStyle("A{$tableHeaderRow}:J{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('FFD1D5DB'));

        foreach (range('A', 'J') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $safePatrimonial = preg_replace('/[^A-Za-z0-9_-]/', '', $equipment->patrimonial_code);
        $filename = "historial_equipo_{$safePatrimonial}_".date('Ymd_His').'.xlsx';

        return new StreamedResponse(function () use ($spreadsheet): void {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'max-age=0',
        ]);
    }
}
