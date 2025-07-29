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
        members_adventurer_id: memberId,
        club_class_id: classId,
        role: "student",
        assigned_at: new Date().toISOString().slice(0, 10),
        active: true,
    });
};

export const undoClassAssignment = async (memberId) => {
    return await axios.post(route("members.assignment.undo"), {
        members_adventurer_id: memberId,
    });
};

export const fetchClubsByIds = async (ids) => {
    const response = await axios.get(route("clubs.by-ids"), {
        params: { ids },
    });
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
    console.log(route('club.update')); 

    return await axios.put(route("club.update"), payload);
};

export const fetchStaffByClubId = async (clubId, churchId = null) => {
    const response = await axios.get(route("clubs.staff", { clubId, churchId }));
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

export const updateStaffAssignedClass = async (staffId, classId) => {
    return await axios.put(route('staff.update-class'), {
    staff_id: staffId,
    class_id: classId,
    })
}
export const submitStaffForm = async (formData, editingStaffId = null) => {
    const url = editingStaffId
        ? route('staff.update', editingStaffId)
        : route('staff.store')
    const method = editingStaffId ? 'put' : 'post'

    return await axios[method](url, formData)
}

export const fetchAssignedMembersByStaff = async (staffId) => {
    const response = await axios.get(`/staff/${staffId}/assigned-members`)
    return response.data
}

// Fetch reports by staff ID
export const fetchReportsByStaffId = async (staffId) => {
    const response = await axios.get(`/assistance-reports/by/staff_id/${staffId}`);
    return response.data.reports;
};

// Fetch PDF report by ID and Date
export const fetchReportByIdAndDate = async (id, date) => {
    const { data } = await axios.get(`/pdf-assistance-reports/${id}/${date}/pdf`);
    return data;
};

export async function createAssistanceReport(payload) {
    const response = await axios.post('/assistance-reports', payload);
    return response.data;
}

export async function updateAssistanceReport(reportId, payload) {
    const response = await axios.put(`/assistance-reports/${reportId}`, payload);
    return response.data;
}

export async function getAssistanceReport(reportId) {
    const response = await axios.get(`/assistance-reports/${reportId}`);
    return response.data;
}

export async function checkAssistanceReportToday(staffId, date) {
    const response = await axios.get(`/assistance-reports/check-today/${staffId}?date=${date}`);
    return response.data;
}

export const filterAssistanceReports = (payload) => {
    return axios.post('/assistance-reports/filter', payload);
};