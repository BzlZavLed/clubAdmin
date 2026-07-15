<template>
  <ion-page>
    <ion-header>
      <ion-toolbar>
        <ion-title>My Club</ion-title>
      </ion-toolbar>
    </ion-header>
    <ion-content class="ion-padding">
      <section v-if="user" class="home-identity">
        <p class="eyebrow">{{ user.profile_type }}</p>
        <h1>{{ user.name }}</h1>
        <p>{{ user.email }}</p>
      </section>

      <template v-if="user?.profile_type === 'parent'">
        <section class="membership-panel">
          <span>Church</span>
          <strong>{{ parentDashboard?.church?.church_name || 'No church assigned' }}</strong>
          <ion-list v-if="parentDashboard?.clubs?.length" inset>
            <ion-item v-for="club in parentDashboard.clubs" :key="club.id">
              <ion-label>
                <h2>{{ club.club_name }}</h2>
                <p>{{ clubTypeLabel(club.club_type) }}</p>
              </ion-label>
            </ion-item>
          </ion-list>
          <ion-note v-else>
            No clubs linked through children yet.
          </ion-note>
        </section>

        <h2 class="section-title">Next events</h2>
        <ion-list inset>
          <ion-item v-for="event in parentDashboard?.workplan?.upcoming_events || []" :key="event.id" router-link="/parent/workplan">
            <ion-label>
              <h2>{{ event.title }}</h2>
              <p>{{ event.club_name }} · {{ event.date }} · {{ event.location || 'No location' }}</p>
            </ion-label>
          </ion-item>
        </ion-list>
        <ion-note v-if="parentDashboard && !parentDashboard.workplan?.upcoming_events?.length">
          No upcoming events.
        </ion-note>
      </template>

      <ion-list v-if="user && user.profile_type !== 'parent'" inset>
        <ion-item router-link="/tasks">
          <ion-label>
            <h2>Tasks</h2>
            <p>Workplan assignments, forms, and evidence.</p>
          </ion-label>
        </ion-item>
        <ion-item router-link="/location-safety">
          <ion-label>
            <h2>Location safety</h2>
            <p>Prepared frame for offsite class tracking.</p>
          </ion-label>
        </ion-item>
      </ion-list>

      <ion-button expand="block" fill="clear" @click="logout">Sign out</ion-button>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { IonButton, IonContent, IonHeader, IonItem, IonLabel, IonList, IonNote, IonPage, IonTitle, IonToolbar } from '@ionic/vue';
import { mobileApi } from '@/services/api';

const router = useRouter();
const user = ref<any>(null);
const parentDashboard = ref<any>(null);

async function load() {
  const payload = await mobileApi.me();
  user.value = payload.user;
  if (user.value?.profile_type === 'parent') {
    parentDashboard.value = await mobileApi.parentDashboard();
  }
}

async function logout() {
  await mobileApi.logout();
  await router.replace('/login');
}

function clubTypeLabel(type: string) {
  if (type === 'adventurers') return 'Adventurers';
  if (type === 'pathfinders') return 'Pathfinders';
  if (type === 'master_guide') return 'Master Guides';
  return type || 'Club';
}

onMounted(load);
</script>
