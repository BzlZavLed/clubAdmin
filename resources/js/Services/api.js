import axios from "axios";

export const fetchClubsByChurch = async (churchName) => {
    const response = await axios.get(route("clubs.by-church-name"), {
        params: { church_name: churchName },
    });
    return response.data;
};

export const fetchStaffRecord = async () => {
    const response = await axios.get("/staff/staff-record"); // Not named
    return response.data;
};

export const fetchParentReceipts = async () => {
    const response = await axios.get(route("parent.receipts.index"));
    return response.data;
};

export const fetchStaffReceipts = async () => {
    const response = await axios.get(route("club.personal.receipts.index"));
    return response.data;
};

export const downloadBulkReceipts = async (receiptIds, label = "payment-receipts") => {
    const csrf = document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute("content");

    const iframeName = `receipt-download-${Date.now()}`;
    const iframe = document.createElement("iframe");
    iframe.name = iframeName;
    iframe.style.display = "none";
    document.body.appendChild(iframe);

    const form = document.createElement("form");
    form.method = "POST";
    form.action = route("payment-receipts.download-bulk");
    form.target = iframeName;
    form.style.display = "none";

    if (csrf) {
        const csrfInput = document.createElement("input");
        csrfInput.type = "hidden";
        csrfInput.name = "_token";
        csrfInput.value = csrf;
        form.appendChild(csrfInput);
    }

    const labelInput = document.createElement("input");
    labelInput.type = "hidden";
    labelInput.name = "label";
    labelInput.value = label;
    form.appendChild(labelInput);

    receiptIds.forEach((id) => {
        const input = document.createElement("input");
        input.type = "hidden";
        input.name = "receipt_ids[]";
        input.value = id;
        form.appendChild(input);
    });

    document.body.appendChild(form);
    form.submit();

    window.setTimeout(() => {
        form.remove();
        iframe.remove();
    }, 4000);
};

export const fetchFinanceLedgerExportHistory = async (params = {}) => {
    const { data } = await axios.get(route("club.finance-engine.movements.pdf-exports.index"), { params });
    return data;
};

export const sendFinanceLedgerReportEmail = async (payload = {}) => {
    const { data } = await axios.post(route("club.finance-engine.movements.pdf.email"), payload);
    return data;
};

export const assignMemberToClass = async ({ memberId, classId }) => {
    return await axios.post(route("members.assign"), {
        member_id: memberId,
        club_class_id: classId,
        role: "student",
        assigned_at: new Date().toISOString().slice(0, 10),
        active: true,
    });
};

export const undoClassAssignment = async (memberId) => {
    return await axios.post(route("members.assignment.undo"), {
        member_id: memberId,
    });
};

export const fetchClubsByIds = async (ids) => {
    const response = await axios.get(route("clubs.by-ids"), {
        params: { ids },
    });
    return response.data;
};

export const fetchClubsByUserId = async (userId) => {
    const response = await axios.get(route("clubs.by-user", userId));
    return response.data;
};

export const fetchMembersByClub = async (clubId) => {
    const response = await axios.get(route("clubs.members", clubId));
    return response.data.members;
};

export const fetchClubClasses = async (clubId) => {
    const response = await axios.get(route("clubs.classes", clubId)); // You may define this if desired
    return response.data;
};

export const fetchMasterGuideMemberSchema = async (clubId) => {
    const { data } = await axios.get(route("clubs.members.master-guide-schema", clubId));
    return data;
};

export const updateMasterGuideMemberSchema = async (clubId, schemaJson) => {
    const { data } = await axios.put(route("clubs.members.master-guide-schema.update", clubId), {
        schema_json: schemaJson,
    });
    return data;
};

export const updateMasterGuideMemberYear = async (memberId, programYear) => {
    const { data } = await axios.patch(route("members.master-guide-year.update", memberId), {
        program_year: programYear,
    });
    return data;
};

export const deleteMemberById = async (memberId, notes, options = {}) => {
    return await axios.post(route("members.destroy", memberId), {
        notes_deleted: notes,
        member_type: options.member_type,
        member_record_id: options.member_record_id,
        _method: "DELETE",
    });
};

