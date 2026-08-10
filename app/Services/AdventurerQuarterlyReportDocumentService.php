<?php

namespace App\Services;

use App\Models\AdventurerQuarterlyReport;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\TblWidth;
use PhpOffice\PhpWord\SimpleType\VerticalJc;
use PhpOffice\PhpWord\Style\Table as TableStyle;
use ZipArchive;

class AdventurerQuarterlyReportDocumentService
{
    public function generate(AdventurerQuarterlyReport $report): AdventurerQuarterlyReport
    {
        $report->loadMissing('club');
        $phpWord = new PhpWord;
        $phpWord->setDefaultFontName('Arial');
        $phpWord->setDefaultFontSize(9);

        $section = $phpWord->addSection([
            'paperSize' => 'Letter',
            'marginTop' => 420,
            'marginRight' => 650,
            'marginBottom' => 420,
            'marginLeft' => 650,
        ]);

        $this->addHeader($section, $report);
        $this->addIdentityFields($section, $report);
        $section->addText('Write a few sentences on something interesting your club has done or been to during this reporting period:', [
            'bold' => true,
            'size' => 9,
        ], ['spaceBefore' => 80, 'spaceAfter' => 30]);
        $section->addText($report->news_item ?: ' ', ['size' => 9], [
            'spaceAfter' => 70,
            'borderBottomSize' => 4,
            'borderBottomColor' => '808080',
        ]);

        $section->addText('QUARTERLY POINTS', ['bold' => true, 'size' => 16], [
            'alignment' => Jc::CENTER,
            'spaceBefore' => 45,
            'spaceAfter' => 35,
        ]);
        $this->addPointsTable($section, $report);

        $section->addPageBreak();
        $section->addText(
            'The purpose of the quarterly report form is to encourage Adventurer Clubs to strive for excellence!!',
            ['bold' => true, 'size' => 17],
            ['alignment' => Jc::CENTER, 'spaceAfter' => 170]
        );

        $guidance = [
            'A' => 'Clubs are expected to meet 3 times each reporting quarter. A meeting is defined by Opening, Devotional, Classes and/or Awards, and Closing exercises and/or an Outreach program. Consistency is the key!!!',
            'B' => 'Adventurers are expected to wear Class A uniforms once per quarter. Be proud to wear your uniform. Uniforms can build team spirit and club unity. If an Adventurer has not yet received a uniform, Sabbath attire must be worn for Class A.',
            'C' => 'Adventurers should have good attendance at club meetings. This provides continuity in their class work and awards classes.',
            'D' => 'Clubs should be working on at least two awards during the reporting quarter.',
            'E' => 'Provide appropriate Adventurer Curriculum for the range of your Adventurers, from Little Lambs to Helping Hands depending on the classes you have.',
            'F' => 'We encourage clubs to participate in at least one outreach activity per quarter.',
            'G' => 'In order to prepare for Investiture, Adventurer class work must be taught each month.',
            'H' => 'Planning and communication are essential within the club. One staff meeting a month is excellent for planning, communication, and fellowship between the staff.',
            'I' => 'Be prompt! We want to be prompt reporting to NAD.',
        ];

        foreach ($guidance as $letter => $text) {
            $section->addText($letter.'.  '.$text, ['bold' => true, 'size' => 10.5], [
                'spaceAfter' => 65,
                'lineHeight' => 1.05,
            ]);
        }

        $section->addText('“I can do all things through Christ which strengthens me.”', [
            'bold' => true,
            'size' => 18,
        ], ['alignment' => Jc::CENTER, 'spaceBefore' => 120, 'spaceAfter' => 15]);
        $section->addText('Philippians 4:13', ['bold' => true, 'size' => 18], [
            'alignment' => Jc::CENTER,
        ]);

        $filename = 'adventurer-quarterly-report-'
            .Str::slug($report->club_name ?: $report->club?->club_name ?: 'club')
            .'-'.$report->reporting_year.'-'.$report->reporting_period.'.docx';
        $path = 'generated/adventurer-quarterly-reports/'.$report->club_id.'/'.$filename;
        $temporaryDirectory = storage_path('app/tmp/adventurer-quarterly-reports');
        if (! is_dir($temporaryDirectory)) {
            mkdir($temporaryDirectory, 0775, true);
        }
        $temporaryPath = $temporaryDirectory.'/'.Str::uuid().'.docx';

        IOFactory::createWriter($phpWord, 'Word2007')->save($temporaryPath);
        $this->convertLegacyImagesToDrawingMl($temporaryPath);
        Storage::disk('public')->put($path, file_get_contents($temporaryPath));
        @unlink($temporaryPath);

        $report->forceFill([
            'docx_path' => $path,
            'docx_file_name' => $filename,
        ])->save();

        return $report->refresh();
    }

