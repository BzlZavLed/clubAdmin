<?php

namespace Tests\Unit;

use App\Models\Club;
use App\Services\ClubLogoService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClubLogoServiceTest extends TestCase
{
    public function test_initials_follow_the_club_name_rules(): void
    {
        $service = app(ClubLogoService::class);

        $this->assertSame('CO', $service->initials(new Club(['club_name' => 'Conquerors'])));
        $this->assertSame('NH', $service->initials(new Club(['club_name' => 'New Hope Club'])));
        $this->assertSame('ÁG', $service->initials(new Club(['club_name' => 'Águilas'])));
        $this->assertSame('CL', $service->initials(new Club(['club_name' => ''])));
    }

    public function test_missing_logo_returns_a_generated_png_avatar(): void
    {
        Storage::fake('public');
        $service = app(ClubLogoService::class);
        $club = new Club(['club_name' => 'New Hope Club', 'logo_path' => null]);

        $avatar = $service->url($club);
        $this->assertStringStartsWith('data:image/png;base64,', $avatar);
        $this->assertSame($avatar, $service->dataUri($club));

        $png = base64_decode(substr($avatar, strlen('data:image/png;base64,')), true);
        $this->assertNotFalse($png);
        $dimensions = getimagesizefromstring($png);
        $this->assertIsArray($dimensions);
        $this->assertSame([512, 512], [$dimensions[0], $dimensions[1]]);
    }

    public function test_uploaded_club_logo_takes_priority_over_the_avatar(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put(
            'club-logos/12/logo.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=')
        );
        $service = app(ClubLogoService::class);
        $club = new Club(['club_name' => 'Conquerors', 'logo_path' => 'club-logos/12/logo.png']);

        $this->assertStringEndsWith('/storage/club-logos/12/logo.png', $service->url($club));
        $this->assertStringStartsWith('data:image/png;base64,', $service->dataUri($club));
    }
}
