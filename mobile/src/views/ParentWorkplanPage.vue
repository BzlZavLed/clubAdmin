<template>
  <ion-page>
    <ion-header>
      <ion-toolbar>
        <app-back-button />
        <ion-title>Workplan</ion-title>
      </ion-toolbar>
    </ion-header>
    <ion-content class="ion-padding">
      <mobile-workplan-calendar :events="events" />
      <ion-note v-if="!events.length">No upcoming workplan events.</ion-note>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { IonContent, IonHeader, IonNote, IonPage, IonTitle, IonToolbar } from '@ionic/vue';
import { mobileApi } from '@/services/api';
import AppBackButton from '@/components/AppBackButton.vue';
import MobileWorkplanCalendar from '@/components/MobileWorkplanCalendar.vue';

const events = ref<any[]>([]);

onMounted(async () => {
  const payload = await mobileApi.parentWorkplan();
  events.value = payload.upcoming_events || [];
});
</script>
