<?php

namespace App\Services\Mail;

use App\Mail\AdventurerInductionRequestMail;
use App\Mail\AdventurerYearlyApplicationMail;
use App\Mail\AdventurerYearlyApplicationSignatureRequestMail;
use App\Mail\ConferenceMemberExportMail;
use App\Mail\FinanceLedgerReportMail;
use App\Mail\ParentEmailVerificationMail;
use App\Mail\ParentPasswordResetMail;
use App\Mail\ParentPaymentSubmissionMail;
use App\Mail\PathfinderAnnualApplicationMail;
use App\Mail\PathfinderAnnualApplicationSignatureRequestMail;
use App\Mail\PathfinderMonthlyReportMail;
use App\Mail\PaymentReceiptMail;
use App\Models\AdventurerInductionRequest;
use App\Models\AdventurerYearlyApplication;
use App\Models\AdventurerYearlyApplicationSignature;
use App\Models\Club;
use App\Models\MailDeliveryLog;
use App\Models\ParentPaymentSubmission;
use App\Models\PathfinderAnnualApplication;
use App\Models\PathfinderAnnualApplicationSignature;
use App\Models\PathfinderMonthlyReport;
use App\Models\PaymentReceipt;
use App\Models\User;
use App\Services\ParentPaymentProofService;
use App\Services\PaymentReceiptPdfService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

class MailerService
{
    public function __construct(
        private readonly PaymentReceiptPdfService $receiptPdfService,
        private readonly ParentPaymentProofService $parentPaymentProofService,
    ) {}

    public function sendParentEmailVerification(User $parent): void
    {
        $actionUrl = URL::temporarySignedRoute(
            'parent.email.verify',
            now()->addHours(24),
            ['user' => $parent->id, 'hash' => sha1(mb_strtolower($parent->email))],
        );

        $this->sendParentAccountMail(
            parent: $parent,
            mailKey: 'parent_email_verification',
            mailable: ParentEmailVerificationMail::class,
            subject: 'Confirma tu correo | Confirm your email',
            actionUrl: $actionUrl,
            view: 'emails.parent_email_verification',
        );
    }

    public function sendParentPasswordReset(User $parent, string $token): void
    {
        $actionUrl = route('password.reset', [
            'token' => $token,
            'email' => $parent->email,
        ]);

        $this->sendParentAccountMail(
            parent: $parent,
            mailKey: 'parent_password_reset',
            mailable: ParentPasswordResetMail::class,
            subject: 'Restablece tu contraseña | Reset your password',
            actionUrl: $actionUrl,
            view: 'emails.parent_password_reset',
        );
    }

    private function sendParentAccountMail(
        User $parent,
        string $mailKey,
        string $mailable,
        string $subject,
        string $actionUrl,
        string $view,
    ): void {
        $mailLog = $this->startLog(
            mailKey: $mailKey,
            mailable: $mailable,
            recipientEmail: $parent->email,
            subject: $subject,
            loggable: $parent,
            clubId: $parent->club_id,
            userId: $parent->id,
            sourceLabel: 'Portal de Padres',
            destinationLabel: $parent->name,
            metadata: ['action_url_expires_hours' => 24],
        );
        $trackingPixelUrl = $this->trackingPixelUrl($mailLog);
        $bodyHtml = view($view, compact('parent', 'actionUrl', 'trackingPixelUrl'))->render();
        $mailLog->forceFill([
            'body_html' => $bodyHtml,
            'body_text' => $this->bodyText($bodyHtml),
            'metadata' => array_merge($mailLog->metadata ?: [], ['tracking_pixel_url' => $trackingPixelUrl]),
        ])->save();

        try {
            $message = $mailable === ParentEmailVerificationMail::class
                ? new ParentEmailVerificationMail($parent, $actionUrl, $trackingPixelUrl, $mailLog->email_uid)
                : new ParentPasswordResetMail($parent, $actionUrl, $trackingPixelUrl, $mailLog->email_uid);
            Mail::to($parent->email)->send($message);
            $this->markSent($mailLog);
        } catch (Throwable $exception) {
            $this->markFailed($mailLog, $exception);
            throw $exception;
        }
    }

    public function queuePaymentReceipt(PaymentReceipt $receipt): MailDeliveryLog
    {
        $receipt = $this->receiptPdfService->loadReceiptContext($receipt);
        $subject = "Recibo de pago {$receipt->receipt_number}";
        $mailLog = $this->startLog(
            mailKey: 'payment_receipt',
            mailable: PaymentReceiptMail::class,
            recipientEmail: $receipt->issued_to_email ?: 'missing-recipient@internal.local',
            subject: $subject,
            loggable: $receipt,
            clubId: $receipt->club_id,
            userId: $receipt->parent_user_id ?: $receipt->staff_user_id,
            sourceLabel: 'Club Portal - Recibos de pago',
            destinationLabel: $receipt->issued_to_type ?: 'payment_receipt_recipient',
            bodyHtml: null,
            metadata: [
                'receipt_number' => $receipt->receipt_number,
                'issued_to_type' => $receipt->issued_to_type,
            ],
        );

        return $this->updatePaymentReceiptBody($mailLog, $receipt);
    }

