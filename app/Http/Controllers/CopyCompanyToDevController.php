<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CopyCompanyToDevController extends Controller
{
    public function __invoke(Company $company): JsonResponse
    {
        $authUser = request()->user();
        abort_unless($authUser && $authUser->is_super_admin, 403);

        if (!env('DB_DEV_DATABASE')) {
            return response()->json([
                'success' => false,
                'message' => 'DB_DEV_DATABASE is not configured. Please set it in .env.',
            ], 422);
        }

        $mainConnection = DB::connection(config('database.default'));
        $devConnection  = DB::connection('dev');
        $mainDatabase   = $mainConnection->getDatabaseName();
        $devDatabase    = $devConnection->getDatabaseName();

        if (!$devDatabase) {
            return response()->json([
                'success' => false,
                'message' => 'Dev database connection is missing a database name.',
            ], 422);
        }

        Log::info('CopyCompanyToDev started', [
            'company_id' => $company->id,
            'company_name' => $company->name_en ?? $company->name_ar,
            'main_database' => $mainDatabase,
            'dev_database' => $devDatabase,
            'requested_by' => $authUser->id,
        ]);

        try {
            $tableNames = collect(
                $mainConnection->table('information_schema.columns')
                    ->select('TABLE_NAME')
                    ->where('TABLE_SCHEMA', $mainDatabase)
                    ->where('COLUMN_NAME', 'company_id')
                    ->distinct()
                    ->orderBy('TABLE_NAME')
                    ->pluck('TABLE_NAME')
                    ->all()
            );

            $copiedTables = 0;
            $totalInserted = 0;

            $devConnection->transaction(function () use (
                $company,
                $mainConnection,
                $devConnection,
                $mainDatabase,
                $devDatabase,
                $tableNames,
                &$copiedTables,
                &$totalInserted
            ) {
                $devConnection->statement('SET FOREIGN_KEY_CHECKS=0');

                try {
                    $this->copyCompanyRow($company, $mainConnection, $devConnection, $devDatabase);

                    foreach ($tableNames as $tableName) {
                        if ($tableName === 'companies') {
                            continue;
                        }

                        if (!$this->tableExists($devConnection, $devDatabase, $tableName)) {
                            Log::warning('CopyCompanyToDev skipped table missing in dev database', [
                                'company_id' => $company->id,
                                'table' => $tableName,
                            ]);
                            continue;
                        }

                        if (!$this->tableHasIdColumn($mainConnection, $mainDatabase, $tableName)) {
                            Log::warning('CopyCompanyToDev skipped table without id column for chunkById', [
                                'company_id' => $company->id,
                                'table' => $tableName,
                            ]);
                            continue;
                        }

                        $deleted = $devConnection->table($tableName)
                            ->where('company_id', $company->id)
                            ->delete();

                        $generatedColumns = $this->generatedColumns($devConnection, $devDatabase, $tableName);

                        $inserted = 0;
                        $mainConnection->table($tableName)
                            ->where('company_id', $company->id)
                            ->orderBy('id')
                            ->chunkById(500, function ($rows) use ($devConnection, $tableName, $generatedColumns, &$inserted) {
                                $payload = $rows->map(function ($row) {
                                    return (array) $row;
                                })->map(function (array $row) use ($generatedColumns) {
                                    foreach ($generatedColumns as $generatedColumn) {
                                        unset($row[$generatedColumn]);
                                    }
                                    return $row;
                                })->all();

                                if (!empty($payload)) {
                                    $devConnection->table($tableName)->insert($payload);
                                    $inserted += count($payload);
                                }
                            });

                        $copiedTables++;
                        $totalInserted += $inserted;

                        Log::info('CopyCompanyToDev table copied', [
                            'company_id' => $company->id,
                            'table' => $tableName,
                            'deleted_rows' => $deleted,
                            'inserted_rows' => $inserted,
                            'generated_columns_ignored' => $generatedColumns,
                        ]);
                    }
                } finally {
                    $devConnection->statement('SET FOREIGN_KEY_CHECKS=1');
                }
            });

            Log::info('CopyCompanyToDev completed', [
                'company_id' => $company->id,
                'tables_copied' => $copiedTables,
                'total_inserted_rows' => $totalInserted,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Company data copied to dev successfully.',
            ]);
        } catch (Throwable $e) {
            Log::error('CopyCompanyToDev failed', [
                'company_id' => $company->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to copy company data to dev database.',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    private function copyCompanyRow(Company $company, $mainConnection, $devConnection, string $devDatabase): void
    {
        if (!$this->tableExists($devConnection, $devDatabase, 'companies')) {
            Log::warning('CopyCompanyToDev skipped companies table because it does not exist in dev database', [
                'company_id' => $company->id,
            ]);
            return;
        }

        $companyRow = $mainConnection->table('companies')
            ->where('id', $company->id)
            ->first();

        if (!$companyRow) {
            throw new \RuntimeException("Company #{$company->id} was not found on main database.");
        }

        $generatedColumns = $this->generatedColumns($devConnection, $devDatabase, 'companies');
        $payload = (array) $companyRow;
        foreach ($generatedColumns as $generatedColumn) {
            unset($payload[$generatedColumn]);
        }

        $deleted = $devConnection->table('companies')->where('id', $company->id)->delete();
        $devConnection->table('companies')->insert($payload);

        Log::info('CopyCompanyToDev companies row copied', [
            'company_id' => $company->id,
            'deleted_rows' => $deleted,
            'inserted_rows' => 1,
            'generated_columns_ignored' => $generatedColumns,
        ]);
    }

    private function tableExists($connection, string $database, string $table): bool
    {
        return $connection->table('information_schema.tables')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', $table)
            ->exists();
    }

    private function tableHasIdColumn($connection, string $database, string $table): bool
    {
        return $connection->table('information_schema.columns')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', 'id')
            ->exists();
    }

    private function generatedColumns($connection, string $database, string $table): array
    {
        return $connection->table('information_schema.columns')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', $table)
            ->where(function ($q) {
                $q->where('EXTRA', 'like', '%GENERATED%')
                    ->orWhereRaw("COALESCE(GENERATION_EXPRESSION, '') <> ''");
            })
            ->pluck('COLUMN_NAME')
            ->values()
            ->all();
    }
}

