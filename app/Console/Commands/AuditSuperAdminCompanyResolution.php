<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

// ══════════════════════════════════════════════════════════════════
//  VERO — Super Admin Company Resolution Audit Command
//
//  Usage:
//    php artisan vero:audit-sa-company
//
//  What it does:
//    Scans every file in app/Http/Controllers/ and reports:
//      🔴 DANGER  — Company::value() used in a WRITE method (store/update)
//                   This silently assigns wrong company_id. Must be fixed.
//      🟡 WARNING — Company::value() used in a READ method (index/show/create)
//                   Silent fallback in reads is acceptable but worth knowing.
//      ✅ SAFE    — Uses abort_unless($companyId > 0) hard block on writes
//                   or uses resolveCompanyId($authUser, $request, true)
//
//  Place this file at:
//    app/Console/Commands/AuditSuperAdminCompanyResolution.php
//
//  Register (Laravel auto-discovers, but if not):
//    Add to $commands array in app/Console/Kernel.php
// ══════════════════════════════════════════════════════════════════

class AuditSuperAdminCompanyResolution extends Command
{
    protected $signature   = 'vero:audit-sa-company';
    protected $description = 'Audit all controllers for dangerous Super Admin company_id silent fallback in write operations';

    // Write methods — these MUST hard-block if no company selected
    private array $writeMethods = [
        'store', 'update', 'storeOverheadType', 'updateOverheadType',
        'storeInventoryPo', 'updateInventoryPo', 'batchStore',
        'confirm', 'post', 'cancel', 'dispose', 'settle',
        'recordTransaction', 'confirmPo', 'confirmReceipt',
        'confirmInventoryReceipt', 'toggleActive',
    ];

    // Read methods — silent fallback here is acceptable
    private array $readMethods = [
        'index', 'show', 'create', 'edit',
    ];

    // Dangerous patterns — silent fallback that can assign wrong company
    private array $dangerousPatterns = [
        'Company::value(',
        'Company::first()',
        "?: Company::value",
        '?: \App\Models\Company::value',
    ];

    // Safe patterns — hard block or explicit required=true
    private array $safePatterns = [
        "abort_unless(\$companyId > 0",
        "abort_unless((int)\$companyId > 0",
        'resolveCompanyId($authUser, $request, true)',
        "mustExist = true",
        "'Company required'",
        'company_required_for_super_admin',
    ];