export const bulkDeleteMembers = async (ids, note = "Bulk deleted") => {
    for (const id of ids) {
        await deleteMemberById(id, note);
    }
};

export const downloadMemberZip = async (ids, clubType = null) => {
    const response = await axios.post(
        route("members.export-zip"),
        {
            member_ids: ids,
            club_type: clubType,
        },
        { responseType: "blob" }
    );

    const blob = new Blob([response.data], { type: "application/zip" });
    const link = document.createElement("a");
    link.href = URL.createObjectURL(blob);
    link.download = `member_export.zip`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(link.href);
};

export const sendMemberZipToConference = async ({ ids, clubType = null, clubId, email }) => {
    const { data } = await axios.post(route("members.export-zip.send-conference"), {
        member_ids: ids,
        club_type: clubType,
        club_id: clubId,
        email,
    });

    return data;
};

export const uploadPathfinderInsuranceCard = async (memberId, file) => {
    const fd = new FormData();
    fd.append("insurance_card_image", file);

    const { data } = await axios.post(
        route("members.pathfinder.insurance-card.upload", memberId),
        fd,
        { headers: { "Content-Type": "multipart/form-data" } }
    );

    return data;
};

export const downloadStaffZip = async (ids) => {
    const response = await axios.post(
        route("export.zip", { type: "staff" }),
        {
            staff_adventurer_ids: ids,
        },
        { responseType: "blob" }
    );

    const blob = new Blob([response.data], { type: "application/zip" });
    const link = document.createElement("a");
    link.href = URL.createObjectURL(blob);
    link.download = `staff_export.zip`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(link.href);
};

export const sendStaffZipToConference = async ({ ids, clubId, email }) => {
    const { data } = await axios.post(route("export.zip.send-conference", { type: "staff" }), {
        staff_adventurer_ids: ids,
        club_id: clubId,
        email,
    });

    return data;
};

export const deleteClassById = async (classId) => {
    return await axios.delete(route("club-classes.destroy", classId));
};

export const createOrUpdateClass = async (classData, isEditing = false) => {
    if (isEditing) {
        return await axios.put(
            route("club-classes.update", classData.id),
            classData
        );
    } else {
        return await axios.post(route("club-classes.store"), classData);
    }
};

export const activateCarpetaClassForClub = async (clubId, unionClassCatalogId) => {
    const { data } = await axios.post(
        route('clubs.carpeta-class-activations.store', { club: clubId }),
        { union_class_catalog_id: unionClassCatalogId }
    );
    return data;
};

export const deactivateCarpetaClassForClub = async (activationId) => {
    const { data } = await axios.delete(
        route('clubs.carpeta-class-activations.destroy', { activation: activationId })
    );
    return data;
};

export const createInvestitureRequirement = async (clubClassId, payload) => {
    const { data } = await axios.post(
        route("investiture-requirements.store", { clubClass: clubClassId }),
        payload
    );
    return data;
};

export const updateInvestitureRequirement = async (requirementId, payload) => {
    const { data } = await axios.put(
        route("investiture-requirements.update", { investitureRequirement: requirementId }),
        payload
    );
    return data;
};

export const deleteInvestitureRequirement = async (requirementId) => {
    const { data } = await axios.delete(
        route("investiture-requirements.destroy", { investitureRequirement: requirementId })
    );
    return data;
};

export const fetchClubsByChurchId = async (churchId) => {
    const response = await axios.get(route("church.clubs", { churchId }));
    return response.data;
};

// Accounts
export const fetchAccountsByClub = async (clubId) => {
    const { data } = await axios.get(route('clubs.accounts.index', { club: clubId }));
    return data;
};

export const createAccount = async (clubId, payload) => {
    const { data } = await axios.post(route('clubs.accounts.store', { club: clubId }), payload);
    return data;
};

export const updateAccount = async (clubId, accountId, payload) => {
    const { data } = await axios.put(route('clubs.accounts.update', { club: clubId, account: accountId }), payload);
    return data;
};

export const deleteAccount = async (clubId, accountId) => {
    const { data } = await axios.delete(route('clubs.accounts.destroy', { club: clubId, account: accountId }));
    return data;
};

