<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Resources\Profiles\Schemas\ProfileForm;
use App\Models\Profile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

class ProfileExcelImportService
{
    private const string IMPORT_DIRECTORY = 'temp-excel-imports';

    /**
     * Procesa un archivo Excel (.xlsx / .xls / .csv) almacenado temporalmente
     * en temp-excel-imports y registra o actualiza los perfiles.
     *
     * @return array{imported: int, updated: int, errors: list<string>}
     */
    public function import(string $filePath): array
    {
        $realPath = $this->resolveAuthorizedTempFilePath($filePath);

        if ($realPath === null) {
            return [
                'imported' => 0,
                'updated' => 0,
                'errors' => ['El archivo de importación no es válido o no se encuentra en el almacenamiento temporal.'],
            ];
        }

        try {
            try {
                $spreadsheet = IOFactory::load($realPath);
                $sheet = $spreadsheet->getActiveSheet();
                $rows = $sheet->toArray();
            } catch (Throwable $e) {
                return [
                    'imported' => 0,
                    'updated' => 0,
                    'errors' => ['No se pudo leer el archivo Excel: '.$e->getMessage()],
                ];
            }

            if (count($rows) < 2) {
                return [
                    'imported' => 0,
                    'updated' => 0,
                    'errors' => ['El archivo no contiene filas de datos para procesar.'],
                ];
            }

            // Buscar fila de encabezados
            $headerIndex = null;
            $headers = [];

            foreach ($rows as $index => $row) {
                $rowUpper = array_map(fn ($cell) => mb_strtoupper(trim((string) $cell), 'UTF-8'), $row);

                if (in_array('CÓDIGO DE ESTUDIANTE', $rowUpper, true) || in_array('CODIGO DE ESTUDIANTE', $rowUpper, true) || in_array('AP. PATERNO', $rowUpper, true) || in_array('NOMBRES', $rowUpper, true)) {
                    $headerIndex = $index;
                    $headers = $rowUpper;
                    break;
                }
            }

            if ($headerIndex === null) {
                $headerIndex = 0;
                $headers = array_map(fn ($cell) => mb_strtoupper(trim((string) $cell), 'UTF-8'), $rows[0]);
            }

            $colMap = [
                'code' => $this->findColumnIndex($headers, ['CÓDIGO DE ESTUDIANTE', 'CODIGO DE ESTUDIANTE', 'CODIGO', 'CÓDIGO', 'DNI']),
                'student_type' => $this->findColumnIndex($headers, ['TIPO DE ALUMNO', 'TIPO ALUMNO']),
                'user_type' => $this->findColumnIndex($headers, ['TIPO DE USUARIO', 'TIPO USUARIO']),
                'last_name' => $this->findColumnIndex($headers, ['AP. PATERNO', 'APELLIDO PATERNO', 'PATERNO']),
                'maternal_last_name' => $this->findColumnIndex($headers, ['AP. MATERNO', 'APELLIDO MATERNO', 'MATERNO']),
                'first_name' => $this->findColumnIndex($headers, ['NOMBRES', 'NOMBRE']),
                'program' => $this->findColumnIndex($headers, ['ESPECIALIDAD', 'PROGRAMA', 'CARRERA']),
                'modality' => $this->findColumnIndex($headers, ['MODALIDAD']),
                'shift' => $this->findColumnIndex($headers, ['TURNO']),
                'cycle' => $this->findColumnIndex($headers, ['CICLO']),
            ];

            if ($colMap['code'] === null || $colMap['first_name'] === null || $colMap['last_name'] === null) {
                return [
                    'imported' => 0,
                    'updated' => 0,
                    'errors' => ['El archivo Excel no contiene las columnas mínimas obligatorias (Código, Nombres y Apellido Paterno).'],
                ];
            }

            $imported = 0;
            $updated = 0;
            $errors = [];

            for ($i = $headerIndex + 1; $i < count($rows); $i++) {
                $row = $rows[$i];

                $rawCode = trim((string) ($row[$colMap['code']] ?? ''));

                if ($rawCode === '' || str_contains(mb_strtolower($rawCode, 'UTF-8'), 'no codigo')) {
                    continue;
                }

                if (mb_strlen($rawCode, 'UTF-8') > 50) {
                    $errors[] = 'Fila '.($i + 1).': el código supera el máximo de 50 caracteres.';

                    continue;
                }

                $code = $rawCode;
                $firstName = trim((string) ($row[$colMap['first_name']] ?? ''));
                $lastName = trim((string) ($row[$colMap['last_name']] ?? ''));
                $maternalLastName = $colMap['maternal_last_name'] !== null ? trim((string) ($row[$colMap['maternal_last_name']] ?? '')) : null;
                $studentType = $colMap['student_type'] !== null ? trim((string) ($row[$colMap['student_type']] ?? '')) : null;
                $rawUserType = $colMap['user_type'] !== null ? trim((string) ($row[$colMap['user_type']] ?? '')) : null;
                $rawProgram = $colMap['program'] !== null ? trim((string) ($row[$colMap['program']] ?? '')) : null;
                $rawModality = $colMap['modality'] !== null ? trim((string) ($row[$colMap['modality']] ?? '')) : null;
                $rawShift = $colMap['shift'] !== null ? trim((string) ($row[$colMap['shift']] ?? '')) : null;
                $rawCycle = $colMap['cycle'] !== null ? trim((string) ($row[$colMap['cycle']] ?? '')) : null;

                if ($firstName === '' && $lastName === '') {
                    continue;
                }

                $userType = $this->normalizeUserType($rawUserType);

                if (filled($rawUserType) && $userType === null) {
                    $errors[] = 'Fila '.($i + 1)." (Código {$code}): tipo de usuario inválido ({$rawUserType}).";

                    continue;
                }

                $program = $this->normalizeProgram($rawProgram);
                $modality = $this->normalizeModality($rawModality);
                $shift = $this->normalizeShift($rawShift);
                $cycle = $this->normalizeCycle($rawCycle);

                try {
                    /** @var Profile|null $profile */
                    $profile = Profile::query()->where('student_code', $code)->first();

                    if ($profile !== null) {
                        $resolvedUserType = $userType ?? $profile->user_type ?? Profile::USER_TYPE_STUDENT;

                        $updatePayload = ProfileForm::normalizeAcademicData([
                            'first_name' => $firstName ?: $profile->first_name,
                            'last_name' => $lastName ?: $profile->last_name,
                            'maternal_last_name' => $maternalLastName ?: $profile->maternal_last_name,
                            'student_type' => $studentType ?: $profile->student_type,
                            'program' => $program ?: $profile->program,
                            'modality' => $modality ?: $profile->modality,
                            'shift' => $shift ?: $profile->shift,
                            'cycle' => $cycle ?: $profile->cycle,
                            'user_type' => $resolvedUserType,
                        ]);

                        $profile->update($updatePayload);
                        $updated++;
                    } else {
                        $resolvedUserType = $userType ?? Profile::USER_TYPE_STUDENT;

                        $createPayload = ProfileForm::normalizeAcademicData([
                            'student_code' => $code,
                            'first_name' => $firstName,
                            'last_name' => $lastName,
                            'maternal_last_name' => $maternalLastName,
                            'student_type' => $studentType,
                            'program' => $program,
                            'modality' => $modality,
                            'shift' => $shift,
                            'cycle' => $cycle,
                            'user_type' => $resolvedUserType,
                        ]);

                        Profile::query()->create($createPayload);
                        $imported++;
                    }
                } catch (Throwable $e) {
                    $errors[] = 'Fila '.($i + 1)." (Código {$code}): ".$e->getMessage();
                }
            }

            return [
                'imported' => $imported,
                'updated' => $updated,
                'errors' => $errors,
            ];
        } finally {
            if (is_file($realPath)) {
                @unlink($realPath);
            }
        }
    }