    public function handle(): int
    {
        $controllerPath = app_path('Http/Controllers');
        $files          = glob($controllerPath . '/*.php');

        if (empty($files)) {
            $this->error('No controller files found at ' . $controllerPath);
            return 1;
        }

        $this->newLine();
        $this->line('╔══════════════════════════════════════════════════════════════╗');
        $this->line('║   VERO — Super Admin Company Resolution Audit                ║');
        $this->line('╚══════════════════════════════════════════════════════════════╝');
        $this->newLine();

        $dangerCount  = 0;
        $warningCount = 0;
        $safeCount    = 0;
        $report       = [];

        foreach (sort($files) ? $files : $files as $file) {
            $fileName = basename($file);

            // Skip base Controller
            if ($fileName === 'Controller.php') {
                continue;
            }

            $content = file_get_contents($file);
            $lines   = explode("\n", $content);

            $findings = $this->analyzeFile($fileName, $lines);

            if (!empty($findings)) {
                $report[$fileName] = $findings;
                foreach ($findings as $f) {
                    if ($f['severity'] === 'DANGER')  $dangerCount++;
                    if ($f['severity'] === 'WARNING') $warningCount++;
                    if ($f['severity'] === 'SAFE')    $safeCount++;
                }
            }
        }

        // ── Print results ─────────────────────────────────────────
        if (empty($report)) {
            $this->info('✅ No Company::value() patterns found in any controller.');
            return 0;
        }

        foreach ($report as $fileName => $findings) {
            $this->line("┌─ <fg=cyan>{$fileName}</>");

            foreach ($findings as $f) {
                $icon = match($f['severity']) {
                    'DANGER'  => '<fg=red>🔴 DANGER </>',
                    'WARNING' => '<fg=yellow>🟡 WARNING</>',
                    'SAFE'    => '<fg=green>✅ SAFE   </>',
                    default   => '   ?      ',
                };

                $this->line(
                    "│  {$icon}  Line {$f['line']}  [{$f['method']}]"
                );
                $this->line(
                    "│           " . trim($f['code'])
                );

                if ($f['severity'] === 'DANGER') {
                    $this->line(
                        "│           <fg=red>^ Silent fallback in WRITE method — can assign wrong company_id</>"
                    );
                    $this->line(
                        "│           <fg=yellow>  Fix: Replace with abort_unless(\$companyId > 0, 422, 'Company required')</>"
                    );
                }
            }

            $this->line("└" . str_repeat("─", 60));
            $this->newLine();
        }

        // ── Summary ───────────────────────────────────────────────
        $this->line('╔══════════════════════════════════════════════════════════════╗');
        $this->line('║   SUMMARY                                                    ║');
        $this->line('╠══════════════════════════════════════════════════════════════╣');
        $this->line("║  🔴 DANGER  (write methods with silent fallback): <fg=red>{$dangerCount}</>          ║");
        $this->line("║  🟡 WARNING (read methods with silent fallback):  <fg=yellow>{$warningCount}</>          ║");
        $this->line("║  ✅ SAFE    (hard-blocked write methods):          <fg=green>{$safeCount}</>          ║");
        $this->line('╚══════════════════════════════════════════════════════════════╝');
        $this->newLine();

        if ($dangerCount > 0) {
            $this->error("ACTION REQUIRED: {$dangerCount} write method(s) use silent company fallback.");
            $this->line('These can silently assign data to the wrong company when Super Admin');
            $this->line('saves without selecting a company. This is a data integrity issue.');
            $this->newLine();
            $this->line('Fix pattern for each DANGER line:');
            $this->line('  BEFORE:');
            $this->line('    $companyId = $authUser->is_super_admin');
            $this->line("        ? (int) (\$request->input('company_id') ?: Company::value('id'))");
            $this->line('        : (int) $authUser->company_id;');
            $this->newLine();
            $this->line('  AFTER:');
            $this->line('    if ($authUser->is_super_admin) {');
            $this->line("        \$companyId = (int) \$request->input('company_id');");
            $this->line("        abort_unless(\$companyId > 0, 422, 'Company required');");
            $this->line('    } else {');
            $this->line('        $companyId = (int) $authUser->company_id;');
            $this->line('    }');
        } else {
            $this->info('No DANGER patterns found in write methods. ✅');
        }

        return $dangerCount > 0 ? 1 : 0;
    }

    // ──────────────────────────────────────────────────────────────
    // PRIVATE — Analyze a single controller file
    // ──────────────────────────────────────────────────────────────
    private function analyzeFile(string $fileName, array $lines): array
    {
        $findings      = [];
        $currentMethod = 'unknown';
        $braceDepth    = 0;
        $methodDepth   = null;

        foreach ($lines as $lineNum => $rawLine) {
            $line = rtrim($rawLine);

            // Track current method name
            if (preg_match('/public function (\w+)\s*\(/', $line, $m)) {
                $currentMethod = $m[1];
                $methodDepth   = null; // reset depth tracker
            }

            // Track brace depth to know when we leave a method
            $braceDepth += substr_count($line, '{') - substr_count($line, '}');

            // Check if this line contains a dangerous pattern
            $hasDanger = false;
            foreach ($this->dangerousPatterns as $pattern) {
                if (str_contains($line, $pattern)) {
                    $hasDanger = true;
                    break;
                }
            }

            if (!$hasDanger) {
                continue;
            }

            // Classify severity based on which method we are in
            $isWriteMethod = in_array($currentMethod, $this->writeMethods);
            $isReadMethod  = in_array($currentMethod, $this->readMethods);

            // Check if the surrounding ~10 lines have a safe guard
            $contextStart = max(0, $lineNum - 2);
            $contextEnd   = min(count($lines) - 1, $lineNum + 10);
            $context      = implode("\n", array_slice($lines, $contextStart, $contextEnd - $contextStart));

            $hasSafeGuard = false;
            foreach ($this->safePatterns as $pattern) {
                if (str_contains($context, $pattern)) {
                    $hasSafeGuard = true;
                    break;
                }
            }

            if ($hasSafeGuard) {
                $severity = 'SAFE';
            } elseif ($isWriteMethod) {
                $severity = 'DANGER';
            } elseif ($isReadMethod) {
                $severity = 'WARNING';
            } else {
                // Unknown method type — treat as WARNING
                $severity = 'WARNING';
            }

            $findings[] = [
                'line'     => $lineNum + 1,
                'method'   => $currentMethod,
                'severity' => $severity,
                'code'     => trim($line),
            ];
        }

        return $findings;
    }
}