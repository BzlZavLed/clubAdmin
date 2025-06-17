<script setup>
//WIP Post form for creating new staff
import { ref, watch } from "vue";
import { useForm,usePage } from "@inertiajs/vue3";

const props = defineProps({
    show: Boolean,
    user: Object,
    clubName: String,
    churchName: String,
    club: Object,
});
const emit = defineEmits(["close", "submitted"]);
const today = new Date().toISOString().split('T')[0]
const page = usePage()

const auth_user = page.props?.auth.user

const form = useForm({
    club_id: auth_user.club_id,
    date_of_record: today,
    name: '',
    dob: '',
    address: '',
    city: '',
    state: '',
    zip: '',
    cell_phone: '',
    email: '',
    assigned_class: '',
    church_name: '',
    club_name: '',
    has_health_limitation: '',
    health_limitation_description: '',
    experiences: [{ position: '', organization: '', date: '' }],
    award_instruction_abilities: [{ name: '', level: '' }],
    unlawful_sexual_conduct: '',
    unlawful_sexual_conduct_records: [{ date_place: '', type: '', reference: '' }],
    sterling_volunteer_completed: '',
    reference_pastor: '',
    reference_elder: '',
    reference_other: '',
    applicant_signature: '',
    application_signed_date: '',
})
watch(
    () => props.user,
    (newUser) => {
        if (newUser) {
            form.name = newUser.name || "";
            form.email = newUser.email || "";
            form.church_name = newUser.church_name || "";
            form.applicant_signature = newUser.name || "";
        }
    }, { immediate: true }
);
watch(() => props.club, (newClub) => {
    if (newClub) {
        form.club_name = newClub.club_name || ''
        form.church_name = newClub.church_name || ''
    }
}, { immediate: true })
const addAbility = () => {
    form.award_instruction_abilities.push({ name: '', level: '' })
}

const removeAbility = (index) => {
    form.award_instruction_abilities.splice(index, 1)
}
const onSubmit = () => {
    form.post("/staff", {
        preserveScroll: true,
        onSuccess: () => {
            emit("submitted");
            emit("close");
            form.reset();
        },
        onError: (err) => console.error(err),
    });
};
</script>