export const fetchClubBankInfo = async (clubId) => {
    const { data } = await axios.get(route('clubs.bank-info.index', { club: clubId }));
    return data;
};

export const updateClubBankInfo = async (clubId, payTo, payload) => {
    const { data } = await axios.put(route('clubs.bank-info.update', { club: clubId, payTo }), payload);
    return data;
};

export const fetchFinanceEngineActionables = async (clubId = null) => {
    const { data } = await axios.get(route('club.finance-engine.actionables'), {
        params: clubId ? { club_id: clubId } : {},
    });
    return data;
};

export const fetchFinanceEngineMovements = async (params = {}) => {
    const { data } = await axios.get(route('club.finance-engine.movements'), {
        params,
    });
    return data;
};

export const fetchFinanceEngineCashbox = async (clubId = null, filters = {}) => {
    const { data } = await axios.get(route('club.finance-engine.cashbox'), {
        params: {
            ...filters,
            ...(clubId ? { club_id: clubId } : {}),
        },
    });
    return data;
};

export const fetchFinanceEngineAccounting = async (clubId = null) => {
    const { data } = await axios.get(route('club.finance-engine.accounting'), {
        params: clubId ? { club_id: clubId } : {},
    });
    return data;
};

export const fetchFinanceEngineFundraisers = async (clubId = null) => {
    const { data } = await axios.get(route('club.finance-engine.fundraisers'), {
        params: clubId ? { club_id: clubId } : {},
    });
    return data;
};

const financeEngineFormData = (payload) => {
    const fd = new FormData();
    Object.entries(payload || {}).forEach(([key, value]) => {
        if (value === undefined || value === null || value === '') return;
        if (Array.isArray(value)) {
            value.forEach((item) => fd.append(`${key}[]`, item));
            return;
        }
        if (typeof value === 'boolean') {
            fd.append(key, value ? '1' : '0');
            return;
        }
        fd.append(key, value);
    });
    return fd;
};

const financeEngineFormHeaders = {
    'Content-Type': 'multipart/form-data',
    Accept: 'application/json',
};

export const createFinanceEngineConcept = async (payload) => {
    const { data } = await axios.post(route('club.finance-engine.concepts.store'), payload, {
        headers: { Accept: 'application/json' },
    });
    return data;
};

export const createFinanceEngineIncome = async (payload) => {
    const { data } = await axios.post(route('club.finance-engine.income.store'), financeEngineFormData(payload), {
        headers: financeEngineFormHeaders,
    });
    return data;
};

export const sendPaymentReceiptEmail = async (receiptId, payload) => {
    const { data } = await axios.post(route('payment-receipts.send', { receipt: receiptId }), payload, {
        headers: { Accept: 'application/json' },
    });
    return data;
};

export const approveParentPaymentTransfer = async (submissionId, payload = {}) => {
    const { data } = await axios.post(route('club.director.payments.parent-transfers.approve', { submission: submissionId }), payload, {
        headers: { Accept: 'application/json' },
    });
    return data;
};

export const rejectParentPaymentTransfer = async (submissionId, payload = {}) => {
    const { data } = await axios.post(route('club.director.payments.parent-transfers.reject', { submission: submissionId }), payload, {
        headers: { Accept: 'application/json' },
    });
    return data;
};

export const createFinanceEngineFundraiserEvent = async (payload) => {
    const { data } = await axios.post(route('club.finance-engine.fundraisers.store'), financeEngineFormData(payload), {
        headers: financeEngineFormHeaders,
    });
    return data;
};

export const createFinanceEngineFundraiserProduct = async (fundraiserEventId, payload) => {
    const { data } = await axios.post(route('club.finance-engine.fundraisers.products.store', { fundraiserEvent: fundraiserEventId }), financeEngineFormData(payload), {
        headers: financeEngineFormHeaders,
    });
    return data;
};

export const updateFinanceEngineFundraiserProduct = async (fundraiserProductId, payload) => {
    const { data } = await axios.post(route('club.finance-engine.fundraisers.products.update', { fundraiserProduct: fundraiserProductId }), financeEngineFormData({
        ...payload,
        _method: 'PATCH',
    }), {
        headers: financeEngineFormHeaders,
    });
    return data;
};