    public function sendPaymentReceipt(PaymentReceipt $receipt, ?MailDeliveryLog $mailLog = null): void
    {
        $receipt = $this->receiptPdfService->loadReceiptContext($receipt);
        $subject = "Recibo de pago {$receipt->receipt_number}";
        $sourceLabel = 'Club Portal - Recibos de pago';
        $destinationLabel = $receipt->issued_to_type ?: 'payment_receipt_recipient';

        if (empty($receipt->issued_to_email)) {
            if ($mailLog) {
                $mailLog->forceFill([
                    'status' => 'manual_required',
                    'failed_at' => null,
                    'sent_at' => null,
                    'error_message' => null,
                ])->save();
            } else {
                $this->logManualRequired(
                    mailKey: 'payment_receipt',
                    mailable: PaymentReceiptMail::class,
                    subject: $subject,
                    loggable: $receipt,
                    clubId: $receipt->club_id,
                    sourceLabel: $sourceLabel,
                    destinationLabel: $destinationLabel,
                    bodyHtml: view('emails.payment_receipt', ['receipt' => $receipt])->render(),
                    metadata: [
                        'receipt_number' => $receipt->receipt_number,
                        'reason' => 'missing_recipient_email',
                    ],
                );
            }

            $receipt->forceFill([
                'delivery_status' => 'manual_required',
                'delivered_at' => null,
            ])->save();

            return;
        }

        if (! $mailLog) {
            $mailLog = $this->startLog(
                mailKey: 'payment_receipt',
                mailable: PaymentReceiptMail::class,
                recipientEmail: $receipt->issued_to_email,
                subject: $subject,
                loggable: $receipt,
                clubId: $receipt->club_id,
                userId: $receipt->parent_user_id ?: $receipt->staff_user_id,
                sourceLabel: $sourceLabel,
                destinationLabel: $destinationLabel,
                bodyHtml: $bodyHtml,
                metadata: [
                    'receipt_number' => $receipt->receipt_number,
                    'issued_to_type' => $receipt->issued_to_type,
                ],
            );
        }

        $mailLog = $this->updatePaymentReceiptBody($mailLog, $receipt);
        $trackingPixelUrl = $this->trackingPixelUrl($mailLog);

        try {
            $pdf = $this->receiptPdfService->make($receipt)->output();

            Mail::to($receipt->issued_to_email)
                ->send(new PaymentReceiptMail($receipt, $pdf, $trackingPixelUrl, $mailLog->email_uid));

            $this->markSent($mailLog);

            $receipt->forceFill([
                'delivery_status' => 'sent',
                'delivered_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            $this->markFailed($mailLog, $exception);

            $receipt->forceFill([
                'delivery_status' => 'failed',
                'delivered_at' => null,
            ])->save();

            throw $exception;
        }
    }

    public function sendParentPaymentSubmission(ParentPaymentSubmission $submission): void
    {
        $submission->loadMissing([
            'club:id,club_name,club_email',
            'concept:id,concept',
            'member:id,type,id_data',
            'parentUser:id,name,email',
        ]);

        $recipient = $submission->club_receipt_email ?: $submission->club?->club_email;
        $subject = 'Comprobante de pago enviado por padre';
        $sourceLabel = 'Portal de padres - Comprobante de pago';
        $destinationLabel = 'Club: '.($submission->club?->club_name ?: 'Club');

        if (empty($recipient)) {
            $this->logManualRequired(
                mailKey: 'parent_payment_submission',
                mailable: ParentPaymentSubmissionMail::class,
                subject: $subject,
                loggable: $submission,
                clubId: $submission->club_id,
                userId: $submission->parent_user_id,
                sourceLabel: $sourceLabel,
                destinationLabel: $destinationLabel,
                bodyHtml: view('emails.parent_payment_submission', ['submission' => $submission])->render(),
                metadata: [
                    'reason' => 'missing_club_email',
                    'member_id' => $submission->member_id,
                ],
            );

            $submission->forceFill([
                'club_receipt_email_status' => 'manual_required',
                'club_receipt_emailed_at' => null,
            ])->save();

            return;
        }

        $mailLog = $this->startLog(
            mailKey: 'parent_payment_submission',
            mailable: ParentPaymentSubmissionMail::class,
            recipientEmail: $recipient,
            subject: $subject,
            loggable: $submission,
            clubId: $submission->club_id,
            userId: $submission->parent_user_id,
            sourceLabel: $sourceLabel,
            destinationLabel: $destinationLabel,
            bodyHtml: null,
            metadata: [
                'member_id' => $submission->member_id,
                'concept_id' => $submission->payment_concept_id,
            ],
        );
        $mailLog = $this->updateParentPaymentSubmissionBody($mailLog, $submission);
        $trackingPixelUrl = $this->trackingPixelUrl($mailLog);

        try {
            $path = $submission->receipt_image_path;

            Mail::to($recipient)
                ->send(new ParentPaymentSubmissionMail(
                    submission: $submission,
                    receiptImageContents: $this->parentPaymentProofService->contents($submission),
                    receiptImageName: basename($path),
                    receiptImageMime: $this->parentPaymentProofService->mimeType($submission),
                    trackingPixelUrl: $trackingPixelUrl,
                    emailUid: $mailLog->email_uid,
                ));

            $this->markSent($mailLog);

            $submission->forceFill([
                'club_receipt_email' => $recipient,
                'club_receipt_email_status' => 'sent',
                'club_receipt_emailed_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            $this->markFailed($mailLog, $exception);

            $submission->forceFill([
                'club_receipt_email' => $recipient,
                'club_receipt_email_status' => 'failed',
                'club_receipt_emailed_at' => null,
            ])->save();

            throw $exception;
        }
    }

    public function sendConferenceMemberExport(
        int $clubId,
        string $recipientEmail,
        string $zipPath,
        string $zipFilename,
        int $memberCount,
        ?int $userId = null,
        string $exportType = 'member',
    ): MailDeliveryLog {
        $club = Club::query()->findOrFail($clubId);
        $isStaffExport = $exportType === 'staff';
        $mailKey = $isStaffExport ? 'conference_staff_export' : 'conference_member_export';
        $exportLabel = $isStaffExport ? 'personal' : 'miembros';
        $exportCountLabel = $isStaffExport ? 'Personal incluido' : 'Miembros incluidos';
        $subject = "Registro de {$exportLabel} - {$club->club_name}";

        $mailLog = $this->startLog(
            mailKey: $mailKey,
            mailable: ConferenceMemberExportMail::class,
            recipientEmail: $recipientEmail,
            subject: $subject,
            loggable: $club,
            clubId: $club->id,
            userId: $userId,
            sourceLabel: 'Club Portal - Exportacion de miembros',
            destinationLabel: 'Conferencia',
            bodyHtml: null,
            metadata: [
                'export_type' => $exportType,
                'item_count' => $memberCount,
                'zip_filename' => $zipFilename,
            ],
        );

        $trackingPixelUrl = $this->trackingPixelUrl($mailLog);
        $bodyHtml = view('emails.conference_member_export', [
            'club' => $club,
            'memberCount' => $memberCount,
            'exportLabel' => $exportLabel,
            'exportCountLabel' => $exportCountLabel,
            'trackingPixelUrl' => $trackingPixelUrl,
            'emailUid' => $mailLog->email_uid,
        ])->render();

        $metadata = $mailLog->metadata ?: [];
        $metadata['tracking_pixel_url'] = $trackingPixelUrl;

        $mailLog->forceFill([
            'body_html' => $bodyHtml,
            'body_text' => $this->bodyText($bodyHtml),
            'metadata' => $metadata,
        ])->save();

        try {
            $zipContents = is_file($zipPath) ? file_get_contents($zipPath) : false;
            if ($zipContents === false) {
                throw new \RuntimeException('Member export ZIP could not be read.');
            }

            Mail::to($recipientEmail)
                ->send(new ConferenceMemberExportMail(
                    club: $club,
                    memberCount: $memberCount,
                    zipContents: $zipContents,
                    zipFilename: $zipFilename,
                    exportType: $exportType,
                    exportLabel: $exportLabel,
                    trackingPixelUrl: $trackingPixelUrl,
                    emailUid: $mailLog->email_uid,
                ));

            $this->markSent($mailLog);
        } catch (Throwable $exception) {
            $this->markFailed($mailLog, $exception);

            throw $exception;
        }

        return $mailLog->refresh();
    }

    public function sendFinanceLedgerReport(
        int $clubId,
        string $recipientEmail,
        array $exportPayload,
        array $filters = [],
        ?int $userId = null,
    ): MailDeliveryLog {
        $club = Club::query()->findOrFail($clubId);
        $subject = "Reporte financiero - {$club->club_name}";
        $files = $this->financeLedgerReportAttachments($exportPayload);

        $mailLog = $this->startLog(
            mailKey: 'finance_ledger_report',
            mailable: FinanceLedgerReportMail::class,
            recipientEmail: $recipientEmail,
            subject: $subject,
            loggable: $club,
            clubId: $club->id,
            userId: $userId,
            sourceLabel: 'Club Portal - Reportes financieros',
            destinationLabel: 'Destinatario externo',
            bodyHtml: null,
            metadata: [
                'filters' => $filters,
                'attachments' => collect($files)->map(fn (array $file) => [
                    'file_name' => $file['file_name'],
                    'label' => $file['label'] ?? null,
                    'size' => $file['size'] ?? null,
                ])->all(),
            ],
        );

        $trackingPixelUrl = $this->trackingPixelUrl($mailLog);
        $bodyHtml = view('emails.finance_ledger_report', [
            'club' => $club,
            'files' => $files,
            'filters' => $filters,
            'trackingPixelUrl' => $trackingPixelUrl,
            'emailUid' => $mailLog->email_uid,
        ])->render();

        $metadata = $mailLog->metadata ?: [];
        $metadata['tracking_pixel_url'] = $trackingPixelUrl;

        $mailLog->forceFill([
            'body_html' => $bodyHtml,
            'body_text' => $this->bodyText($bodyHtml),
            'metadata' => $metadata,
        ])->save();

        try {
            Mail::to($recipientEmail)
                ->send(new FinanceLedgerReportMail(
                    club: $club,
                    files: $files,
                    filters: $filters,
                    trackingPixelUrl: $trackingPixelUrl,
                    emailUid: $mailLog->email_uid,
                ));

            $this->markSent($mailLog);
        } catch (Throwable $exception) {
            $this->markFailed($mailLog, $exception);

            throw $exception;
        }

        return $mailLog->refresh();
    }

    public function sendPathfinderAnnualApplication(
        PathfinderAnnualApplication $application,
        string $recipientEmail,
        ?int $userId = null,
    ): MailDeliveryLog {
        $application->loadMissing('club');
        $club = $application->club;
        $subject = "Pathfinder Club Yearly Application - {$club?->club_name} {$application->application_year}";

        $mailLog = $this->startLog(
            mailKey: 'pathfinder_annual_application',
            mailable: PathfinderAnnualApplicationMail::class,
            recipientEmail: $recipientEmail,
            subject: $subject,
            loggable: $application,
            clubId: $application->club_id,
            userId: $userId,
            sourceLabel: 'Club Portal - Aplicacion anual Pathfinder',
            destinationLabel: 'Destinatario de aplicacion anual',
            bodyHtml: null,
            metadata: [
                'application_year' => $application->application_year,
                'pdf_file_name' => $application->pdf_file_name,
            ],
        );

        $trackingPixelUrl = $this->trackingPixelUrl($mailLog);
        $bodyHtml = view('emails.pathfinder_annual_application', [
            'application' => $application,
            'trackingPixelUrl' => $trackingPixelUrl,
            'emailUid' => $mailLog->email_uid,
        ])->render();

        $metadata = $mailLog->metadata ?: [];
        $metadata['tracking_pixel_url'] = $trackingPixelUrl;

        $mailLog->forceFill([
            'body_html' => $bodyHtml,
            'body_text' => $this->bodyText($bodyHtml),
            'metadata' => $metadata,
        ])->save();

        try {
            $disk = Storage::disk('public');
            if (! $application->pdf_path || ! $disk->exists($application->pdf_path)) {
                throw new \RuntimeException('Pathfinder annual application PDF could not be read.');
            }

            Mail::to($recipientEmail)
                ->send(new PathfinderAnnualApplicationMail(
                    application: $application,
                    pdfContents: $disk->get($application->pdf_path),
                    pdfFilename: $application->pdf_file_name ?: 'pathfinder-annual-application.pdf',
                    trackingPixelUrl: $trackingPixelUrl,
                    emailUid: $mailLog->email_uid,
                ));

            $this->markSent($mailLog);

            $application->forceFill([
                'last_sent_to_email' => $recipientEmail,
                'delivery_status' => 'sent',
                'sent_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            $this->markFailed($mailLog, $exception);

            $application->forceFill([
                'last_sent_to_email' => $recipientEmail,
                'delivery_status' => 'failed',
                'sent_at' => null,
            ])->save();

            throw $exception;
        }

        return $mailLog->refresh();
    }

    public function sendAdventurerYearlyApplication(
        AdventurerYearlyApplication $application,
        string $recipientEmail,
        ?int $userId = null,
    ): MailDeliveryLog {
        $application->loadMissing('club');
        $subject = "Adventurer Club Yearly Application - {$application->club_name} {$application->application_year}";

        $mailLog = $this->startLog(
            mailKey: 'adventurer_yearly_application',
            mailable: AdventurerYearlyApplicationMail::class,
            recipientEmail: $recipientEmail,
            subject: $subject,
            loggable: $application,
            clubId: $application->club_id,
            userId: $userId,
            sourceLabel: 'Club Portal - Solicitud anual de Aventureros',
            destinationLabel: 'Destinatario de solicitud anual',
            bodyHtml: null,
            metadata: [
                'application_year' => $application->application_year,
                'docx_file_name' => $application->docx_file_name,
            ],
        );

        $trackingPixelUrl = $this->trackingPixelUrl($mailLog);
        $bodyHtml = view('emails.adventurer_yearly_application', [
            'application' => $application,
            'trackingPixelUrl' => $trackingPixelUrl,
            'emailUid' => $mailLog->email_uid,
        ])->render();
        $metadata = $mailLog->metadata ?: [];
        $metadata['tracking_pixel_url'] = $trackingPixelUrl;
        $mailLog->forceFill([
            'body_html' => $bodyHtml,
            'body_text' => $this->bodyText($bodyHtml),
            'metadata' => $metadata,
        ])->save();

        try {
            $disk = Storage::disk('public');
            if (! $application->docx_path || ! $disk->exists($application->docx_path)) {
                throw new \RuntimeException('Adventurer yearly application Word document could not be read.');
            }

            Mail::to($recipientEmail)->send(new AdventurerYearlyApplicationMail(
                application: $application,
                docxPath: $application->docx_path,
                docxFilename: $application->docx_file_name ?: 'adventurer-yearly-application.docx',
                trackingPixelUrl: $trackingPixelUrl,
                emailUid: $mailLog->email_uid,
            ));

            $this->markSent($mailLog);
            $application->forceFill([
                'last_sent_to_email' => $recipientEmail,
                'delivery_status' => 'sent',
                'sent_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            $this->markFailed($mailLog, $exception);
            $application->forceFill([
                'last_sent_to_email' => $recipientEmail,
                'delivery_status' => 'failed',
                'sent_at' => null,
            ])->save();
            throw $exception;
        }

        return $mailLog->refresh();
    }

    public function sendAdventurerInductionRequest(
        AdventurerInductionRequest $inductionRequest,
        string $recipientEmail,
        ?int $userId = null,
    ): MailDeliveryLog {
        $inductionRequest->loadMissing('club');
        $subject = "Adventurer Induction Attendance Request - {$inductionRequest->club_name}";

        $mailLog = $this->startLog(
            mailKey: 'adventurer_induction_request',
            mailable: AdventurerInductionRequestMail::class,
            recipientEmail: $recipientEmail,
            subject: $subject,
            loggable: $inductionRequest,
            clubId: $inductionRequest->club_id,
            userId: $userId,
            sourceLabel: 'Club Portal - Solicitud de inducción de Aventureros',
            destinationLabel: 'Destinatario de solicitud de inducción',
            bodyHtml: null,
            metadata: [
                'induction_date' => optional($inductionRequest->induction_date)->toDateString(),
                'docx_file_name' => $inductionRequest->docx_file_name,
            ],
        );

        $trackingPixelUrl = $this->trackingPixelUrl($mailLog);
        $bodyHtml = view('emails.adventurer_induction_request', [
            'inductionRequest' => $inductionRequest,
            'trackingPixelUrl' => $trackingPixelUrl,
            'emailUid' => $mailLog->email_uid,
        ])->render();
        $metadata = $mailLog->metadata ?: [];
        $metadata['tracking_pixel_url'] = $trackingPixelUrl;
        $mailLog->forceFill([
            'body_html' => $bodyHtml,
            'body_text' => $this->bodyText($bodyHtml),
            'metadata' => $metadata,
        ])->save();

        try {
            $disk = Storage::disk('public');
            if (! $inductionRequest->docx_path || ! $disk->exists($inductionRequest->docx_path)) {
                throw new \RuntimeException('Adventurer induction request Word document could not be read.');
            }

            Mail::to($recipientEmail)->send(new AdventurerInductionRequestMail(
                inductionRequest: $inductionRequest,
                docxPath: $inductionRequest->docx_path,
                docxFilename: $inductionRequest->docx_file_name ?: 'adventurer-induction-request.docx',
                trackingPixelUrl: $trackingPixelUrl,
                emailUid: $mailLog->email_uid,
            ));

            $this->markSent($mailLog);
            $inductionRequest->forceFill([
                'last_sent_to_email' => $recipientEmail,
                'status' => 'emailed',
            ])->save();
        } catch (Throwable $exception) {
            $this->markFailed($mailLog, $exception);
            $inductionRequest->forceFill([
                'last_sent_to_email' => $recipientEmail,
                'emailed_at' => null,
                'status' => 'failed',
            ])->save();
            throw $exception;
        }

        return $mailLog->refresh();
    }

    public function sendAdventurerYearlyApplicationSignatureRequest(
        AdventurerYearlyApplicationSignature $signature,
        ?int $userId = null,
    ): MailDeliveryLog {
        $signature->loadMissing('application.club');
        $application = $signature->application;
        $signatureUrl = route('adventurer-yearly-applications.signatures.show', [
            'token' => $signature->request_token,
        ]);
        $roleLabel = match ($signature->role) {
            'pastor' => 'Church Pastor',
            'head_elder' => 'Head Elder',
            'church_clerk' => 'Church Clerk',
            'director' => 'Club Director',
            default => $signature->role,
        };
        $subject = "Signature requested: Adventurer Club Yearly Application - {$application?->club_name}";

        $mailLog = $this->startLog(
            mailKey: 'adventurer_yearly_application_signature_request',
            mailable: AdventurerYearlyApplicationSignatureRequestMail::class,
            recipientEmail: $signature->signer_email,
            subject: $subject,
            loggable: $signature,
            clubId: $application->club_id,
            userId: $userId,
            sourceLabel: 'Club Portal - Solicitud de firma de Aventureros',
            destinationLabel: $roleLabel,
            bodyHtml: null,
            metadata: [
                'application_year' => $application->application_year,
                'signature_role' => $signature->role,
                'signature_url' => $signatureUrl,
            ],
        );

        $trackingPixelUrl = $this->trackingPixelUrl($mailLog);
        $bodyHtml = view('emails.adventurer_yearly_application_signature_request', [
            'signature' => $signature,
            'signatureUrl' => $signatureUrl,
            'trackingPixelUrl' => $trackingPixelUrl,
            'emailUid' => $mailLog->email_uid,
        ])->render();
        $metadata = $mailLog->metadata ?: [];
        $metadata['tracking_pixel_url'] = $trackingPixelUrl;
        $mailLog->forceFill([
            'body_html' => $bodyHtml,
            'body_text' => $this->bodyText($bodyHtml),
            'metadata' => $metadata,
        ])->save();

        try {
            Mail::to($signature->signer_email)->send(new AdventurerYearlyApplicationSignatureRequestMail(
                signature: $signature,
                signatureUrl: $signatureUrl,
                trackingPixelUrl: $trackingPixelUrl,
                emailUid: $mailLog->email_uid,
            ));
            $this->markSent($mailLog);
        } catch (Throwable $exception) {
            $this->markFailed($mailLog, $exception);
            throw $exception;
        }

        return $mailLog->refresh();
    }

    public function sendPathfinderAnnualApplicationSignatureRequest(
        PathfinderAnnualApplicationSignature $signature,
        ?int $userId = null,
    ): MailDeliveryLog {
        $signature->loadMissing('application.club');
        $application = $signature->application;
        $club = $application->club;
        $signatureUrl = route('pathfinder-annual-applications.signatures.show', [
            'token' => $signature->request_token,
        ]);
        $roleLabel = match ($signature->role) {
            'pastor' => 'Church Pastor',
            'head_elder' => 'Head Elder',
            'director' => 'Club Director',
            default => $signature->role,
        };
        $subject = "Signature requested: Pathfinder Club Yearly Application - {$club?->club_name}";

        $mailLog = $this->startLog(
            mailKey: 'pathfinder_annual_application_signature_request',
            mailable: PathfinderAnnualApplicationSignatureRequestMail::class,
            recipientEmail: $signature->signer_email,
            subject: $subject,
            loggable: $signature,
            clubId: $application->club_id,
            userId: $userId,
            sourceLabel: 'Club Portal - Solicitud de firma Pathfinder',
            destinationLabel: $roleLabel,
            bodyHtml: null,
            metadata: [
                'application_year' => $application->application_year,
                'signature_role' => $signature->role,
                'signature_url' => $signatureUrl,
            ],
        );

        $trackingPixelUrl = $this->trackingPixelUrl($mailLog);
        $bodyHtml = view('emails.pathfinder_annual_application_signature_request', [
            'signature' => $signature,
            'signatureUrl' => $signatureUrl,
            'trackingPixelUrl' => $trackingPixelUrl,
            'emailUid' => $mailLog->email_uid,
        ])->render();

        $metadata = $mailLog->metadata ?: [];
        $metadata['tracking_pixel_url'] = $trackingPixelUrl;

        $mailLog->forceFill([
            'body_html' => $bodyHtml,
            'body_text' => $this->bodyText($bodyHtml),
            'metadata' => $metadata,
        ])->save();

        try {
            Mail::to($signature->signer_email)
                ->send(new PathfinderAnnualApplicationSignatureRequestMail(
                    signature: $signature,
                    signatureUrl: $signatureUrl,
                    trackingPixelUrl: $trackingPixelUrl,
                    emailUid: $mailLog->email_uid,
                ));

            $this->markSent($mailLog);
        } catch (Throwable $exception) {
            $this->markFailed($mailLog, $exception);

            throw $exception;
        }

        return $mailLog->refresh();
    }

    public function sendPathfinderMonthlyReport(
        PathfinderMonthlyReport $report,
        string $recipientEmail,
        ?int $userId = null,
    ): MailDeliveryLog {
        $report->loadMissing(['club', 'attachments']);
        $club = $report->club;
        $subject = "Pathfinder Monthly Report - {$club?->club_name} {$report->report_month} {$report->report_year}";

        $mailLog = $this->startLog(
            mailKey: 'pathfinder_monthly_report',
            mailable: PathfinderMonthlyReportMail::class,
            recipientEmail: $recipientEmail,
            subject: $subject,
            loggable: $report,
            clubId: $report->club_id,
            userId: $userId,
            sourceLabel: 'Club Portal - Reporte mensual Pathfinder',
            destinationLabel: 'Destinatario de reporte mensual',
            bodyHtml: null,
            metadata: [
                'report_year' => $report->report_year,
                'report_month' => $report->report_month,
                'pdf_file_name' => $report->pdf_file_name,
                'attachment_count' => $report->attachments->count(),
            ],
        );

        $trackingPixelUrl = $this->trackingPixelUrl($mailLog);
        $bodyHtml = view('emails.pathfinder_monthly_report', [
            'report' => $report,
            'trackingPixelUrl' => $trackingPixelUrl,
            'emailUid' => $mailLog->email_uid,
        ])->render();

        $metadata = $mailLog->metadata ?: [];
        $metadata['tracking_pixel_url'] = $trackingPixelUrl;

        $mailLog->forceFill([
            'body_html' => $bodyHtml,
            'body_text' => $this->bodyText($bodyHtml),
            'metadata' => $metadata,
        ])->save();

        try {
            $files = $this->pathfinderMonthlyReportAttachments($report);

            Mail::to($recipientEmail)
                ->send(new PathfinderMonthlyReportMail(
                    report: $report,
                    files: $files,
                    trackingPixelUrl: $trackingPixelUrl,
                    emailUid: $mailLog->email_uid,
                ));

            $this->markSent($mailLog);

            $report->forceFill([
                'last_sent_to_email' => $recipientEmail,
                'delivery_status' => 'sent',
                'sent_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            $this->markFailed($mailLog, $exception);

            $report->forceFill([
                'last_sent_to_email' => $recipientEmail,
                'delivery_status' => 'failed',
                'sent_at' => null,
            ])->save();

            throw $exception;
        }

        return $mailLog->refresh();
    }

    private function financeLedgerReportAttachments(array $exportPayload): array
    {
        $files = $exportPayload['files'] ?? [[
            'label' => 'Libro contable',
            'file_name' => $exportPayload['file_name'] ?? 'finance-ledger.pdf',
            'url' => $exportPayload['url'] ?? null,
            'size' => $exportPayload['size'] ?? null,
        ]];

        return collect($files)
            ->map(function (array $file): array {
                $path = parse_url((string) ($file['url'] ?? ''), PHP_URL_PATH);
                $absolutePath = $path ? public_path(ltrim($path, '/')) : null;

                if (! $absolutePath || ! is_file($absolutePath)) {
                    throw new \RuntimeException('Finance ledger report file could not be read.');
                }

                $contents = file_get_contents($absolutePath);
                if ($contents === false) {
                    throw new \RuntimeException('Finance ledger report file could not be read.');
                }

                return [
                    'label' => $file['label'] ?? null,
                    'file_name' => $file['file_name'] ?? basename($absolutePath),
                    'size' => $file['size'] ?? filesize($absolutePath),
                    'mime_type' => 'application/pdf',
                    'contents' => $contents,
                ];
            })
            ->values()
            ->all();
    }

    private function pathfinderMonthlyReportAttachments(PathfinderMonthlyReport $report): array
    {
        $disk = Storage::disk('public');

        if (! $report->pdf_path || ! $disk->exists($report->pdf_path)) {
            throw new \RuntimeException('Pathfinder monthly report PDF could not be read.');
        }

        $files = [[
            'label' => 'Reporte mensual',
            'file_name' => $report->pdf_file_name ?: 'pathfinder-monthly-report.pdf',
            'mime_type' => 'application/pdf',
            'contents' => $disk->get($report->pdf_path),
        ]];

        foreach ($report->attachments as $attachment) {
            $attachmentDisk = Storage::disk($attachment->disk ?: 'public');

            if (! $attachment->path || ! $attachmentDisk->exists($attachment->path)) {
                continue;
            }

            $files[] = [
                'label' => $attachment->kind,
                'file_name' => $attachment->original_name ?: basename($attachment->path),
                'mime_type' => $attachment->mime_type ?: 'application/octet-stream',
                'contents' => $attachmentDisk->get($attachment->path),
            ];
        }

        return $files;
    }

    private function startLog(
        string $mailKey,
        string $mailable,
        string $recipientEmail,
        string $subject,
        Model $loggable,
        ?int $clubId = null,
        ?int $userId = null,
        ?string $sourceLabel = null,
        ?string $destinationLabel = null,
        ?string $bodyHtml = null,
        array $metadata = [],
    ): MailDeliveryLog {
        return MailDeliveryLog::query()->create([
            'email_uid' => $this->newEmailUid(),
            'mail_key' => $mailKey,
            'mailable' => $mailable,
            'from_email' => config('mail.from.address'),
            'from_name' => config('mail.from.name'),
            'recipient_email' => $recipientEmail,
            'subject' => $subject,
            'source_label' => $sourceLabel,
            'destination_label' => $destinationLabel,
            'status' => 'queued',
            'loggable_type' => $loggable->getMorphClass(),
            'loggable_id' => $loggable->getKey(),
            'club_id' => $clubId,
            'user_id' => $userId,
            'queued_at' => now(),
            'body_html' => $bodyHtml,
            'body_text' => $this->bodyText($bodyHtml),
            'metadata' => $metadata,
        ]);
    }

    private function logManualRequired(
        string $mailKey,
        string $mailable,
        string $subject,
        Model $loggable,
        ?int $clubId = null,
        ?int $userId = null,
        ?string $sourceLabel = null,
        ?string $destinationLabel = null,
        ?string $bodyHtml = null,
        array $metadata = [],
    ): MailDeliveryLog {
        return MailDeliveryLog::query()->create([
            'email_uid' => $this->newEmailUid(),
            'mail_key' => $mailKey,
            'mailable' => $mailable,
            'from_email' => config('mail.from.address'),
            'from_name' => config('mail.from.name'),
            'recipient_email' => 'missing-recipient@internal.local',
            'subject' => $subject,
            'source_label' => $sourceLabel,
            'destination_label' => $destinationLabel,
            'status' => 'manual_required',
            'loggable_type' => $loggable->getMorphClass(),
            'loggable_id' => $loggable->getKey(),
            'club_id' => $clubId,
            'user_id' => $userId,
            'body_html' => $bodyHtml,
            'body_text' => $this->bodyText($bodyHtml),
            'metadata' => $metadata,
        ]);
    }

    private function markSent(MailDeliveryLog $mailLog): void
    {
        $mailLog->forceFill([
            'status' => 'sent',
            'sent_at' => now(),
            'failed_at' => null,
            'error_message' => null,
        ])->save();
    }

    private function markFailed(MailDeliveryLog $mailLog, Throwable $exception): void
    {
        $mailLog->forceFill([
            'status' => 'failed',
            'failed_at' => now(),
            'error_message' => mb_substr($exception->getMessage(), 0, 2000),
        ])->save();
    }

    private function updatePaymentReceiptBody(MailDeliveryLog $mailLog, PaymentReceipt $receipt): MailDeliveryLog
    {
        $metadata = $mailLog->metadata ?: [];
        $metadata['tracking_pixel_url'] = $this->trackingPixelUrl($mailLog);
        $bodyHtml = view('emails.payment_receipt', [
            'receipt' => $receipt,
            'trackingPixelUrl' => $metadata['tracking_pixel_url'],
            'emailUid' => $mailLog->email_uid,
        ])->render();

        $mailLog->forceFill([
            'body_html' => $bodyHtml,
            'body_text' => $this->bodyText($bodyHtml),
            'metadata' => $metadata,
        ])->save();

        return $mailLog->refresh();
    }

    private function updateParentPaymentSubmissionBody(MailDeliveryLog $mailLog, ParentPaymentSubmission $submission): MailDeliveryLog
    {
        $metadata = $mailLog->metadata ?: [];
        $metadata['tracking_pixel_url'] = $this->trackingPixelUrl($mailLog);
        $bodyHtml = view('emails.parent_payment_submission', [
            'submission' => $submission,
            'trackingPixelUrl' => $metadata['tracking_pixel_url'],
            'emailUid' => $mailLog->email_uid,
        ])->render();

        $mailLog->forceFill([
            'body_html' => $bodyHtml,
            'body_text' => $this->bodyText($bodyHtml),
            'metadata' => $metadata,
        ])->save();

        return $mailLog->refresh();
    }

    private function trackingPixelUrl(MailDeliveryLog $mailLog): string
    {
        return URL::signedRoute('mail-tracking.open', ['mailLog' => $mailLog]);
    }

    private function newEmailUid(): string
    {
        do {
            $uid = 'mail_'.strtolower((string) Str::ulid());
        } while (MailDeliveryLog::query()->where('email_uid', $uid)->exists());

        return $uid;
    }

    private function bodyText(?string $bodyHtml): ?string
    {
        if (! $bodyHtml) {
            return null;
        }

        return trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $bodyHtml))));
    }
}
