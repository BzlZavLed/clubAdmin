<template>
  <ion-page>
    <ion-router-outlet />

    <nav class="app-bottom-nav">
      <button type="button" :class="{ active: isActive('/home') }" @click="go('/home')">
        <ion-icon :icon="homeOutline" />
        <span>Home</span>
      </button>

      <button v-if="profileType === 'parent'" type="button" :class="{ active: isActive('/parent') }" @click="go('/parent')">
        <ion-icon :icon="peopleOutline" />
        <span>Parent</span>
      </button>

      <button v-if="profileType === 'parent'" type="button" :class="{ active: isActive('/parent/payments') }" @click="go('/parent/payments')">
        <ion-icon :icon="cardOutline" />
        <span>Payments</span>
      </button>

      <button v-if="profileType === 'parent'" type="button" :class="{ active: isActive('/parent/workplan') }" @click="go('/parent/workplan')">
        <ion-icon :icon="calendarOutline" />
        <span>Plan</span>
      </button>

      <button v-if="profileType !== 'parent'" type="button" :class="{ active: isActive('/tasks') }" @click="go('/tasks')">
        <ion-icon :icon="checkboxOutline" />
        <span>Tasks</span>
      </button>

      <button v-if="profileType !== 'parent'" type="button" :class="{ active: isActive('/location-safety') }" @click="go('/location-safety')">
        <ion-icon :icon="locationOutline" />
        <span>Safety</span>
      </button>
    </nav>
  </ion-page>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
  IonIcon,
  IonPage,
  IonRouterOutlet,
} from '@ionic/vue';
import {
  calendarOutline,
  cardOutline,
  checkboxOutline,
  homeOutline,
  locationOutline,
  peopleOutline,
} from 'ionicons/icons';
import { mobileApi } from '@/services/api';

const route = useRoute();
const router = useRouter();
const user = ref<any>(null);
const profileType = computed(() => user.value?.profile_type);

function go(path: string) {
  if (route.path !== path) {
    router.push(path);
  }
}

function isActive(path: string) {
  if (path === '/parent') return route.path === '/parent' || route.path === '/parent/children';
  return route.path === path || route.path.startsWith(`${path}/`);
}

onMounted(async () => {
  try {
    const payload = await mobileApi.me();
    user.value = payload.user;
  } catch {
    user.value = null;
  }
});
</script>