export const createFinanceEngineFundraiserSale = async (fundraiserEventId, payload) => {
    const { data } = await axios.post(route('club.finance-engine.fundraisers.sales.store', { fundraiserEvent: fundraiserEventId }), payload, {
        headers: { Accept: 'application/json' },
    });
    return data;
};

export const cancelFinanceEngineFundraiserSale = async (fundraiserEventId, fundraiserSaleId, payload) => {
    const { data } = await axios.post(route('club.finance-engine.fundraisers.sales.cancel', {
        fundraiserEvent: fundraiserEventId,
        fundraiserSale: fundraiserSaleId,
    }), payload, {
        headers: { Accept: 'application/json' },
    });

    return data;
};

export const closeFinanceEngineFundraiserEvent = async (fundraiserEventId, payload = {}) => {
    const { data } = await axios.post(route('club.finance-engine.fundraisers.close', { fundraiserEvent: fundraiserEventId }), payload, {
        headers: { Accept: 'application/json' },
    });
    return data;
};

export const uploadFinanceEngineFundraiserInvestmentReceipts = async (fundraiserEventId, payload = {}, options = {}) => {
    const { data } = await axios.post(route('club.finance-engine.fundraisers.investment-receipts.store', { fundraiserEvent: fundraiserEventId }), financeEngineFormData(payload), {
        headers: financeEngineFormHeaders,
        ...options,
    });
    return data;
};

export const createFinanceEngineFundraiserPartner = async (fundraiserEventId, payload) => {
    const { data } = await axios.post(route('club.finance-engine.fundraisers.partners.store', { fundraiserEvent: fundraiserEventId }), payload, {
        headers: { Accept: 'application/json' },
    });
    return data;
};

export const recordFinanceEngineFundraiserPartnerContribution = async (fundraiserEventPartnerId, payload = {}) => {
    const { data } = await axios.post(route('club.finance-engine.fundraisers.partners.contribution', { fundraiserEventPartner: fundraiserEventPartnerId }), payload, {
        headers: { Accept: 'application/json' },
    });
    return data;
};

export const recordFinanceEngineFundraiserPartnerDistribution = async (fundraiserEventPartnerId, payload = {}) => {
    const { data } = await axios.post(route('club.finance-engine.fundraisers.partners.distribution', { fundraiserEventPartner: fundraiserEventPartnerId }), payload, {
        headers: { Accept: 'application/json' },
    });
    return data;
};

export const createFinanceEngineExpense = async (payload) => {
    const { data } = await axios.post(route('club.finance-engine.expenses.store'), financeEngineFormData(payload), {
        headers: financeEngineFormHeaders,
    });
    return data;
};

export const uploadFinanceEngineExpenseReceipt = async (expenseId, payload, options = {}) => {
    const { data } = await axios.post(
        route('club.finance-engine.expenses.receipt.upload', { expense: expenseId }),
        financeEngineFormData(payload),
        { headers: financeEngineFormHeaders, ...options }
    );
    return data;
};

export const removeFinanceEngineExpenseReceipt = async (expenseId) => {
    const { data } = await axios.delete(route('club.finance-engine.expenses.receipt.remove', { expense: expenseId }), {
        headers: { Accept: 'application/json' },
    });
    return data;
};

export const uploadFinanceEngineReimbursementPaymentProof = async (expenseId, payload, options = {}) => {
    const { data } = await axios.post(
        route('club.finance-engine.expenses.reimbursement-payment-proof.upload', { expense: expenseId }),
        financeEngineFormData(payload),
        { headers: financeEngineFormHeaders, ...options }
    );
    return data;
};

export const removeFinanceEngineReimbursementPaymentProof = async (expenseId) => {
    const { data } = await axios.delete(route('club.finance-engine.expenses.reimbursement-payment-proof.remove', { expense: expenseId }), {
        headers: { Accept: 'application/json' },
    });
    return data;
};

export const reimburseFinanceEngineExpense = async (expenseId, payload) => {
    const { data } = await axios.post(
        route('club.finance-engine.expenses.reimburse', { expense: expenseId }),
        financeEngineFormData(payload),
        { headers: financeEngineFormHeaders }
    );
    return data;
};

