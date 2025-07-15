// resources/js/Composables/useAuth.js
import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";

export function useAuth() {
    const page = usePage();
    const user = computed(() => page.props?.auth?.user ?? {});
    const church = computed(() => page.props?.auth?.church_name ?? "");
    const isInClub = computed(() => page.props?.auth?.is_in_club ?? false);
    const userClubIds = computed(() => page.props?.auth?.user_club_ids ?? []);

    // Role checks
    const isClubDirector = computed(
        () => user.value?.profile_type === "club_director"
    );
    const isClubStaff = computed(
        () => user.value?.profile_type === "club_personal"
    );
    const isAdmin = computed(() =>
        user.value?.profile_type?.includes("manager")
    );

    return {
        user,
        church,
        isInClub,
        userClubIds,
        isClubDirector,
        isClubStaff,
        isAdmin,
    };
}