<template>
<div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-4xl p-6 max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-xl font-bold">Register New Staff Member</h2>
            <button @click="$emit('close')" class="text-red-500 hover:text-red-700 text-lg font-bold">
                &times;
            </button>
        </div>

        <form @submit.prevent="onSubmit" class="space-y-4">
            <h3><b>Section 1</b></h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label>Name</label><input v-model="form.name" type="text" class="w-full p-2 border rounded" />
                </div>
                <div>
                    <label>DOB</label><input v-model="form.dob" type="date" class="w-full p-2 border rounded" />
                </div>
                <div>
                    <label>Address</label><input v-model="form.address" type="text" class="w-full p-2 border rounded" />
                </div>
                <div>
                    <label>City</label><input v-model="form.city" type="text" class="w-full p-2 border rounded" />
                </div>
                <div>
                    <label>State</label><input v-model="form.state" type="text" class="w-full p-2 border rounded" />
                </div>
                <div>
                    <label>ZIP</label><input v-model="form.zip" type="text" class="w-full p-2 border rounded" />
                </div>
                <div>
                    <label>Cell Phone</label><input v-model="form.cell_phone" type="text" class="w-full p-2 border rounded" />
                </div>
                <div>
                    <label>Email</label><input v-model="form.email" type="email" class="w-full p-2 border rounded" />
                </div>
                <div>
                    <label>Assigned Class</label><input v-model="form.assigned_class" type="text" class="w-full p-2 border rounded" />
                </div>
                <div>
                    <label>Church name</label><input v-model="form.church_name" type="church_name" class="w-full p-2 border rounded" />
                </div>
                <div>
                    <label>Club name</label><input v-model="form.club_name" type="club_name" class="w-full p-2 border rounded" />
                </div>
            </div>
            <h3><b>Section 2 Health history</b></h3>
            <div class="mb-4">
                <label class="block mb-2">
                    Do you now have, or have you had any injury/sickness that might limit your involvement in Adventurer Club activities?
                </label>

                <div class="flex items-center space-x-4">
                    <label class="inline-flex items-center">
                        <input type="radio" value="yes" v-model="form.has_health_limitation" class="mr-2" />
                        Yes
                    </label>
                    <label class="inline-flex items-center">
                        <input type="radio" value="no" v-model="form.has_health_limitation" class="mr-2" />
                        No
                    </label>
                </div>

                <div v-if="form.has_health_limitation === 'yes'" class="mt-4">
                    <label class="block mb-1">If yes, how would it hinder?</label>
                    <textarea v-model="form.health_limitation_description" class="w-full p-2 border rounded" rows="3"></textarea>
                </div>
            </div>
            <h3><b>Section 3 Experience</b></h3>
            <div class="mb-4">
                <label class="block mb-2">
                    List all experience (Pathfinders, scouting, Sabbath School, etc.) that might qualify you for Adventurer leadership.
                </label>

                <div v-for="(experience, index) in form.experiences" :key="index" class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-2">
                    <div>
                        <label class="block text-sm font-medium">Position/Type of Work</label>
                        <input type="text" v-model="experience.position" class="w-full p-2 border rounded" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium">Church/Organization</label>
                        <input type="text" v-model="experience.organization" class="w-full p-2 border rounded" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium">Date of Service</label>
                        <input type="text" v-model="experience.date" placeholder="e.g. 2020–2022" class="w-full p-2 border rounded" />
                    </div>
                </div>
            </div>
            <h3><b>Section 4 Award Instruction Ability</b></h3>

            <div class="mb-4">
                <label class="block mb-2">Please list the awards/crafts which you are interested in teaching.
                    <br>Circle: T-capable of teaching. A-able to assist. I-interested in learning to teach.
                </label>

                <table class="w-full text-sm border rounded">
                    <thead class="bg-gray-100">
                        <tr>
                            <th class="p-2 text-left">Ability Name</th>
                            <th class="p-2 text-left">Commitment Level</th>
                            <th class="p-2 text-left">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(entry, index) in form.award_instruction_abilities" :key="index">
                            <td class="p-2">
                                <input v-model="entry.name" type="text" class="w-full p-1 border rounded" placeholder="e.g. Camping" />
                            </td>
                            <td class="p-2">
                                <select v-model="entry.level" class="w-full p-1 border rounded">
                                    <option disabled value="">Select</option>
                                    <option value="T">T - Capable of Teaching</option>
                                    <option value="A">A - Able to Assist</option>
                                    <option value="I">I - Interested in Learning</option>
                                </select>
                            </td>
                            <td class="p-2 text-center">
                                <button type="button" class="text-red-600 hover:underline" @click="removeAbility(index)">Remove</button>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="mt-2">
                    <button type="button" class="bg-blue-600 text-white px-3 py-1 rounded hover:bg-blue-700" @click="addAbility">
                        + Add Ability
                    </button>
                </div>

                <p class="text-sm text-gray-500 mt-2">Circle: T = Capable of teaching, A = Able to assist, I = Interested in learning</p>
            </div>
            <h3><b>Section 5 Unlawful conduct</b></h3>
            <div class="mt-4">
                <label class="block mb-1">
                    Have you been accused, charged, or disciplined for any unlawful sexual conduct, child abuse, and/or child sexual abuse?
                </label>

                <div class="flex items-center gap-6">
                    <label class="inline-flex items-center">
                        <input type="radio" v-model="form.unlawful_sexual_conduct" value="yes" class="mr-2" />
                        Yes
                    </label>
                    <label class="inline-flex items-center">
                        <input type="radio" v-model="form.unlawful_sexual_conduct" value="no" class="mr-2" />
                        No
                    </label>
                </div>

                <!-- Explanation field -->
                <div v-if="form.unlawful_sexual_conduct === 'yes'" class="mt-4">
                    <div class="mb-4">
                        <label class="block font-semibold mb-2">
                            If yes, please complete the following:
                        </label>

                        <div v-for="(entry, index) in form.unlawful_sexual_conduct_records" :key="index" class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-2">
                            <div>
                                <label class="block text-sm font-medium">Date & Place</label>
                                <input type="text" v-model="entry.date_place" class="w-full p-2 border rounded" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium">Type of Conduct</label>
                                <input type="text" v-model="entry.type" class="w-full p-2 border rounded" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium">Reference (name, address, phone)</label>
                                <input type="text" v-model="entry.reference" class="w-full p-2 border rounded" />
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <h3><b>Section 6 Sterling Volunteers</b></h3>

            <div>
                <label>Sterling Volunteers Background Check Completed?</label><input v-model="form.sterling_volunteer_completed" type="text" class="w-full p-2 border rounded" />
            </div>
            <h3><b>Section 7 References</b></h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label>Pastor Reference</label><input v-model="form.reference_pastor" type="text" class="w-full p-2 border rounded" />
                </div>
                <div>
                    <label>Elder Reference</label><input v-model="form.reference_elder" type="text" class="w-full p-2 border rounded" />
                </div>
                <div>
                    <label>Other Reference</label><input v-model="form.reference_other" type="text" class="w-full p-2 border rounded" />
                </div>
            </div>
            <h3><b>Section 8 Statement of Accuracy</b></h3>
            <label>The above information is accurate to the best of my recollection. I understand this is strictly a volunteer position, and I will receive no remuneration for services and time volunteered.</label>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label>Signature</label><input v-model="form.applicant_signature" type="text" class="w-full p-2 border rounded" />
                </div>
                <div>
                    <label>Date</label><input v-model="form.application_signed_date" type="date" class="w-full p-2 border rounded" />
                </div>
            </div>
            <div class="flex justify-end">
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                    Submit
                </button>
            </div>
            <br>
            <label>
                <b>Notes</b>
                Please make sure you have checked the appropriate box in Section 5 and signed your name in Section 8.

                Directors are to mail the completed form to: Adventurer Ministries Conference Director.

                Section 5 deals with unlawful conduct. This section has been included to protect the Adventurer Club members from abuse and protect the Seventh-day Adventist church organization from recommending my staff member who has a problem in this area.

                If the conference Adventurer director recommends the applicant, information in Sections 1 through 4 will be copied and sent to the local Adventurer Club for the director to use in determining staff qualification. If the applicant has not been approved, none of the information will be forwarded.

                When a local club director requests a recommendation from the conference Adventurer director, he/she may not release any specifics and may respond only with “recommended”, “not recommended”, or “recommended with conditions noted”.

                All information on this application will become a permanent record and should include updates. In the event of accusations against the applicant, opportunity should be given for response by the accused. This response also becomes a part of the record.

                We regret having to include a section on unlawful conduct, however, understanding the epidemic proportions of this problem, it becomes necessary to create a data base to protect child, parents, Adventurer staff, and the church.

            </label>
        </form>
    </div>
</div>
</template>