export const createFinanceEngineTransfer = async (payload) => {
    const { data } = await axios.post(route('club.finance-engine.transfers.store'), financeEngineFormData(payload), {
        headers: financeEngineFormHeaders,
    });
    return data;
};

export const updateFinanceEngineMovementDisplayConcept = async (movementType, movementId, payload) => {
    const { data } = await axios.patch(
        route('club.finance-engine.movements.display-concept.update', { movementType, movementId }),
        payload,
        { headers: { Accept: 'application/json' } }
    );
    return data;
};

export const validateFinanceEngineStaffRemittance = async (remittanceBatchId, clubId = null) => {
    const { data } = await axios.post(route('club.finance-engine.staff-remittances.validate'), {
        remittance_batch_id: remittanceBatchId,
        ...(clubId ? { club_id: clubId } : {}),
    }, {
        headers: { Accept: 'application/json' },
    });
    return data;
};

export const createFinanceEngineEventSettlement = async (eventId, payload) => {
    const { data } = await axios.post(
        route('club.finance-engine.event-settlements.store', { event: eventId }),
        financeEngineFormData(payload),
        { headers: financeEngineFormHeaders }
    );
    return data;
};

export const reverseFinanceEnginePayment = async (paymentId, payload) =>
    await axios.post(route('club.finance-engine.corrections.payments.reverse', paymentId), payload, {
        headers: { Accept: 'application/json' },
    });

export const reverseFinanceEngineExpense = async (expenseId, payload) =>
    await axios.post(route('club.finance-engine.corrections.expenses.reverse', expenseId), payload, {
        headers: { Accept: 'application/json' },
    });

export const reverseFinanceEngineReimbursement = async (expenseId, payload) =>
    await axios.post(route('club.finance-engine.corrections.reimbursements.reverse', expenseId), payload, {
        headers: { Accept: 'application/json' },
    });

export const fetchStaffMoneyCustody = async (clubId = null) => {
    const { data } = await axios.get(route('club.personal.money-custody.data'), {
        params: clubId ? { club_id: clubId } : {},
    });
    return data;
};

export const remitStaffMoneyCustody = async (payload) => {
    const { data } = await axios.post(route('club.personal.money-custody.remit'), payload);
    return data;
};

export const deleteClubById = async (clubId) => {
    return await axios.delete(route("club.destroy"), { data: { id: clubId } });
};

export const selectUserClub = async (clubId, userId) => {
    return await axios.post(route("club.select"), {
        club_id: clubId,
        user_id: userId,
    });
};

export const attachDirectorToClub = async (clubId, userId) => {
    return await axios.post(route("club.attach-director", clubId), {
        user_id: userId,
    });
};

export const detachDirectorFromClub = async (clubId, userId) => {
    return await axios.post(route("club.detach-director", clubId), {
        user_id: userId,
    });
};

export const createClubObjective = async (clubId, payload) => {
    const { data } = await axios.post(route('clubs.objectives.store', { club: clubId }), payload);
    return data;
};

export const updateClubObjective = async (clubId, objectiveId, payload) => {
    const { data } = await axios.put(route('clubs.objectives.update', { club: clubId, objective: objectiveId }), payload);
    return data;
};

export const deleteClubObjective = async (clubId, objectiveId) => {
    const { data } = await axios.delete(route('clubs.objectives.destroy', { club: clubId, objective: objectiveId }));
    return data;
};

export const saveAdventurerYearlyApplication = async (clubId, payload) => {
    const { data } = await axios.post(route('clubs.adventurer-yearly-applications.store', { club: clubId }), payload);
    return data;
};

export const saveAdventurerQuarterlyReport = async (clubId, payload) => {
    const { data } = await axios.post(route('clubs.adventurer-quarterly-reports.store', { club: clubId }), payload);
    return data;
};

export const saveAdventurerInductionRequest = async (clubId, payload) => {
    const { data } = await axios.post(route('clubs.adventurer-induction-requests.store', { club: clubId }), payload);
    return data;
};