    /**
     * Valida que la ruta pertenezca estrictamente al directorio temporal de importaciones
     * en el disco local y exista como archivo regular.
     */
    private function resolveAuthorizedTempFilePath(string $filePath): ?string
    {
        $normalizedPath = ltrim(str_replace('\\', '/', trim($filePath)), '/');

        if ($normalizedPath === '' || ! str_starts_with($normalizedPath, self::IMPORT_DIRECTORY.'/')) {
            return null;
        }

        $baseDir = realpath(Storage::disk('local')->path(self::IMPORT_DIRECTORY));

        if ($baseDir === false) {
            return null;
        }

        $candidate = realpath(Storage::disk('local')->path($normalizedPath));

        if ($candidate === false || ! is_file($candidate)) {
            return null;
        }

        $basePrefix = rtrim($baseDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if (! str_starts_with($candidate, $basePrefix)) {
            return null;
        }

        return $candidate;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<string>  $candidates
     */
    private function findColumnIndex(array $headers, array $candidates): ?int
    {
        foreach ($candidates as $candidate) {
            $key = array_search($candidate, $headers, true);
            if ($key !== false) {
                return (int) $key;
            }
        }

        foreach ($headers as $index => $header) {
            foreach ($candidates as $candidate) {
                if (str_contains($header, $candidate)) {
                    return $index;
                }
            }
        }

        return null;
    }

    private function normalizeUserType(?string $userType): ?string
    {
        if (blank($userType)) {
            return null;
        }

        $normalized = mb_strtolower(trim($userType), 'UTF-8');

        foreach (Profile::USER_TYPES as $allowedType) {
            if (mb_strtolower($allowedType, 'UTF-8') === $normalized) {
                return $allowedType;
            }
        }

        return null;
    }

    private function normalizeProgram(?string $program): ?string
    {
        if (blank($program)) {
            return null;
        }

        $upper = mb_strtoupper(trim($program), 'UTF-8');

        if (str_contains($upper, 'SISTEMA')) {
            return 'Ingeniería de Sistemas e Informática';
        }

        if (str_contains($upper, 'ADMINISTRA')) {
            return 'Administración';
        }

        if (str_contains($upper, 'CONTABILI')) {
            return 'Contabilidad';
        }

        return trim($program);
    }

    private function normalizeModality(?string $modality): ?string
    {
        if (blank($modality)) {
            return null;
        }

        $upper = mb_strtoupper(trim($modality), 'UTF-8');

        if (str_contains($upper, 'SEMIPRESENCIAL')) {
            return 'SEMIPRESENCIAL';
        }

        if (str_contains($upper, 'PRESENCIAL')) {
            return 'PRESENCIAL';
        }

        if (str_contains($upper, 'DISTANCIA')) {
            return 'A DISTANCIA';
        }

        return $upper;
    }

    private function normalizeShift(?string $shift): ?string
    {
        if (blank($shift)) {
            return null;
        }

        return mb_strtoupper(trim($shift), 'UTF-8');
    }

    private function normalizeCycle(mixed $cycle): ?int
    {
        if (blank($cycle)) {
            return null;
        }

        $cleaned = trim((string) $cycle);

        if (is_numeric($cleaned)) {
            $int = (int) $cleaned;

            return ($int >= 1 && $int <= 10) ? $int : null;
        }

        $romanMap = [
            'I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4, 'V' => 5,
            'VI' => 6, 'VII' => 7, 'VIII' => 8, 'IX' => 9, 'X' => 10,
        ];

        $upper = mb_strtoupper($cleaned, 'UTF-8');
        $upper = str_replace('CICLO', '', $upper);
        $upper = trim($upper);

        return $romanMap[$upper] ?? null;
    }
}
