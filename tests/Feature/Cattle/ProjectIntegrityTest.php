<?php

namespace Tests\Feature\Cattle;

// ══════════════════════════════════════════════════════════════════
//  ProjectIntegrityTest
//
//  Safety nets that read the project itself. Each one guards a real
//  bug that was found in this app and must not come back:
//
//   • a button/route pointing at a controller method that does not exist
//   • a permission checked in code that was never created (→ error 500)
//   • a page opened by a route name that does not exist
//   • the standard Chart of Accounts losing a posting account
//   • company-ownership checks done with a strict === on numbers
//
//  @layer Test
// ══════════════════════════════════════════════════════════════════

use App\Models\Account;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Tests\Support\CattleScenario;
use Tests\TestCase;

class ProjectIntegrityTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, string> every .php file under app/ */
    private function phpFiles(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $out[] = $f->getPathname();
            }
        }
        return $out;
    }

    public function test_every_route_points_to_a_real_controller_method(): void
    {
        $broken = [];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();
            if ($action === 'Closure' || ! str_contains($action, '@')) {
                continue;
            }
            [$class, $method] = explode('@', $action);
            if (! class_exists($class) || ! method_exists($class, $method)) {
                $broken[] = $route->methods()[0] . ' /' . ltrim($route->uri(), '/') . ' → ' . $action;
            }
        }

        $this->assertSame([], $broken, "Routes pointing to missing code:\n" . implode("\n", $broken));
    }

    public function test_every_permission_checked_in_code_exists_in_the_seeder(): void
    {
        CattleScenario::seedPermissions();
        $seeded = Permission::pluck('name')->all();

        $missing = [];
        foreach ($this->phpFiles(base_path('app')) as $file) {
            $code = file_get_contents($file);
            if (preg_match_all("/(?:hasPermissionTo|->can)\(\s*'([^'\$]+)'/", $code, $m)) {
                foreach ($m[1] as $perm) {
                    if (! in_array($perm, $seeded, true)) {
                        $missing[$perm] = basename($file);
                    }
                }
            }
        }

        $this->assertSame([], $missing, 'Permissions used in code but never created: ' . json_encode($missing));
    }

    public function test_every_route_name_used_in_the_screens_exists(): void
    {
        $skip = ['TagInput.vue', 'subscription.js']; // known to be unused
        $bad  = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('js'), \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if (! $f->isFile() || ! in_array($f->getExtension(), ['vue', 'js'], true)) {
                continue;
            }
            if (in_array($f->getFilename(), $skip, true)) {
                continue;
            }
            // ignore comment lines (they may mention old, renamed routes)
            $code = implode("\n", array_filter(
                file($f->getPathname(), FILE_IGNORE_NEW_LINES),
                fn ($l) => ! preg_match('/^\s*(\/\/|\*|\/\*)/', $l)
            ));
            if (preg_match_all("/route\(\s*'([a-z0-9_.\-]+)'/i", $code, $m)) {
                foreach (array_unique($m[1]) as $name) {
                    if (! Route::has($name)) {
                        $bad[$name] = $f->getFilename();
                    }
                }
            }
        }

        $this->assertSame([], $bad, 'Screens use route names that do not exist: ' . json_encode($bad));
    }

    public function test_every_page_a_controller_renders_exists(): void
    {
        // Known gaps: these controller methods exist but NO screen links to them
        // (the Chart of Accounts uses a side panel; the other two are never
        // opened from a button). Remove an entry here once its page is built.
        $knownUnlinked = [
            'Accounting/Accounts/CreateEdit',
            'Companies/Show',
            'Slaughter/Show',
        ];

        $missing = [];
        foreach ($this->phpFiles(app_path('Http/Controllers')) as $file) {
            if (preg_match_all("/Inertia::render\(\s*'([A-Za-z0-9_\/]+)'/", file_get_contents($file), $m)) {
                foreach (array_unique($m[1]) as $page) {
                    if (! in_array($page, $knownUnlinked, true)
                        && ! file_exists(resource_path("js/Pages/{$page}.vue"))) {
                        $missing[$page] = basename($file);
                    }
                }
            }
        }

        $this->assertSame([], $missing, 'Controllers render pages that do not exist: ' . json_encode($missing));
    }

    public function test_company_checks_never_use_strict_comparison_without_casting(): void
    {
        $offenders = [];
        foreach ($this->phpFiles(app_path('Http')) as $file) {
            foreach (file($file) as $i => $line) {
                if (preg_match('/company_id\s*[!=]==|[!=]==\s*\$[\w>\-]*company_id/', $line)
                    && ! str_contains($line, '(int)')) {
                    $offenders[] = basename($file) . ':' . ($i + 1);
                }
            }
        }

        $this->assertSame([], $offenders, 'Strict company_id comparison without (int): ' . implode(', ', $offenders));
    }

    public function test_standard_chart_of_accounts_has_the_accounts_the_app_posts_to(): void
    {
        CattleScenario::seedPermissions();
        $s = CattleScenario::make();

        foreach (['1310', '1320', '1330', '1340', '1600', '2210', '2230', '4100', '5100', '5200'] as $code) {
            $acc = Account::where('company_id', $s->company->id)->where('code', $code)->first();
            $this->assertNotNull($acc, "Account {$code} is missing from the standard chart");
            $this->assertTrue((bool) $acc->allow_direct_posting, "Account {$code} must allow direct posting");
            $this->assertTrue((bool) $acc->is_active, "Account {$code} must be active");
        }
    }

    public function test_seeding_the_chart_twice_does_not_duplicate_accounts(): void
    {
        $s = CattleScenario::make();
        $before = Account::where('company_id', $s->company->id)->count();

        (new ChartOfAccountsSeeder())->seedForCompany($s->company->id);

        $this->assertSame($before, Account::where('company_id', $s->company->id)->count());
    }

    public function test_each_company_gets_its_own_independent_chart(): void
    {
        $a = CattleScenario::make();
        $b = CattleScenario::make();

        $this->assertSame(
            Account::where('company_id', $a->company->id)->count(),
            Account::where('company_id', $b->company->id)->count()
        );
        $this->assertNotSame($a->account('1600')->id, $b->account('1600')->id);
    }

    public function test_journal_entries_have_the_reversal_link_column(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('journal_entries', 'reversal_of_id'));
        $this->assertContains('reversal_of_id', (new \App\Models\JournalEntry())->getFillable());
    }
}
