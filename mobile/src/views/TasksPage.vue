<template>
  <ion-page>
    <ion-header>
      <ion-toolbar>
        <app-back-button />
        <ion-title>Tasks</ion-title>
      </ion-toolbar>
    </ion-header>
    <ion-content class="ion-padding">
      <ion-refresher slot="fixed" @ionRefresh="refresh">
        <ion-refresher-content />
      </ion-refresher>
      <ion-list inset>
        <ion-item v-for="task in tasks" :key="task.id">
          <ion-label>
            <h2>{{ task.title }}</h2>
            <p>{{ task.status }} · {{ task.due_at || 'No due date' }}</p>
          </ion-label>
        </ion-item>
      </ion-list>
      <ion-note v-if="!loading && tasks.length === 0">No mobile tasks assigned yet.</ion-note>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import {
  IonContent,
  IonHeader,
  IonItem,
  IonLabel,
  IonList,
  IonNote,
  IonPage,
  IonRefresher,
  IonRefresherContent,
  IonTitle,
  IonToolbar,
} from '@ionic/vue';
import { mobileApi } from '@/services/api';
import AppBackButton from '@/components/AppBackButton.vue';

const tasks = ref<any[]>([]);
const loading = ref(false);

async function load() {
  loading.value = true;
  try {
    const payload = await mobileApi.tasks();
    tasks.value = payload.data || [];
  } finally {
    loading.value = false;
  }
}

async function refresh(event: CustomEvent) {
  await load();
  (event.target as HTMLIonRefresherElement).complete();
}

onMounted(load);
</script>