    private function addHeader($section, AdventurerQuarterlyReport $report): void
    {
        $header = $section->addTable([
            'width' => 10700,
            'unit' => TblWidth::TWIP,
            'layout' => TableStyle::LAYOUT_FIXED,
            'cellMargin' => 20,
        ]);
        $row = $header->addRow();
        $logo = public_path('images/adventurer-club-logo.png');
        $left = $row->addCell(1300, ['valign' => VerticalJc::CENTER]);
        $middle = $row->addCell(8100, ['valign' => VerticalJc::CENTER]);
        $right = $row->addCell(1300, ['valign' => VerticalJc::CENTER]);
        if (is_file($logo)) {
            $left->addImage($logo, ['width' => 48, 'height' => 54, 'alignment' => Jc::CENTER]);
            $right->addImage($logo, ['width' => 48, 'height' => 54, 'alignment' => Jc::CENTER]);
        }
        $middle->addText('Conference Adventurer of Seventh-day Adventists', [
            'bold' => true,
            'name' => 'Century Gothic',
            'size' => 14,
        ], ['alignment' => Jc::CENTER, 'spaceAfter' => 20]);
        $middle->addText('Quarterly Report', [
            'bold' => true,
            'name' => 'Century Gothic',
            'size' => 14,
            'color' => 'C00000',
        ], ['alignment' => Jc::CENTER, 'spaceAfter' => 20]);

        $periods = [
            AdventurerQuarterlyReport::PERIOD_SEP_OCT => ['Sep.-Oct.', 'due Nov. 1'],
            AdventurerQuarterlyReport::PERIOD_NOV_DEC => ['Nov.-Dec.', 'due Jan. 1'],
            AdventurerQuarterlyReport::PERIOD_JAN_FEB => ['Jan.-Feb.', 'due Mar. 1'],
            AdventurerQuarterlyReport::PERIOD_MAR_APR => ['Mar.-Apr.', 'due May 1'],
        ];
        $periodLine = collect($periods)->map(
            fn ($details, $period) => ($period === $report->reporting_period ? '[X] ' : '[ ] ').$details[0]
        )->implode('     ');
        $dueLine = collect($periods)->pluck(1)->implode('          ');
        $middle->addText($periodLine, ['bold' => true, 'size' => 8.5], ['alignment' => Jc::CENTER]);
        $middle->addText($dueLine, ['bold' => true, 'size' => 7.5], ['alignment' => Jc::CENTER]);
    }

    private function addIdentityFields($section, AdventurerQuarterlyReport $report): void
    {
        $table = $section->addTable([
            'width' => 10700,
            'unit' => TblWidth::TWIP,
            'layout' => TableStyle::LAYOUT_FIXED,
            'cellMargin' => 40,
        ]);
        $this->addFieldRow($table, 'Club Name', $report->club_name, 'Director’s Name', $report->director_name);
        $this->addFieldRow($table, 'Cell', $report->cell_number, 'Email', $report->email_address);
        $this->addFieldRow(
            $table,
            'Membership',
            "Boys: {$report->membership_boys}    Girls: {$report->membership_girls}    Total: {$report->membership_total}",
            'Staff',
            "Males: {$report->staff_males}    Females: {$report->staff_females}    Total: {$report->staff_total}"
        );
    }

    private function addFieldRow($table, string $leftLabel, ?string $leftValue, string $rightLabel, ?string $rightValue): void
    {
        $row = $table->addRow(320);
        $row->addCell(1450)->addText($leftLabel.':', ['bold' => true, 'size' => 8.5]);
        $row->addCell(3900, ['borderBottomSize' => 4, 'borderBottomColor' => '000000'])
            ->addText($leftValue ?: ' ', ['size' => 8.5]);
        $row->addCell(1450)->addText($rightLabel.':', ['bold' => true, 'size' => 8.5]);
        $row->addCell(3900, ['borderBottomSize' => 4, 'borderBottomColor' => '000000'])
            ->addText($rightValue ?: ' ', ['size' => 8.5]);
    }

