// File: Composables/useGeneral.js
import { ref } from "vue";
import { useToast } from "vue-toastification";

export function useGeneral() {
    const toast = useToast();

    const toastRef = ref({
        show: false,
        message: "",
        type: "success",
    });

    const showToast = (message, type = "success") => {
        if (toast && typeof toast[type] === "function") {
            toast[type](message);
        } else {
            toastRef.value = { show: true, message, type };
            setTimeout(() => (toastRef.value.show = false), 3000);
        }
    };

    const showError = (err, fallbackMessage = 'Something went wrong') => {
        let errorMessage = fallbackMessage

        if (err?.response?.data?.message) {
            errorMessage = err.response.data.error || err.response.data.message;
        } else if (err?.message) {
            errorMessage = err.message
        }

        toast.error(errorMessage)
    }

    const isLoading = ref(false);

    const startLoading = () => {
        isLoading.value = true;
    };

    const stopLoading = () => {
        isLoading.value = false;
    };

    return {
        toastRef,
        showToast,
        isLoading,
        startLoading,
        stopLoading,
        showError
    };
}
