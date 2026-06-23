<template>
  <ion-page>
    <ion-header>
      <ion-toolbar>
        <app-back-button />
        <ion-title>Receipt</ion-title>
      </ion-toolbar>
    </ion-header>
    <ion-content class="ion-padding">
      <section v-if="receipt" class="receipt-card">
        <div class="receipt-heading">
          <div>
            <p class="eyebrow">Receipt</p>
            <h1>{{ receipt.receipt_number }}</h1>
          </div>
          <strong>${{ money(receipt.payment.amount_paid) }}</strong>
        </div>

        <div class="receipt-status">
          <span>{{ receipt.delivery_status || 'issued' }}</span>
          <span>{{ receipt.issued_at }}</span>
        </div>

        <div class="receipt-section">
          <h2>Organization</h2>
          <dl>
            <div>
              <dt>Club</dt>
              <dd>{{ receipt.club.name || '—' }}</dd>
            </div>
            <div>
              <dt>Church</dt>
              <dd>{{ receipt.club.church_name || '—' }}</dd>
            </div>
            <div>
              <dt>Email</dt>
              <dd>{{ receipt.club.email || '—' }}</dd>
            </div>
          </dl>
        </div>

        <div class="receipt-section">
          <h2>Payment</h2>
          <dl>
            <div>
              <dt>Payer</dt>
              <dd>{{ receipt.payment.payer_name || '—' }}</dd>
            </div>
            <div>
              <dt>Concept</dt>
              <dd>{{ receipt.payment.concept_name || '—' }}</dd>
            </div>
            <div>
              <dt>Date</dt>
              <dd>{{ receipt.payment.payment_date || '—' }}</dd>
            </div>
            <div>
              <dt>Method</dt>
              <dd>{{ receipt.payment.payment_type || '—' }}</dd>
            </div>
            <div>
              <dt>Received by</dt>
              <dd>{{ receipt.payment.received_by || '—' }}</dd>
            </div>
          </dl>
        </div>

        <div v-if="receipt.payment.allocations?.length" class="receipt-section">
          <h2>Allocations</h2>
          <div v-for="allocation in receipt.payment.allocations" :key="allocation.id" class="allocation-row">
            <span>{{ allocation.label }}</span>
            <strong>${{ money(allocation.amount) }}</strong>
          </div>
        </div>

        <div v-if="receipt.payment.notes" class="receipt-section">
          <h2>Notes</h2>
          <p>{{ receipt.payment.notes }}</p>
        </div>

        <ion-button v-if="receipt.download_url" expand="block" fill="outline" :href="receipt.download_url" target="_blank">
          Open PDF
        </ion-button>
      </section>

      <ion-note v-else>Loading receipt...</ion-note>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { IonButton, IonContent, IonHeader, IonNote, IonPage, IonTitle, IonToolbar } from '@ionic/vue';
import AppBackButton from '@/components/AppBackButton.vue';
import { mobileApi } from '@/services/api';

const route = useRoute();
const receipt = ref<any>(null);
const money = (value: unknown) => Number(value || 0).toFixed(2);

onMounted(async () => {
  const payload = await mobileApi.parentReceipt(String(route.params.id));
  receipt.value = payload.data;
});
</script>
