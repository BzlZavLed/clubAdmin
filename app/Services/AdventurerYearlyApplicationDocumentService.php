<?php

namespace App\Services;

use App\Models\AdventurerYearlyApplication;
use App\Models\AdventurerYearlyApplicationSignature;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\TblWidth;
use PhpOffice\PhpWord\SimpleType\VerticalJc;
use PhpOffice\PhpWord\Style\Table as TableStyle;
use ZipArchive;

class AdventurerYearlyApplicationDocumentService
{
    public function generate(AdventurerYearlyApplication $application): AdventurerYearlyApplication
    {
        $application->loadMissing(['club', 'signatures']);
        $phpWord = new PhpWord;
        $phpWord->setDefaultFontName('Times New Roman');
        $phpWord->setDefaultFontSize(8.5);

        $section = $phpWord->addSection([
            'paperSize' => 'Letter',
            'marginTop' => 360,
            'marginRight' => 500,
            'marginBottom' => 360,
            'marginLeft' => 500,
        ]);

        $logo = public_path('images/adventurer-club-logo.png');
        if (is_file($logo)) {
            $section->addImage($logo, [
                'width' => 58,
                'height' => 66,
                'alignment' => Jc::CENTER,
            ]);
        }

        $section->addText(
            'Scan and email application to areynolds@ccosda.org',
            ['bold' => true, 'size' => 9.5],
            ['alignment' => Jc::CENTER, 'spaceAfter' => 0]
        );
        $section->addText('Chesapeake Conference', ['size' => 9.5], [
            'alignment' => Jc::CENTER,
            'spaceAfter' => 20,
        ]);
        $section->addText('Date  '.$this->date($application->application_date), ['size' => 9], [
            'alignment' => Jc::CENTER,
            'spaceAfter' => 20,
            'borderBottomSize' => 6,
            'borderBottomColor' => '000000',
        ]);

        $section->addText('Adventurer Club Yearly Application', [
            'bold' => true,
            'size' => 22,
        ], [
            'spaceBefore' => 60,
            'spaceAfter' => 70,
        ]);

        $this->addFields($section, $application);

        $section->addText('The Philosophy of Adventurers', ['bold' => true, 'size' => 10], [
            'spaceBefore' => 80,
            'spaceAfter' => 20,
        ]);
        $section->addText(
            'The purpose of having an Adventurer Club is to lead its membership into a growing, redemptive relationship with Christ, and to build its membership into responsible, mature individuals and to involve its membership in active selfless service. All Adventurer leaders are Christians, working hand in hand with parents, teachers, and pastors providing optimum opportunities for Christian development. The Adventurer Club is an extension of the home, school and church; it is an experiential environment where growth and learning flourish. The membership involves children ages 4-9 who have a desire for family activities. These activities range from community and world mission projects to nature, outdoor work and camping activities, and Adventurer curriculum.',
            ['size' => 9],
            ['alignment' => Jc::BOTH, 'spaceAfter' => 45, 'lineHeight' => 1.0]
        );

        $section->addText('Signatures:', ['size' => 9.5], ['spaceAfter' => 35]);
        $signatures = $application->signatures->keyBy('role');
        $this->addSignature($section, 'Church Pastor', $signatures->get(AdventurerYearlyApplicationSignature::ROLE_PASTOR));
        $this->addSignature($section, 'Head Elder', $signatures->get(AdventurerYearlyApplicationSignature::ROLE_HEAD_ELDER));
        $this->addSignature($section, 'Church Clerk', $signatures->get(AdventurerYearlyApplicationSignature::ROLE_CHURCH_CLERK));
        $this->addSignature($section, 'Club Director', $signatures->get(AdventurerYearlyApplicationSignature::ROLE_DIRECTOR));
        $latestSignedAt = $application->signatures->whereNotNull('signed_at')->max('signed_at');
        $this->addSignature($section, 'Date', null, $latestSignedAt ? $latestSignedAt->format('m/d/Y') : $this->date($application->signature_date));

        $section->addText(
            'Above all, the Adventurer program gives children an environment in which to actively expand their personal experience with Christ.',
            ['size' => 9],
            ['alignment' => Jc::BOTH, 'spaceBefore' => 55, 'spaceAfter' => 30, 'lineHeight' => 1.0]
        );
        $section->addText('Your Commitment to Adventurers', ['bold' => true, 'size' => 10], ['spaceAfter' => 15]);
        $section->addText(
            'We, the undersigned, have read, understand, and are in full agreement with the above Philosophy of Adventurers and agree to support our club through those means with which the Lord has blessed this church, including finances, staff volunteers, securing a place to meet, transportation on outings, and other such needs as may arise in the fulfillment of this ministry, and to assist and support the work of the Adventurer ministry in this conference and around the world.',
            ['size' => 9],
            ['alignment' => Jc::BOTH, 'spaceAfter' => 35, 'lineHeight' => 1.0]
        );
        $section->addText('Other Church Board Members:', ['bold' => true, 'size' => 9.5], ['spaceAfter' => 10]);
        foreach ($application->other_board_members ?: [] as $member) {
            $section->addText($member ?: ' ', ['size' => 9], [
                'spaceAfter' => 25,
                'borderBottomSize' => 4,
                'borderBottomColor' => '000000',
            ]);
        }

        $filename = 'adventurer-club-yearly-application-'
            .Str::slug($application->club_name ?: $application->club?->club_name ?: 'club')
            .'-'
            .Str::slug($application->application_year)
            .'.docx';
        $path = 'generated/adventurer-yearly-applications/'.$application->club_id.'/'.$filename;
        $temporaryDirectory = storage_path('app/tmp/adventurer-yearly-applications');
        if (! is_dir($temporaryDirectory)) {
            mkdir($temporaryDirectory, 0775, true);
        }
        $temporaryPath = $temporaryDirectory.'/'.Str::uuid().'.docx';

        IOFactory::createWriter($phpWord, 'Word2007')->save($temporaryPath);
        $this->convertLegacyImagesToDrawingMl($temporaryPath);
        Storage::disk('public')->put($path, file_get_contents($temporaryPath));
        @unlink($temporaryPath);

        $application->forceFill([
            'docx_path' => $path,
            'docx_file_name' => $filename,
        ])->save();

        return $application->refresh();
    }

