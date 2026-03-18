<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Pathfinder Application</title>
    <style>
        @page { margin: 22px 28px; }
        body { font-family: Arial, sans-serif; color: #111; font-size: 11px; line-height: 1.2; }
        .header { width: 100%; margin-bottom: 8px; }
        .header-table { width: 100%; border-collapse: collapse; }
        .header-title { font-size: 22px; font-weight: 700; }
        .logo-cell { text-align: right; width: 90px; }
        .logo-box {
            display: inline-block;
            width: 74px;
            height: 74px;
            border: 2px solid #0b5fa5;
            border-radius: 10px;
            text-align: center;
            font-size: 10px;
            font-weight: 700;
            color: #0b5fa5;
            padding-top: 24px;
            box-sizing: border-box;
        }
        .subtext { font-size: 10px; }
        .row-table { width: 100%; border-collapse: collapse; margin-bottom: 3px; }
        .row-table td { vertical-align: bottom; padding-right: 8px; }
        .label { white-space: nowrap; width: 1%; }
        .line {
            display: block;
            min-height: 15px;
            border-bottom: 1px solid #333;
            padding: 0 4px 1px 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .line.multiline {
            min-height: 34px;
            white-space: normal;
        }
        .section-note { margin: 8px 0; text-align: justify; }
        .question-list { margin-top: 6px; }
        .question-row { margin: 3px 0; }
        .checkbox {
            display: inline-block;
            width: 11px;
            text-align: center;
            font-weight: 700;
        }
        .small-note { font-size: 8px; color: #444; }
        .footer-warning {
            margin-top: 10px;
            text-align: center;
            color: #d32020;
            font-size: 11px;
            font-weight: 700;
        }
    </style>
</head>
<body>
    @php
        $pickupPeople = is_array($member->pickup_authorized_people ?? null) ? implode(', ', $member->pickup_authorized_people) : '';
        $guardianPrimary = $member->father_guardian_name ?: $member->mother_guardian_name;
        $guardianSignature = $member->parent_guardian_signature ?: $guardianPrimary;
        $signedAt = $member->signed_at ? \Carbon\Carbon::parse($member->signed_at)->format('m/d/Y') : '';
        $yesNo = fn ($value) => $value ? 'Yes' : 'No';
        $checkbox = fn ($value) => $value ? 'X' : '';
        $logoPath = public_path('images/pathfinder.webp');
    @endphp

    <div class="header">
        <table class="header-table">
            <tr>
                <td>
                    <div class="header-title">Pathfinder Application &amp; Health Record</div>
                </td>
                <td class="logo-cell">
                    @if(file_exists($logoPath))
                        <img src="{{ $logoPath }}" alt="Pathfinder Logo" style="width: 78px; height: auto;">
                    @else
                        <div class="logo-box">PATHFINDER</div>
                    @endif
                </td>
            </tr>
        </table>
    </div>

    <table class="row-table">
        <tr>
            <td class="label">Club Name:</td>
            <td><span class="line">{{ $club?->club_name ?? $member->club_name ?? '' }}</span></td>
            <td class="label">Directors Name</td>
            <td><span class="line">{{ $club?->director_name ?? $member->director_name ?? '' }}</span></td>
        </tr>
    </table>
    <table class="row-table">
        <tr>
            <td class="label">Name:</td>
            <td><span class="line">{{ $member->applicant_name ?? '' }}</span></td>
            <td class="label">DOB:</td>
            <td><span class="line">{{ $member->birthdate ? \Carbon\Carbon::parse($member->birthdate)->format('m/d/Y') : '' }}</span></td>
        </tr>
    </table>
    <table class="row-table">
        <tr>
            <td class="label">Address:</td>
            <td><span class="line">{{ $member->mailing_address ?? '' }}</span></td>
            <td class="label">City:</td>
            <td><span class="line">{{ $member->city ?? '' }}</span></td>
        </tr>
    </table>
    <table class="row-table">
        <tr>
            <td class="label">State:</td>
            <td><span class="line">{{ $member->state ?? '' }}</span></td>
            <td class="label">Zip:</td>
            <td><span class="line">{{ $member->zip ?? '' }}</span></td>
            <td class="label">E-Mail:</td>
            <td><span class="line">{{ $member->email_address ?? '' }}</span></td>
        </tr>
    </table>
    <table class="row-table">
        <tr>
            <td class="label">School:</td>
            <td><span class="line">{{ $member->school ?? '' }}</span></td>
            <td class="label">Grade:</td>
            <td><span class="line">{{ $member->grade ?? '' }}</span></td>
        </tr>
    </table>
    <table class="row-table">
        <tr>
            <td class="label">Father/Guardian:</td>
            <td><span class="line">{{ $member->father_guardian_name ?? '' }}</span></td>
            <td class="label">E-Mail:</td>
            <td><span class="line">{{ $member->father_guardian_email ?? '' }}</span></td>
            <td class="label">Phone:</td>
            <td><span class="line">{{ $member->father_guardian_phone ?? '' }}</span></td>
        </tr>
    </table>
    <table class="row-table">
        <tr>
            <td class="label">Mother/Guardian:</td>
            <td><span class="line">{{ $member->mother_guardian_name ?? '' }}</span></td>
            <td class="label">E-Mail:</td>
            <td><span class="line">{{ $member->mother_guardian_email ?? '' }}</span></td>
            <td class="label">Phone:</td>
            <td><span class="line">{{ $member->mother_guardian_phone ?? '' }}</span></td>
        </tr>
    </table>

    <div class="section-note">
        Please list person(s) authorized to pick up your child from Pathfinder functions:
    </div>
    <div class="line multiline">{{ $pickupPeople }}</div>

    <div class="section-note">
        Parents/Guardians your child will be released from Pathfinder functions only to persons listed above. If other arrangements are necessary, a note must accompany your child and a call must be made to the Club Director prior to the Pathfinder function. No exceptions! Thank you for your cooperation.
    </div>

    <div class="section-note">
        We the Parent/Guardians of the above named Pathfinder applicant have read the Pathfinder Pledge, Law, rules and objective of this Pathfinder club and are desirous that the above named become a Pathfinder. We will assist the applicant with observance of the rules, maintaining an understanding the Pathfinder Pledge and Law, as well as assisting with the objectives of this Pathfinder Club. We also waive any and all claims against the Club Leadership, Pathfinder Club, Conference, Union, or North American Division of Seventh-day Adventists, for any accidents which may arise in connection with the activities of this Pathfinder Club, as permitted by law.
    </div>

    <table class="row-table">
        <tr>
            <td class="label">I/we also understand my child may be photographed or video taped and I/we release all rights for their picture or video to be used for printed and web publications and advertising as permitted by law.</td>
        </tr>
    </table>
    <table class="row-table">
        <tr>
            <td class="label">Parents/Guardians Signature:</td>
            <td><span class="line">{{ $guardianSignature }}</span></td>
            <td class="label">Date:</td>
            <td><span class="line">{{ $signedAt }}</span></td>
        </tr>
    </table>

    <div class="section-note">
        The following information is critical for the safe care of your Pathfinder during routine Pathfinder activities and emergencies. Please answer all questions as to "yes" or "no" and list any additional information needed.
    </div>

    <div class="question-list">
        <div class="question-row"><span class="checkbox">{{ $checkbox(!empty($member->health_history)) }}</span> Does your child have any health history? <span class="small-note">{{ $member->health_history ?? '' }}</span></div>
        <div class="question-row"><span class="checkbox">{{ $checkbox(!empty($member->disabilities)) }}</span> Does your child have any difficulties that would effect them during any Pathfinder function? <span class="small-note">{{ $member->disabilities ?? '' }}</span></div>
        <div class="question-row"><span class="checkbox">{{ $checkbox(!empty($member->medication_allergies)) }}</span> Does your child have any allergies to medications? <span class="small-note">{{ $member->medication_allergies ?? '' }}</span></div>
        <div class="question-row"><span class="checkbox">{{ $checkbox(!empty($member->food_allergies)) }}</span> Does your child have any allergies to foods? <span class="small-note">{{ $member->food_allergies ?? '' }}</span></div>
        <div class="question-row"><span class="checkbox">{{ $checkbox(!empty($member->dietary_considerations)) }}</span> Are there any dietary considerations which should be considered when planning a menu? <span class="small-note">{{ $member->dietary_considerations ?? '' }}</span></div>
        <div class="question-row"><span class="checkbox">{{ $checkbox(!empty($member->physical_restrictions)) }}</span> Are there any physical restrictions that would effect your child during Pathfinder functions? <span class="small-note">{{ $member->physical_restrictions ?? '' }}</span></div>
        <div class="question-row"><span class="checkbox">{{ $checkbox(!empty($member->immunization_notes)) }}</span> Are all shot records up to date? <span class="small-note">{{ $member->immunization_notes ?? '' }}</span></div>
        <div class="question-row"><span class="checkbox">{{ $checkbox(!empty($member->current_medications)) }}</span> Is your child currently on any medications? <span class="small-note">{{ $member->current_medications ?? '' }}</span></div>
    </div>

    <table class="row-table" style="margin-top: 8px;">
        <tr>
            <td class="label">Primary Physician:</td>
            <td><span class="line">{{ $member->physician_name ?? '' }}</span></td>
            <td class="label">Phone:</td>
            <td><span class="line">{{ $member->physician_phone ?? '' }}</span></td>
        </tr>
    </table>
    <table class="row-table">
        <tr>
            <td class="label">Emergency Contact:</td>
            <td><span class="line">{{ $member->emergency_contact_name ?? '' }}</span></td>
            <td class="label">Phone:</td>
            <td><span class="line">{{ $member->emergency_contact_phone ?? '' }}</span></td>
        </tr>
    </table>
    <table class="row-table">
        <tr>
            <td class="label">Medical Insurance:</td>
            <td><span class="line">{{ $member->insurance_provider ?? '' }}</span></td>
            <td class="label">Number:</td>
            <td><span class="line">{{ $member->insurance_number ?? '' }}</span></td>
        </tr>
    </table>
    <div class="small-note">(Please provide Pathfinder Club a copy of insurance card)</div>

    <div class="section-note">
        Being the Parents/Guardians of the applicant I/we certify the above medical history and information is correct to the best of our knowledge and the applicant has permission to engage in all Pathfinder activities except those noted. In the event the I/we cannot be reached in an emergency, permission to the adult leader to whom the applicant is charged to hospitalize, secure proper anesthesia or physician, order injection, surgery, resuscitation, or any care deemed necessary by that leader or physician to insure safe return of said applicant to his/her Parents/Guardians.
    </div>

    <table class="row-table">
        <tr>
            <td class="label">Parent/Guardian:</td>
            <td><span class="line">{{ $guardianPrimary }}</span></td>
            <td class="label">Date:</td>
            <td><span class="line">{{ $signedAt }}</span></td>
        </tr>
    </table>
    <table class="row-table"><tr><td class="label">Parent/Guardian:</td><td><span class="line"></span></td><td class="label">Date:</td><td><span class="line"></span></td></tr></table>
    <table class="row-table"><tr><td class="label">Parent/Guardian:</td><td><span class="line"></span></td><td class="label">Date:</td><td><span class="line"></span></td></tr></table>
    <table class="row-table"><tr><td class="label">Parent/Guardian:</td><td><span class="line"></span></td><td class="label">Date:</td><td><span class="line"></span></td></tr></table>

    <div class="footer-warning">
        Form must be filled out/reviewed, signed, and dated each year for the applicant to be officially recognized by Chesapeake Conference of Seventh-day Adventists.
    </div>
</body>
</html>