    private function addPointsTable($section, AdventurerQuarterlyReport $report): void
    {
        $rows = [
            ['A', 'Number of meetings held this quarter (10 points per meeting - 30 maximum)', 30, $report->meetings_points],
            ['B', 'Adventurers in Class A - Full Dress Uniform once this quarter', 45, $report->uniform_points],
            ['C', 'Average Adventurer attendance: '.$this->number($report->attendance_percentage).'% (51% or above = 60; 50% or below = 30)', 60, $report->attendance_points],
            ['D', 'Awards being taught this quarter: '.$report->awards_taught.' (10 points per award - 30 maximum)', 30, $report->awards_points],
            ['E', 'Adventurer Curriculum taught for all class levels', 45, $report->curriculum_points],
            ['F', 'Outreach program: '.($report->outreach_activity ?: 'None reported'), 30, $report->outreach_points],
            ['G', 'Staff meetings this quarter: '.$report->staff_meetings_held.' (15 points per meeting - 30 maximum)', 30, $report->staff_meetings_points],
            ['H', 'Report sent by the first day of the next quarter (due '.$report->due_date->format('M. j, Y').')', 15, $report->promptness_points],
            ['I', 'News item written above', 15, $report->news_item_points],
        ];
        $table = $section->addTable([
            'width' => 10700,
            'unit' => TblWidth::TWIP,
            'layout' => TableStyle::LAYOUT_FIXED,
            'borderSize' => 4,
            'borderColor' => '808080',
            'cellMargin' => 55,
        ]);
        $header = $table->addRow();
        foreach ([['Item', 650], ['Requirement', 8300], ['Max.', 750], ['Points', 1000]] as [$label, $width]) {
            $header->addCell($width, ['bgColor' => 'E7E6E6', 'valign' => VerticalJc::CENTER])
                ->addText($label, ['bold' => true, 'size' => 8], ['alignment' => Jc::CENTER]);
        }
        foreach ($rows as [$letter, $description, $maximum, $points]) {
            $row = $table->addRow();
            $row->addCell(650, ['valign' => VerticalJc::CENTER])->addText($letter, ['bold' => true], ['alignment' => Jc::CENTER]);
            $row->addCell(8300, ['valign' => VerticalJc::CENTER])->addText($description, ['size' => 8]);
            $row->addCell(750, ['valign' => VerticalJc::CENTER])->addText((string) $maximum, ['size' => 8], ['alignment' => Jc::CENTER]);
            $row->addCell(1000, ['valign' => VerticalJc::CENTER])->addText((string) $points, ['bold' => true, 'size' => 9], ['alignment' => Jc::CENTER]);
        }
        $total = $table->addRow();
        $total->addCell(8950, ['gridSpan' => 2, 'bgColor' => 'E7E6E6'])
            ->addText('TOTAL POINTS', ['bold' => true, 'size' => 10], ['alignment' => Jc::RIGHT]);
        $total->addCell(750, ['bgColor' => 'E7E6E6'])->addText('300', ['bold' => true], ['alignment' => Jc::CENTER]);
        $total->addCell(1000, ['bgColor' => 'E7E6E6'])->addText((string) $report->total_points, ['bold' => true, 'size' => 11], ['alignment' => Jc::CENTER]);
    }

    private function number($value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    private function convertLegacyImagesToDrawingMl(string $docxPath): void
    {
        $archive = new ZipArchive;
        if ($archive->open($docxPath) !== true) {
            throw new \RuntimeException('The generated Adventurer quarterly report could not be opened.');
        }
        $documentXml = $archive->getFromName('word/document.xml');
        if (! is_string($documentXml)) {
            $archive->close();
            throw new \RuntimeException('The generated Adventurer quarterly report is missing its content.');
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

                return '<w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0">'
                    .'<wp:extent cx="'.$width.'" cy="'.$height.'"/><wp:effectExtent l="0" t="0" r="0" b="0"/>'
                    .'<wp:docPr id="'.$imageNumber.'" name="Embedded image '.$imageNumber.'"/>'
                    .'<wp:cNvGraphicFramePr><a:graphicFrameLocks noChangeAspect="1"/></wp:cNvGraphicFramePr>'
                    .'<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
                    .'<pic:pic><pic:nvPicPr><pic:cNvPr id="'.$imageNumber.'" name="Embedded image '.$imageNumber.'"/><pic:cNvPicPr/></pic:nvPicPr>'
                    .'<pic:blipFill><a:blip r:embed="'.$relationshipId.'"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
                    .'<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="'.$width.'" cy="'.$height.'"/></a:xfrm>'
                    .'<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic>'
                    .'</a:graphicData></a:graphic></wp:inline></w:drawing>';
            },
            $documentXml
        );
        if (! is_string($convertedXml)) {
            $archive->close();
            throw new \RuntimeException('The generated report images could not be processed.');
        }
        if ($imageNumber > 0) {
            $archive->addFromString('word/document.xml', $convertedXml);
        }
        $archive->close();
    }
}
