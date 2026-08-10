<?php

namespace App\Services;

use App\Models\AdventurerInductionRequest;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\TblWidth;
use PhpOffice\PhpWord\SimpleType\VerticalJc;
use PhpOffice\PhpWord\Style\Table as TableStyle;

class AdventurerInductionRequestDocumentService
{
    public function generate(AdventurerInductionRequest $inductionRequest): AdventurerInductionRequest
    {
        $inductionRequest->loadMissing('club');
        $phpWord = new PhpWord;
        $phpWord->setDefaultFontName('Times New Roman');
        $phpWord->setDefaultFontSize(11);

        $section = $phpWord->addSection([
            'paperSize' => 'Letter',
            'marginTop' => 900,
            'marginRight' => 1080,
            'marginBottom' => 900,
            'marginLeft' => 1080,
            'headerHeight' => 708,
            'footerHeight' => 708,
        ]);

        $section->addText('Adventurer Induction Attendance', [
            'bold' => true,
            'size' => 24,
        ], [
            'alignment' => Jc::CENTER,
            'spaceBefore' => 120,
            'spaceAfter' => 0,
        ]);
        $section->addText('Request Form', [
            'bold' => true,
            'size' => 24,
        ], [
            'alignment' => Jc::CENTER,
            'spaceAfter' => 280,
        ]);

        $this->addField($section, 'We would like', $inductionRequest->requested_attendee, 2300, 7060);
        $section->addText('(Area Coordinator/Staff to attend our Club’s Induction Service)', [
            'bold' => true,
            'size' => 11,
        ], [
            'alignment' => Jc::CENTER,
            'spaceAfter' => 190,
        ]);
        $this->addField($section, 'Club Name', $inductionRequest->club_name);
        $this->addField($section, 'Date of Induction', $inductionRequest->induction_date->format('F j, Y'));
        $this->addField($section, 'Time of Induction', $this->time($inductionRequest->induction_time));
        $this->addField($section, 'Place of Induction', $inductionRequest->induction_place);

        $section->addText('Direction:', ['bold' => true, 'size' => 11.5], [
            'spaceBefore' => 30,
            'spaceAfter' => 15,
        ]);
        $directionLines = preg_split('/\R/', trim((string) $inductionRequest->directions)) ?: [];
        $directionText = trim(implode(' ', array_filter($directionLines)));
        $section->addText($directionText ?: ' ', ['size' => 11], [
            'spaceAfter' => 95,
            'borderBottomSize' => 5,
            'borderBottomColor' => '000000',
        ]);
        for ($line = 0; $line < 3; $line++) {
            $section->addText(' ', ['size' => 11], [
                'spaceAfter' => 95,
                'borderBottomSize' => 5,
                'borderBottomColor' => '000000',
            ]);
        }

        $section->addText(' ', [], [
            'spaceBefore' => 120,
            'spaceAfter' => 100,
            'borderBottomSize' => 8,
            'borderBottomColor' => '000000',
        ]);
        $section->addText('Induction APPOINTMENT CONFIRMATION', [
            'bold' => true,
            'size' => 15,
        ], [
            'alignment' => Jc::CENTER,
            'spaceAfter' => 0,
        ]);
        $section->addText('For Office Use Only', [
            'bold' => true,
            'size' => 13,
        ], [
            'alignment' => Jc::CENTER,
            'spaceAfter' => 130,
        ]);
        $this->addField(
            $section,
            'Received',
            $inductionRequest->received_at?->format('F j, Y g:i A') ?: '',
            1800,
            7560,
        );
        $this->addField(
            $section,
            'Emailed',
            $inductionRequest->emailed_at?->format('F j, Y g:i A') ?: '',
            1800,
            7560,
        );

        $filename = 'adventurer-induction-request-'
            .Str::slug($inductionRequest->club_name ?: $inductionRequest->club?->club_name ?: 'club')
            .'-'.$inductionRequest->induction_date->format('Y-m-d').'.docx';
        $path = 'generated/adventurer-induction-requests/'.$inductionRequest->club_id.'/'.$filename;
        $temporaryDirectory = storage_path('app/tmp/adventurer-induction-requests');
        if (! is_dir($temporaryDirectory)) {
            mkdir($temporaryDirectory, 0775, true);
        }
        $temporaryPath = $temporaryDirectory.'/'.Str::uuid().'.docx';

        IOFactory::createWriter($phpWord, 'Word2007')->save($temporaryPath);
        Storage::disk('public')->put($path, file_get_contents($temporaryPath));
        @unlink($temporaryPath);

        $inductionRequest->forceFill([
            'docx_path' => $path,
            'docx_file_name' => $filename,
        ])->save();

        return $inductionRequest->refresh();
    }

    private function addField(
        $section,
        string $label,
        ?string $value,
        int $labelWidth = 2700,
        int $valueWidth = 6660,
    ): void {
        $table = $section->addTable([
            'width' => 9360,
            'unit' => TblWidth::TWIP,
            'layout' => TableStyle::LAYOUT_FIXED,
            'cellMarginTop' => 80,
            'cellMarginBottom' => 80,
            'cellMarginLeft' => 120,
            'cellMarginRight' => 120,
        ]);
        $row = $table->addRow();
        $row->addCell($labelWidth, ['valign' => VerticalJc::CENTER])
            ->addText($label.':', ['bold' => true, 'size' => 11.5], ['spaceAfter' => 0]);
        $row->addCell($valueWidth, [
            'valign' => VerticalJc::CENTER,
            'borderBottomSize' => 5,
            'borderBottomColor' => '000000',
        ])->addText($value ?: ' ', ['size' => 11], ['spaceAfter' => 0]);
        $section->addText(' ', ['size' => 2], ['spaceAfter' => 65]);
    }

    private function time(string $time): string
    {
        return \Carbon\CarbonImmutable::parse($time)->format('g:i A');
    }
}