    private function addFields($section, AdventurerYearlyApplication $application): void
    {
        $table = $section->addTable([
            'width' => 11200,
            'unit' => TblWidth::TWIP,
            'layout' => TableStyle::LAYOUT_FIXED,
            'cellMargin' => 15,
        ]);
        $fields = [
            'Club Name' => $application->club_name,
            'Sponsoring Church' => $application->sponsoring_church,
            'Pastor' => $application->pastor,
            'Elected Club Director' => $application->elected_club_director,
            'Email Address' => $application->email_address,
            'Cell Number' => $application->cell_number,
            'Home Address' => $application->home_address,
        ];

        foreach ($fields as $label => $value) {
            $row = $table->addRow(250);
            $row->addCell(2600)->addText($label, ['bold' => true, 'size' => 9.5], ['spaceAfter' => 0]);
            $valueCell = $row->addCell(8600, [
                'borderBottomSize' => 5,
                'borderBottomColor' => '000000',
                'valign' => VerticalJc::CENTER,
            ]);
            $valueCell->addText($value ?: ' ', ['size' => 9], ['spaceAfter' => 0]);
        }
    }

    private function addSignature(
        $cell,
        string $label,
        ?AdventurerYearlyApplicationSignature $signature = null,
        ?string $fallbackText = null,
    ): void {
        $table = $cell->addTable([
            'width' => 5200,
            'unit' => TblWidth::TWIP,
            'layout' => TableStyle::LAYOUT_FIXED,
            'cellMargin' => 15,
        ]);
        $row = $table->addRow(275);
        $row->addCell(1800)->addText($label, ['size' => 8.5], ['spaceAfter' => 0]);
        $valueCell = $row->addCell(3400, [
            'borderBottomSize' => 4,
            'borderBottomColor' => '000000',
            'valign' => VerticalJc::CENTER,
        ]);
        if (
            $signature?->signed_at
            && $signature->signature_type === 'drawn'
            && $signature->signature_path
            && Storage::disk('public')->exists($signature->signature_path)
        ) {
            $valueCell->addImage(
                Storage::disk('public')->path($signature->signature_path),
                ['height' => 22, 'width' => 95]
            );
        } else {
            $text = $signature?->signed_at
                ? ($signature->signature_text ?: $signature->signer_name)
                : $fallbackText;
            $valueCell->addText($text ?: ' ', ['size' => 8.3], ['spaceAfter' => 0]);
        }
    }

