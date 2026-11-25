<?php
namespace Tests\Feature;
use App\Enums\SystemRole;
use App\Livewire\Releases\Form as ReleaseForm;
use App\Livewire\Tracks\Form as TrackForm;
use Database\Seeders\CatalogReferenceSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;
class TmpDebugTest extends TestCase
{
    use RefreshDatabase;
    public function test_debug(): void
    {
        $this->seed([RolesAndPermissionsSeeder::class, CatalogReferenceSeeder::class]);
        $admin = $this->makeUser(SystemRole::SuperAdmin);
        $this->activateLicense();
        $t = Livewire::actingAs($admin)->test(ReleaseForm::class)->set('title', 'Half Done')->set('artistRows', [['artist_id' => '', 'role' => 'primary']])->call('save')->call('saveDraft');
        fwrite(STDERR, json_encode($t->errors()->toArray())."\n");
        $html = $t->html();
        preg_match_all('/.{80}New artist.{40}/s', $html, $m);
        fwrite(STDERR, json_encode($m[0])."\n");
    }
}