export const sendAdventurerInductionRequest = async (clubId, inductionRequestId, email) => {
    const { data } = await axios.post(route('clubs.adventurer-induction-requests.send', {
        club: clubId,
        inductionRequest: inductionRequestId,
    }), { email });
    return data;
};

export const sendAdventurerYearlyApplication = async (clubId, applicationId, email) => {
    const { data } = await axios.post(route('clubs.adventurer-yearly-applications.send', {
        club: clubId,
        application: applicationId,
    }), { email });
    return data;
};

export const saveAdventurerYearlyApplicationDirectorSignature = async (clubId, applicationId, payload) => {
    const { data } = await axios.post(route('clubs.adventurer-yearly-applications.director-signature', {
        club: clubId,
        application: applicationId,
    }), payload);
    return data;
};

export const requestAdventurerYearlyApplicationSignature = async (clubId, applicationId, payload) => {
    const { data } = await axios.post(route('clubs.adventurer-yearly-applications.signature-requests', {
        club: clubId,
        application: applicationId,
    }), payload);
    return data;
};

export const savePathfinderAnnualApplication = async (clubId, payload) => {
    const { data } = await axios.post(route('clubs.pathfinder-annual-applications.store', { club: clubId }), payload);
    return data;
};

export const sendPathfinderAnnualApplication = async (clubId, applicationId, email) => {
    const { data } = await axios.post(route('clubs.pathfinder-annual-applications.send', {
        club: clubId,
        application: applicationId,
    }), { email });
    return data;
};

export const savePathfinderAnnualApplicationDirectorSignature = async (clubId, applicationId, payload) => {
    const { data } = await axios.post(route('clubs.pathfinder-annual-applications.director-signature', {
        club: clubId,
        application: applicationId,
    }), payload);
    return data;
};

export const requestPathfinderAnnualApplicationSignature = async (clubId, applicationId, payload) => {
    const { data } = await axios.post(route('clubs.pathfinder-annual-applications.signature-requests', {
        club: clubId,
        application: applicationId,
    }), payload);
    return data;
};

export const savePathfinderMonthlyReport = async (clubId, payload) => {
    const { data } = await axios.post(route('clubs.pathfinder-monthly-reports.store', { club: clubId }), payload);
    return data;
};

export const sendPathfinderMonthlyReport = async (clubId, reportId, email) => {
    const { data } = await axios.post(route('clubs.pathfinder-monthly-reports.send', {
        club: clubId,
        report: reportId,
    }), { email });
    return data;
};

export const createClub = async (payload) => {
    return await axios.post(route("club.store"), payload);
};

export const updateClub = async (payload) => {
    console.log(route("club.update"));

    return await axios.put(route("club.update"), payload);
};

export const fetchStaffByClubId = async (clubId, churchId = null) => {
    const response = await axios.get(
        route("clubs.staff", { clubId, churchId })
    );
    return response.data;
};

export const createStaffUser = async (payload) => {
    return await axios.post(route("staff.createUser"), payload);
};

export const updateStaffStatus = async (staffId, status_code) => {
    return await axios.post(route("staff.updateStaffAccount"), {
        staff_id: staffId,
        status_code,
    });
};

export const updateUserStatus = async (userId, status_code) => {
    return await axios.post(route("staff.updateUserAccount"), {
        user_id: userId,
        status_code,
    });
};

export const makeStaffUserTreasurer = async (userId, clubId) => {
    return await axios.post(route("staff.makeTreasurer", userId), {
        club_id: clubId,
    });
};

export const approveStaff = async (staffId) => {
    return await axios.post(route('staff.approve', staffId))
}


export const rejectStaff = async (staffId) => {
    return await axios.post(route('staff.reject', staffId))
}

export const updateStaffAssignedClass = async (staffId, classId) => {
    return await axios.put(route("staff.update-class"), {
        staff_id: staffId,
        class_id: classId,
    });
};

export const linkStaffToClubUser = async (staffId) => {
    const { data } = await axios.post(route('staff.link-club', staffId))
    return data
}

export const fetchInviteCode = async () => {
    const { data } = await axios.get(route('club.director.church.invite-code'))
    return data
}

