<?php
namespace App\Services;

use App\Models\MemberAdventurer;
use App\Models\StaffAdventurer;
use PhpOffice\PhpWord\TemplateProcessor;
use Illuminate\Support\Str;

class DocumentExportService
{
    public function generateMemberDoc(MemberAdventurer $member, string $outputDir): string
    {
        $templatePath = $this->getTemplatePath('template_adventurer_new.docx');
        $processor = new TemplateProcessor($templatePath);
    
        $processor->setValue('current_date', date('m/d/Y'));
        $processor->setValue('club_name', $member->club_name);
        $processor->setValue('director_name', $member->director_name);
        $processor->setValue('church_name', $member->church_name);
    
        $processor->setValue('applicant_name', $member->applicant_name);
        $processor->setValue('birthdate', $member->birthdate);
        $processor->setValue('age', $member->age);
        $processor->setValue('grade', $member->grade);
        $processor->setValue('mailing_address', $member->mailing_address);
        $processor->setValue('cell_number', $member->cell_number);
        $processor->setValue('emergency_contact', $member->emergency_contact . " (Cell: " . $member->cell_number . ")");
    
        $processor->setValue('investiture_classes', is_array($member->investiture_classes) ? implode(', ', $member->investiture_classes) : $member->investiture_classes);
        $processor->setValue('allergies', $member->allergies);
        $processor->setValue('physical_restrictions', $member->physical_restrictions);
        $processor->setValue('health_history', $member->health_history);
    
        $processor->setValue('signature', $member->signature);
        $processor->setValue('parent_signature', $member->parent_name);
        $processor->setValue('parent_name', $member->parent_name);
        $processor->setValue('parent_cell', $member->parent_cell);
        $processor->setValue('home_address', $member->home_address);
        $processor->setValue('email_address', $member->email_address);
    
        if (!file_exists($outputDir)) {
            mkdir($outputDir, 0775, true);
        }
    
        $filename = "adventurer_member_" . Str::slug($member->applicant_name) . ".docx";
        $outputPath = $outputDir . '/' . $filename;
        $processor->saveAs($outputPath);
    
        return $outputPath;
    }


    public function generateStaffDoc(StaffAdventurer $staff, string $outputDir): string
    {
        $templatePath = $this->getTemplatePath('template_staff_new.docx');
        $processor = new TemplateProcessor($templatePath);

        // Basic fields
        $processor->setValue('date_of_record', date('m/d/Y'));
        $processor->setValue('name', $staff->name);
        $processor->setValue('dob', optional($staff->dob)->format('Y-m-d'));
        $processor->setValue('address', $staff->address);
        $processor->setValue('city', $staff->city);
        $processor->setValue('state', $staff->state);
        $processor->setValue('zip', $staff->zip);
        $processor->setValue('cell_phone', $staff->cell_phone);
        $processor->setValue('church_name', $staff->church_name);
        $processor->setValue('club_name', $staff->club_name);
        $processor->setValue('email', $staff->email);

        // Health History
        $processor->setValue('has_health_limitation', $staff->has_health_limitation ? 'Yes' : 'No');
        $processor->setValue('health_limitation_description', $staff->health_limitation_description);

        // Experiences
        $experiences = is_array($staff->experiences)
            ? $staff->experiences
            : json_decode($staff->experiences, true);

        $lines = [];

        foreach ($experiences as $i => $exp) {
            $line = ($i + 1) . '. ';
            $line .= str_pad($exp['position'] ?? '', 50);
            $line .= str_pad($exp['organization'] ?? '', 50);
            $line .= $exp['date'] ?? '';
            $lines[] = $line;
        }

        $formattedBlock = implode("\n", $lines);
        $processor->setValue('experiences_block', $formattedBlock);

        // Awards/Instruction Abilities

        $awards = is_array($staff->award_instruction_abilities)
            ? $staff->award_instruction_abilities
            : json_decode($staff->award_instruction_abilities, true);

        $lines = [];

        foreach ($awards as $i => $aw) {
            $line = ($i + 1) . '. ';
            $line .= str_pad($aw['name'] ?? '', 50);
            $line .= str_pad($aw['level'] ?? '', 50);
            $lines[] = $line;
        }

        $formattedBlock = implode("\n", $lines);
        $processor->setValue('awards_block', $formattedBlock);

        // Unlawful Conduct
        $processor->setValue('unlawful_sexual_conduct', $staff->unlawful_sexual_conduct);
        $conducts = is_array($staff->unlawful_sexual_conduct_records)
            ? $staff->unlawful_sexual_conduct_records
            : json_decode($staff->unlawful_sexual_conduct_records, true);

        $lines = [];

        foreach ($conducts as $index => $entry) {
            $lines[] = ($index + 1) . ". Date & Place: " . ($entry['date_place'] ?? 'N/A');
            $lines[] = "   Type of Conduct: " . ($entry['type'] ?? 'N/A');
            $lines[] = "   Reference name, address and phone: " . ($entry['reference'] ?? 'N/A');
            $lines[] = ""; // Empty line between entries
        }

        $formattedBlock = implode("\n", $lines);

        // Escape XML-sensitive characters to prevent Word corruption
        $cleanedBlock = htmlspecialchars($formattedBlock, ENT_QUOTES | ENT_XML1);

        $processor->setValue('unlawful_conduct_block', $cleanedBlock);


        // Sterling Volunteer
        $processor->setValue('sterling_volunteer_completed', $staff->sterling_volunteer_completed ? 'Yes' : 'No');

        // References
        $processor->setValue('reference_pastor', $staff->reference_pastor);
        $processor->setValue('reference_elder', $staff->reference_elder);
        $processor->setValue('reference_other', $staff->reference_other);

        // Signature
        $processor->setValue('applicant_signature', $staff->applicant_signature);
        $processor->setValue('application_signed_date', optional($staff->application_signed_date)->format('Y-m-d'));

        // Save
        if (!file_exists($outputDir)) {
            mkdir($outputDir, 0775, true);
        }

        $filename = "adventurer_staff_" . Str::slug($staff->name) . ".docx";
        $outputPath = $outputDir . '/' . $filename;
        $processor->saveAs($outputPath);

        return $outputPath;
    }

    private function getTemplatePath(string $file): string
    {
        $candidates = [
            storage_path('app/templates/' . $file),
            resource_path('templates/' . $file),
            base_path('templates/' . $file),
        ];

        foreach ($candidates as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        throw new \RuntimeException("Template file {$file} not found. Checked: " . implode(', ', $candidates));
    }
}