    private function date($date): string
    {
        return $date ? $date->format('m/d/Y') : '';
    }

    private function convertLegacyImagesToDrawingMl(string $docxPath): void
    {
        $archive = new ZipArchive;
        if ($archive->open($docxPath) !== true) {
            throw new \RuntimeException('The generated Adventurer application could not be opened for image compatibility processing.');
        }

        $documentXml = $archive->getFromName('word/document.xml');
        if (! is_string($documentXml)) {
            $archive->close();
            throw new \RuntimeException('The generated Adventurer application is missing its document content.');
        }

        $documentXml = str_replace(
            'xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"',
            'xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" '
                .'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
                .'xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"',
            $documentXml
        );

        $imageNumber = 0;
        $convertedXml = preg_replace_callback(
            '/<w:pict><v:shape\b[^>]*style="[^"]*width:([\d.]+)pt;\s*height:([\d.]+)pt;[^"]*"[^>]*>.*?<v:imagedata r:id="([^"]+)"[^>]*\/>.*?<\/v:shape><\/w:pict>/s',
            function (array $matches) use (&$imageNumber): string {
                $imageNumber++;
                $width = (int) round((float) $matches[1] * 12700);
                $height = (int) round((float) $matches[2] * 12700);
                $relationshipId = htmlspecialchars($matches[3], ENT_XML1 | ENT_QUOTES, 'UTF-8');

                return '<w:drawing>'
                    .'<wp:inline distT="0" distB="0" distL="0" distR="0">'
                    .'<wp:extent cx="'.$width.'" cy="'.$height.'"/>'
                    .'<wp:effectExtent l="0" t="0" r="0" b="0"/>'
                    .'<wp:docPr id="'.$imageNumber.'" name="Embedded image '.$imageNumber.'"/>'
                    .'<wp:cNvGraphicFramePr><a:graphicFrameLocks noChangeAspect="1"/></wp:cNvGraphicFramePr>'
                    .'<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
                    .'<pic:pic><pic:nvPicPr>'
                    .'<pic:cNvPr id="'.$imageNumber.'" name="Embedded image '.$imageNumber.'"/>'
                    .'<pic:cNvPicPr/></pic:nvPicPr>'
                    .'<pic:blipFill><a:blip r:embed="'.$relationshipId.'"/>'
                    .'<a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
                    .'<pic:spPr><a:xfrm><a:off x="0" y="0"/>'
                    .'<a:ext cx="'.$width.'" cy="'.$height.'"/></a:xfrm>'
                    .'<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
                    .'</pic:pic></a:graphicData></a:graphic>'
                    .'</wp:inline></w:drawing>';
            },
            $documentXml
        );

        if (! is_string($convertedXml) || $imageNumber === 0) {
            $archive->close();
            throw new \RuntimeException('The generated Adventurer application images could not be converted to compatible Word markup.');
        }

        $archive->addFromString('word/document.xml', $convertedXml);
        $archive->close();
    }
}