export const regenerateInviteCode = async () => {
    const { data } = await axios.post(route('club.director.church.invite-code.regenerate'))
    return data
}
export const submitStaffForm = async (formData, editingStaffId = null) => {
    const url = editingStaffId
        ? route("staff.update", editingStaffId)
        : route("staff.store");
    const method = editingStaffId ? "put" : "post";

    return await axios[method](url, formData);
};

export const fetchAssignedMembersByStaff = async (staffId) => {
    const response = await axios.get(`/staff/${staffId}/assigned-members`);
    return response.data;
};

export const addInvestitureRequirementCompletion = async (payload) => {
    const { data } = await axios.post(
        route('club.personal.investiture-requirements.completions.store'),
        payload
    );
    return data;
};

// Fetch reports by staff ID
export const fetchReportsByStaffId = async (staffId) => {
    const response = await axios.get(
        `/assistance-reports/by/staff_id/${staffId}`
    );
    return response.data.reports;
};

// Fetch PDF report by ID and Date
export const fetchReportByIdAndDate = async (id, date) => {
    const { data } = await axios.get(
        `/pdf-assistance-reports/${id}/${date}/pdf`
    );
    return data;
};

export async function createAssistanceReport(payload) {
    const response = await axios.post("/assistance-reports", payload);
    return response.data;
}

export async function updateAssistanceReport(reportId, payload) {
    const response = await axios.put(
        `/assistance-reports/${reportId}`,
        payload
    );
    return response.data;
}

export async function getAssistanceReport(reportId) {
    const response = await axios.get(`/assistance-reports/${reportId}`);
    return response.data;
}

export async function checkAssistanceReportToday(staffId, date, params = {}) {
    const response = await axios.get(
        `/assistance-reports/check-today/${staffId}`,
        { params: { date, ...params } }
    );
    return response.data;
}

export async function fetchAssistanceRequirementActivities(date) {
    const { data } = await axios.get(route('club.assistance_report.activities'), {
        params: { date },
    });
    return data;
}

export const filterAssistanceReports = (payload) => {
    return axios.post("/assistance-reports/filter", payload);
};
//FINANCIALS
// List by club
export const listPaymentConceptsByClub = (clubId) =>
    axios.get(route("clubs.payment-concepts.index", clubId));

// Create (payload must include scopes[], type, pay_to, status, etc.)
export const createPaymentConcept = (clubId, payload) =>
    axios.post(route("clubs.payment-concepts.store", clubId), payload);

// Show one
export const showPaymentConcept = (clubId, id) =>
    axios.get(
        route("clubs.payment-concepts.show", {
            club: clubId,
            paymentConcept: id,
        })
    );

// Update
export const updatePaymentConcept = (clubId, id, payload) =>
    axios.put(
        route("clubs.payment-concepts.update", {
            club: clubId,
            paymentConcept: id,
        }),
        payload
    );

// Delete
export const deletePaymentConcept = (clubId, id) =>
    axios.delete(
        route("clubs.payment-concepts.destroy", {
            club: clubId,
            paymentConcept: id,
        })
    );

//PAYMENTS
export const fetchDirectorPayments = async (clubId = null) => {
    const { data } = await axios.get(route('club.director.payments'), {
        params: clubId ? { club_id: clubId } : {},
    });
    return data;
};

export const createClubPayment = async (payload) => {
    const fd = new FormData();
    Object.entries(payload).forEach(([k, v]) => {
        if (v === undefined || v === null) return;
        if (Array.isArray(v)) {
            v.forEach((item) => fd.append(`${k}[]`, item));
            return;
        }
        fd.append(k, v);
    });

    return await axios.post(route("club.payments.store"), fd, {
        headers: { "Content-Type": "multipart/form-data" },
    });
};

export const updateClubPayment = async (paymentId, payload) => {
    const fd = new FormData();
    Object.entries(payload).forEach(([k, v]) => {
        if (v === undefined || v === null) return;
        fd.append(k, v);
    });
    fd.append('_method', 'PUT');

    return await axios.post(route("club.payments.update", { payment: paymentId }), fd, {
        headers: { "Content-Type": "multipart/form-data" },
    });
};

