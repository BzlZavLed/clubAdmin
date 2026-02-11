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

export const deleteMemberById = async (memberId, notes) => {
    return await axios.post(route("members.destroy", memberId), {
        notes_deleted: notes,
        _method: "DELETE",
    });
};

export const bulkDeleteMembers = async (ids, note = "Bulk deleted") => {
    for (const id of ids) {
        await deleteMemberById(id, note);
    }
};

export const downloadMemberZip = async (ids) => {
    const response = await axios.post(
        route("members.export-zip"),
        {
            member_ids: ids,
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

export const deleteClubById = async (clubId) => {
    return await axios.delete(route("club.destroy"), { data: { id: clubId } });
};

export const selectUserClub = async (clubId, userId) => {
    return await axios.post(route("club.select"), {
        club_id: clubId,
        user_id: userId,
    });
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

export async function checkAssistanceReportToday(staffId, date) {
    const response = await axios.get(
        `/assistance-reports/check-today/${staffId}?date=${date}`
    );
    return response.data;
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
export const createClubPayment = async (payload) => {
    const fd = new FormData();
    Object.entries(payload).forEach(([k, v]) => {
        if (v === undefined || v === null) return;
        fd.append(k, v);
    });

    return await axios.post(route("club.payments.store"), fd, {
        headers: { "Content-Type": "multipart/form-data" },
    });
};

// Director Financial Report — bootstrap data
export const fetchFinancialReportBootstrap = async (clubId = null) => {
    const { data } = await axios.get(route('financial.preload'), {
        params: clubId ? { club_id: clubId } : {}
    })
    return data
}

// Account balances by pay_to
export const fetchFinancialAccountBalances = async (clubId = null) => {
    const { data } = await axios.get(route('financial.accounts'), {
        params: clubId ? { club_id: clubId } : {}
    })
    return data
}

// Expenses
export const fetchExpenses = async (clubId = null) => {
    const { data } = await axios.get(route('club.director.expenses'), {
        params: clubId ? { club_id: clubId } : {}
    })

    return data
}

export const createExpense = async (payload) => {
    const fd = new FormData()
    Object.entries(payload).forEach(([k, v]) => {
        if (v === undefined || v === null) return
        fd.append(k, v)
    })

    return await axios.post(route('club.director.expenses.store'), fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
    })
}

export const uploadExpenseReceipt = async (expenseId, file) => {
    const fd = new FormData()
    fd.append('receipt_image', file)

    return await axios.post(route('club.director.expenses.upload', expenseId), fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
    })
}

export const uploadReimbursementReceipt = async (expenseId, file) => {
    const fd = new FormData()
    fd.append('receipt_image', file)

    return await axios.post(route('club.director.expenses.uploadReimbursementReceipt', expenseId), fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
    })
}

export const markExpenseReimbursed = async (expenseId, payTo, receiptFile) => {
    const fd = new FormData()
    fd.append('pay_to', payTo)
    if (receiptFile) {
        fd.append('receipt_image', receiptFile)
    }
    return await axios.post(route('club.director.expenses.reimburse', expenseId), fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
    })
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
