<template>
  <ion-page>
    <ion-content class="ion-padding auth-screen">
      <section class="login-panel">
        <p class="eyebrow">Admin My Club</p>
        <h1>Mobile access</h1>
        <ion-list inset>
          <ion-item>
            <ion-input v-model="email" type="email" label="Email" label-placement="stacked" autocomplete="email" />
          </ion-item>
          <ion-item>
            <ion-input v-model="password" type="password" label="Password" label-placement="stacked" />
          </ion-item>
        </ion-list>
        <ion-button expand="block" :disabled="loading" @click="submit">
          {{ loading ? 'Signing in...' : 'Sign in' }}
        </ion-button>
        <p v-if="error" class="error-text">{{ error }}</p>
      </section>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { IonButton, IonContent, IonInput, IonItem, IonList, IonPage } from '@ionic/vue';
import { mobileApi } from '@/services/api';

const router = useRouter();
const email = ref('');
const password = ref('');
const loading = ref(false);
const error = ref('');

async function submit() {
  loading.value = true;
  error.value = '';
  try {
    await mobileApi.login(email.value, password.value);
    await router.replace('/home');
  } catch (requestError: any) {
    error.value = requestError?.response?.data?.message || 'Could not sign in.';
  } finally {
    loading.value = false;
  }
}
</script>