// Director Financial Report bootstrap data
export const fetchFinancialReportBootstrap = async (clubId = null) => {
    const { data } = await axios.get(route('financial.preload'), {
        params: clubId ? { club_id: clubId } : {}
    })
    return data
}

// Parent workplan
export const fetchParentWorkplan = async (clubId = null) => {
    const { data } = await axios.get(route('parent.workplan.data'), {
        params: clubId ? { club_id: clubId } : {}
    })
    return data
}

// Class Plans
export const createClassPlan = async (payload) => {
    const { data } = await axios.post(route('club.personal.class-plans.store'), payload)
    return data
}

export const updateClassPlan = async (id, payload) => {
    const { data } = await axios.put(route('club.personal.class-plans.update', id), payload)
    return data
}

export const deleteClassPlan = async (id) => {
    const { data } = await axios.delete(route('club.personal.class-plans.destroy', id))
    return data
}

export const updateClassPlanStatus = async (id, payload) => {
    const body = typeof payload === 'string' ? { status: payload } : payload
    const { data } = await axios.put(route('club.workplan.class-plans.status', id), body)
    return data
}

// Workplan data for club_personal dashboard
export const fetchPersonalWorkplan = async (clubId = null) => {
    const { data } = await axios.get(route('club.personal.workplan.data'), {
        params: clubId ? { club_id: clubId } : {}
    })
    return data
}

// Workplan
export const previewWorkplan = async (payload) => {
    const { data } = await axios.post(route('club.workplan.preview'), payload)
    return data
}

export const confirmWorkplan = async (payload) => {
    const { data } = await axios.post(route('club.workplan.confirm'), payload)
    return data
}

export const createWorkplanEvent = async (payload) => {
    const { data } = await axios.post(route('club.workplan.events.store'), payload)
    return data
}

export const updateWorkplanEvent = async (id, payload) => {
    const { data } = await axios.put(route('club.workplan.events.update', id), payload)
    return data
}

export const deleteWorkplanEvent = async (id) => {
    const { data } = await axios.delete(route('club.workplan.events.destroy', id))
    return data
}

// Event Planner
export const updateEvent = async (id, payload) => {
    const { data } = await axios.put(route('events.update', id), payload, {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    })
    return data
}

export const deleteWorkplan = async (clubId) => {
    const { data } = await axios.delete(route('club.workplan.destroy'), {
        params: clubId ? { club_id: clubId } : {}
    })
    return data
}

export const exportWorkplanToMyChurchAdmin = async (payload) => {
    const { data } = await axios.post(route('club.workplan.export'), payload)
    return data
}

export const fetchMyChurchAdminCatalog = async (payload) => {
    console.log(payload);
    const { data } = await axios.post(route('club.settings.catalog'), payload)
    return data
}

export const saveMyChurchAdminConfig = async (payload) => {
    const { data } = await axios.post(route('club.settings.save'), payload)
    return data
}

export const updateClubContact = async (payload) => {
    const { data } = await axios.patch(route('club.settings.contact'), payload)
    return data
}

export const uploadClubLogo = async ({ clubId, file }) => {
    const fd = new FormData()
    fd.append('club_id', clubId)
    fd.append('logo', file)

    const { data } = await axios.post(route('club.settings.logo'), fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
    })
    return data
}

export const removeClubLogo = async (clubId) => {
    const { data } = await axios.delete(route('club.settings.logo.destroy'), {
        data: { club_id: clubId },
    })
    return data
}

// Pathfinder temp data
export const fetchTempMembersPathfinder = async (clubId) => {
    const { data } = await axios.get(route('clubs.temp-members.index', clubId))
    return data
}

export const createTempMemberPathfinder = async (payload) => {
    const clubId = payload.club_id
    const { data } = await axios.post(route('clubs.temp-members.store', clubId), payload)
    return data
}

export const fetchTempStaffPathfinder = async (clubId) => {
    const { data } = await axios.get(route('clubs.temp-staff.index', clubId))
    return data
}

export const createTempStaffPathfinder = async (payload) => {
    const clubId = payload.club_id
    const { data } = await axios.post(route('clubs.temp-staff.store', clubId), payload)
    return data
}
