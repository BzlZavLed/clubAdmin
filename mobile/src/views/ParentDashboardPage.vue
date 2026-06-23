<template>
  <ion-page>
    <ion-header>
      <ion-toolbar>
        <app-back-button />
        <ion-title>Parent</ion-title>
      </ion-toolbar>
    </ion-header>
    <ion-content class="ion-padding">
      <ion-refresher slot="fixed" @ionRefresh="refresh">
        <ion-refresher-content />
      </ion-refresher>

      <section class="summary-grid">
        <div class="metric">
          <span>Children</span>
          <strong>{{ dashboard?.children?.length || 0 }}</strong>
        </div>
        <div class="metric">
          <span>Expected</span>
          <strong>${{ money(dashboard?.payment_summary?.total_expected) }}</strong>
        </div>
        <div class="metric">
          <span>Paid</span>
          <strong>${{ money(dashboard?.payment_summary?.total_paid) }}</strong>
        </div>
      </section>

      <h2 class="section-title">Children</h2>
      <ion-list inset>
        <ion-item v-for="child in dashboard?.children || []" :key="child.member_id" button @click="openChild(child)">
          <ion-label>
            <h2>{{ child.name }}</h2>
            <p>{{ child.member_label }} · {{ child.club_name }} · {{ child.class_name || 'No class' }}</p>
          </ion-label>
        </ion-item>
      </ion-list>
      <ion-note v-if="dashboard && !dashboard.children?.length">No children linked to this parent account.</ion-note>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { IonContent, IonHeader, IonItem, IonLabel, IonList, IonNote, IonPage, IonRefresher, IonRefresherContent, IonTitle, IonToolbar } from '@ionic/vue';
import { mobileApi } from '@/services/api';
import AppBackButton from '@/components/AppBackButton.vue';

const router = useRouter();
const dashboard = ref<any>(null);

const money = (value: unknown) => Number(value || 0).toFixed(2);

function openChild(child: any) {
  router.push({ path: '/parent/children', query: { child: child.member_id } });
}

async function load() {
  dashboard.value = await mobileApi.parentDashboard();
}

async function refresh(event: CustomEvent) {
  await load();
  (event.target as HTMLIonRefresherElement).complete();
}

onMounted(load);
</script>
