<?php

namespace Tests\Feature;

use App\Models\MemberAdventurer;
use App\Services\DocumentExportService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class DocumentExportServiceTest extends TestCase
{
    public function test_member_word_export_uses_drawn_signature_image_when_available(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('parent-enrollment-signatures/signature.png', $this->signaturePng());

        $member = $this->member([
            'signature' => 'Taylor Parent',
            'signature_type' => 'drawn',
            'signature_path' => 'parent-enrollment-signatures/signature.png',
        ]);

        $document = app(DocumentExportService::class)->generateMemberDoc(
            $member,
            Storage::disk('public')->path('exports')
        );

        $archive = new ZipArchive;
        $this->assertTrue($archive->open($document) === true);
        $documentXml = $archive->getFromName('word/document.xml');
        $mediaFiles = [];
        for ($index = 0; $index < $archive->numFiles; $index++) {
            $name = $archive->getNameIndex($index);
            if (str_starts_with($name, 'word/media/')) {
                $mediaFiles[] = $name;
            }
        }
        $archive->close();

        $this->assertNotEmpty($mediaFiles);
        $this->assertStringNotContainsString('${signature}', $documentXml);
        $this->assertTrue(
            str_contains($documentXml, '<w:drawing>') || str_contains($documentXml, '<w:pict>'),
            'The exported Word document does not contain a rendered signature image.'
        );
    }

    public function test_member_word_export_uses_typed_signature_and_falls_back_when_image_is_missing(): void
    {
        Storage::fake('public');

        foreach ([
            $this->member(['signature' => 'Typed Parent', 'signature_type' => 'typed']),
            $this->member([
                'applicant_name' => 'Missing Image Child',
                'signature' => 'Fallback Parent',
                'signature_type' => 'drawn',
                'signature_path' => 'parent-enrollment-signatures/missing.png',
            ]),
        ] as $member) {
            $document = app(DocumentExportService::class)->generateMemberDoc(
                $member,
                Storage::disk('public')->path('exports')
            );

            $archive = new ZipArchive;
            $this->assertTrue($archive->open($document) === true);
            $documentXml = $archive->getFromName('word/document.xml');
            $archive->close();

            $this->assertStringContainsString($member->signature, $documentXml);
            $this->assertStringNotContainsString('${signature}', $documentXml);
        }
    }

    private function member(array $overrides = []): MemberAdventurer
    {
        return new MemberAdventurer(array_merge([
            'club_name' => 'Sample Adventurer Club',
            'director_name' => 'Jamie Director',
            'church_name' => 'Sample Church',
            'applicant_name' => 'Sample Child',
            'birthdate' => '2018-01-01',
            'age' => 8,
            'grade' => '2',
            'mailing_address' => '1 Main Street',
            'cell_number' => '555-111-2222',
            'emergency_contact' => 'Taylor Parent',
            'investiture_classes' => ['Busy Bee'],
            'allergies' => 'None',
            'physical_restrictions' => 'None',
            'health_history' => 'None',
            'parent_name' => 'Taylor Parent',
            'parent_cell' => '555-111-2222',
            'home_address' => '1 Main Street',
            'email_address' => 'parent@example.com',
            'signature' => 'Taylor Parent',
            'signature_type' => 'typed',
            'signature_path' => null,
        ], $overrides));
    }

    private function signaturePng(): string
    {
        $image = imagecreatetruecolor(600, 180);
        $white = imagecolorallocate($image, 255, 255, 255);
        $ink = imagecolorallocate($image, 15, 23, 42);
        imagefill($image, 0, 0, $white);
        imagesetthickness($image, 7);
        imageline($image, 35, 125, 125, 45, $ink);
        imageline($image, 125, 45, 190, 130, $ink);
        imageline($image, 190, 130, 300, 60, $ink);
        imageline($image, 300, 60, 380, 120, $ink);
        imageline($image, 380, 120, 555, 65, $ink);

        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);

        return $png;
    }
}
