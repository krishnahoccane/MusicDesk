<?php

namespace Tests\Installation;

use App\Services\Installation\RequirementsChecker;
use Tests\TestCase;

class RequirementsCheckerTest extends TestCase
{
    protected bool $installed = false;

    public function test_it_reports_every_group(): void
    {
        $groups = (new RequirementsChecker)->check();

        $this->assertSame(['PHP', 'Extensions', 'Functions', 'Permissions', 'Uploads & limits', 'Database drivers'], array_keys($groups));

        foreach ($groups as $checks) {
            foreach ($checks as $check) {
                $this->assertContains($check['status'], ['pass', 'warn', 'fail']);
            }
        }
    }

    public function test_php_version_passes_on_supported_runtime(): void
    {
        $php = (new RequirementsChecker)->check()['PHP'][0];

        $this->assertNotSame('fail', $php['status']);
    }

    public function test_required_extensions_include_licensing_and_backup_dependencies(): void
    {
        $this->assertContains('sodium', RequirementsChecker::REQUIRED_EXTENSIONS);
        $this->assertContains('zip', RequirementsChecker::REQUIRED_EXTENSIONS);
        $this->assertContains('pdo_mysql', RequirementsChecker::REQUIRED_EXTENSIONS);
    }

    public function test_size_parsing(): void
    {
        $checker = new RequirementsChecker;

        $this->assertSame(64 * 1024 * 1024, $checker->bytes('64M'));
        $this->assertSame(2 * 1024 ** 3, $checker->bytes('2G'));
        $this->assertSame(512 * 1024, $checker->bytes('512K'));
        $this->assertSame(0, $checker->bytes(''));
    }
}
