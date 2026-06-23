<template>
  <ion-page>
    <ion-header>
      <ion-toolbar>
        <app-back-button />
        <ion-title>Location safety</ion-title>
      </ion-toolbar>
    </ion-header>
    <ion-content class="ion-padding">
      <section class="status-panel">
        <p class="eyebrow">Prepared frame</p>
        <h1>Offsite tracking</h1>
        <p>
          Native background tracking will be wired here after the server consent,
          active session, and app-store privacy work are complete.
        </p>
        <ion-button expand="block" fill="outline" @click="requestPermission">
          Check location permission
        </ion-button>
        <ion-note v-if="permissionStatus">{{ permissionStatus }}</ion-note>
      </section>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { IonButton, IonContent, IonHeader, IonNote, IonPage, IonTitle, IonToolbar } from '@ionic/vue';
import { requestForegroundLocationPermission } from '@/services/location';
import AppBackButton from '@/components/AppBackButton.vue';

const permissionStatus = ref('');

async function requestPermission() {
  const result = await requestForegroundLocationPermission();
  permissionStatus.value = `Location: ${result.location}`;
}
</script>
