<template>
  <ion-page>
    <ion-header>
      <ion-toolbar>
        <app-back-button />
        <ion-title>Payments</ion-title>
      </ion-toolbar>
    </ion-header>
    <ion-content class="ion-padding">
      <ion-refresher slot="fixed" @ionRefresh="refreshPayments">
        <ion-refresher-content />
      </ion-refresher>
      <h2 class="section-title">Expected</h2>
      <ion-list inset>
        <ion-item v-for="payment in expected" :key="payment.row_key" button @click="openPayment(payment)">
          <ion-label>
            <h2>{{ payment.concept_name }}</h2>
            <p>{{ payment.member_name }} · {{ statusLabel(payment.status) }} · ${{ money(payment.remaining_amount) }} remaining</p>
            <p>{{ payment.club_name }} · {{ payment.due_date || 'No due date' }}</p>
          </ion-label>
          <ion-badge slot="end" :color="statusColor(payment.status)">
            ${{ money(payment.reusable ? payment.expected_amount : payment.available_amount ?? payment.remaining_amount) }}
          </ion-badge>
        </ion-item>
      </ion-list>
      <ion-note v-if="!expected.length">No expected payments found.</ion-note>

      <h2 class="section-title">Submitted transfers</h2>
      <ion-list inset>
        <ion-item v-for="submission in transferSubmissions" :key="submission.id">
          <ion-label>
            <h2>{{ submission.concept_name }}</h2>
            <p>{{ submission.member_name }} · {{ statusLabel(submission.status) }} · ${{ money(submission.amount) }}</p>
            <p>{{ submission.payment_date }} · {{ submission.reference || 'No reference' }}</p>
          </ion-label>
        </ion-item>
      </ion-list>
      <ion-note v-if="!transferSubmissions.length">No submitted transfer receipts yet.</ion-note>

      <h2 class="section-title">Receipts</h2>
      <ion-list inset>
        <ion-item v-for="receipt in receipts" :key="receipt.id" :router-link="`/parent/receipts/${receipt.id}`">
          <ion-label>
            <h2>{{ receipt.receipt_number }}</h2>
            <p>{{ receipt.member_name }} · ${{ money(receipt.amount_paid) }} · {{ receipt.payment_date }}</p>
          </ion-label>
        </ion-item>
      </ion-list>
      <ion-note v-if="!receipts.length">Approved receipts will appear here after club validation.</ion-note>

      <ion-modal :is-open="paymentModalOpen" @didDismiss="closePayment">
        <ion-header>
          <ion-toolbar>
            <ion-title>Payment detail</ion-title>
            <ion-buttons slot="end">
              <ion-button @click="closePayment">Close</ion-button>
            </ion-buttons>
          </ion-toolbar>
        </ion-header>
        <ion-content class="ion-padding">
          <template v-if="selectedPayment">
            <section class="payment-detail">
              <div>
                <h2>{{ selectedPayment.concept_name }}</h2>
                <p>{{ selectedPayment.member_name }} · {{ selectedPayment.club_name }}</p>
                <p v-if="selectedPayment.class_name">{{ selectedPayment.class_name }}</p>
              </div>
              <ion-badge :color="statusColor(selectedPayment.status)">{{ statusLabel(selectedPayment.status) }}</ion-badge>
            </section>

            <div class="amount-grid">
              <div>
                <span>Expected</span>
                <strong>${{ money(selectedPayment.expected_amount) }}</strong>
              </div>
              <div>
                <span>Paid</span>
                <strong>${{ money(selectedPayment.paid_amount) }}</strong>
              </div>
              <div>
                <span>Available</span>
                <strong>${{ money(selectedPayment.reusable ? selectedPayment.expected_amount : selectedPayment.available_amount ?? selectedPayment.remaining_amount) }}</strong>
              </div>
            </div>

            <section class="account-panel" v-if="selectedPayment.deposit_account">
              <h3>{{ selectedPayment.deposit_account.label || selectedPayment.deposit_account_label || 'Club account' }}</h3>
              <p v-for="line in depositAccountLines(selectedPayment.deposit_account)" :key="line">{{ line }}</p>
              <ion-note v-if="selectedPayment.deposit_account.deposit_instructions">
                {{ selectedPayment.deposit_account.deposit_instructions }}
              </ion-note>
            </section>
            <ion-note v-else color="warning">
              {{ selectedPayment.transfer_blocked_reason || 'The club has not published payment account information yet.' }}
            </ion-note>

            <section v-if="relatedReceipts.length" class="detail-section">
              <h3>Approved receipts</h3>
              <ion-list inset>
                <ion-item v-for="receipt in relatedReceipts" :key="receipt.id" :router-link="`/parent/receipts/${receipt.id}`" @click="closePayment">
                  <ion-label>
                    <h2>{{ receipt.receipt_number }}</h2>
                    <p>${{ money(receipt.amount) }} · {{ receipt.payment_date || receipt.issued_at }}</p>
                  </ion-label>
                </ion-item>
              </ion-list>
            </section>

            <section v-if="relatedSubmissions.length" class="detail-section">
              <h3>Submitted for validation</h3>
              <ion-list inset>
                <ion-item v-for="submission in relatedSubmissions" :key="submission.id">
                  <ion-label>
                    <h2>${{ money(submission.amount) }} · {{ statusLabel(submission.status) }}</h2>
                    <p>{{ submission.payment_date }} · {{ submission.reference || 'No reference' }}</p>
                    <p v-if="submission.review_notes">{{ submission.review_notes }}</p>
                  </ion-label>
                </ion-item>
              </ion-list>
            </section>

            <section class="detail-section" v-if="selectedPayment.can_submit_transfer">
              <h3>Upload transfer receipt</h3>
              <ion-list inset>
                <ion-item>
                  <ion-input v-model="transferForm.amount" type="number" label="Amount" label-placement="stacked" />
                </ion-item>
                <ion-item>
                  <ion-input v-model="transferForm.payment_date" type="date" label="Payment date" label-placement="stacked" />
                </ion-item>
                <ion-item>
                  <ion-input v-model="transferForm.reference" label="Reference" label-placement="stacked" />
                </ion-item>
                <ion-item>
                  <ion-textarea v-model="transferForm.notes" label="Notes" label-placement="stacked" auto-grow />
                </ion-item>
              </ion-list>
              <label class="file-upload">
                <span>{{ receiptFile?.name || 'Select receipt image' }}</span>
                <input type="file" accept="image/*" @change="onReceiptFileChange" />
              </label>
              <ion-note v-if="uploadMessage" color="success">{{ uploadMessage }}</ion-note>
              <ion-note v-if="uploadError" color="danger">{{ uploadError }}</ion-note>
              <ion-button expand="block" :disabled="uploading" @click="submitTransfer">
                {{ uploading ? 'Uploading...' : 'Submit for validation' }}
              </ion-button>
            </section>
            <ion-note v-else-if="selectedPayment.transfer_blocked_reason" color="medium">
              {{ selectedPayment.transfer_blocked_reason }}
            </ion-note>
          </template>
        </ion-content>
      </ion-modal>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import {
  IonBadge,
  IonButton,
  IonButtons,
  IonContent,
  IonHeader,
  IonInput,
  IonItem,
  IonLabel,
  IonList,
  IonModal,
  IonNote,
  IonPage,
  IonRefresher,
  IonRefresherContent,
  IonTextarea,
  IonTitle,
  IonToolbar,
} from '@ionic/vue';
import { mobileApi } from '@/services/api';
import AppBackButton from '@/components/AppBackButton.vue';

const expected = ref<any[]>([]);
const receipts = ref<any[]>([]);
const transferSubmissions = ref<any[]>([]);
const selectedPayment = ref<any>(null);
const paymentModalOpen = ref(false);
const receiptFile = ref<File | null>(null);
const uploading = ref(false);
const uploadError = ref('');
const uploadMessage = ref('');
const money = (value: unknown) => Number(value || 0).toFixed(2);

const transferForm = reactive({
  amount: '',
  payment_date: new Date().toISOString().slice(0, 10),
  reference: '',
  notes: '',
});

const relatedSubmissions = computed(() => {
  if (!selectedPayment.value) return [];
  return transferSubmissions.value.filter((submission) =>
    submission.concept_name === selectedPayment.value.concept_name
    && submission.member_name === selectedPayment.value.member_name
  );
});

const relatedReceipts = computed(() => selectedPayment.value?.receipt_links || []);

function statusLabel(status: string) {
  if (status === 'paid' || status === 'approved') return 'Approved';
  if (status === 'pending_review' || status === 'pending') return 'In review';
  if (status === 'rejected') return 'Rejected';
  if (status === 'optional') return 'Optional';
  return 'Due';
}

function statusColor(status: string) {
  if (status === 'paid' || status === 'approved') return 'success';
  if (status === 'pending_review' || status === 'pending') return 'warning';
  if (status === 'rejected') return 'danger';
  return 'primary';
}

function depositAccountLines(account: any): string[] {
  if (!account) return [];
  return [
    account.bank_name ? `Bank: ${account.bank_name}` : null,
    account.account_holder ? `Account holder: ${account.account_holder}` : null,
    account.account_type ? `Type: ${account.account_type}` : null,
    account.account_number ? `Account: ${account.account_number}` : null,
    account.routing_number ? `Routing: ${account.routing_number}` : null,
    account.zelle_email ? `Zelle: ${account.zelle_email}` : null,
    account.zelle_phone ? `Zelle phone: ${account.zelle_phone}` : null,
  ].filter((line): line is string => Boolean(line));
}

async function loadPayments() {
  const payload = await mobileApi.parentPayments();
  expected.value = payload.expected_payments || [];
  transferSubmissions.value = payload.transfer_submissions || [];
  receipts.value = payload.receipts || [];
}

async function refreshPayments(event: any) {
  try {
    await loadPayments();
  } finally {
    event.target.complete();
  }
}

function openPayment(payment: any) {
  selectedPayment.value = payment;
  transferForm.amount = String(Number(payment.reusable ? payment.expected_amount : payment.available_amount ?? payment.remaining_amount ?? payment.expected_amount ?? 0).toFixed(2));
  transferForm.payment_date = new Date().toISOString().slice(0, 10);
  transferForm.reference = '';
  transferForm.notes = '';
  receiptFile.value = null;
  uploadError.value = '';
  uploadMessage.value = '';
  paymentModalOpen.value = true;
}

function closePayment() {
  paymentModalOpen.value = false;
  selectedPayment.value = null;
  receiptFile.value = null;
}

function onReceiptFileChange(event: Event) {
  const input = event.target as HTMLInputElement;
  receiptFile.value = input.files?.[0] || null;
}

async function submitTransfer() {
  if (!selectedPayment.value) return;
  if (!receiptFile.value) {
    uploadError.value = 'Select a receipt image before submitting.';
    return;
  }

  uploading.value = true;
  uploadError.value = '';
  uploadMessage.value = '';

  try {
    const payload = await mobileApi.parentSubmitTransfer({
      payment_concept_id: selectedPayment.value.concept_id,
      member_id: selectedPayment.value.member_id,
      amount: transferForm.amount,
      payment_date: transferForm.payment_date,
      reference: transferForm.reference,
      notes: transferForm.notes,
      receipt_image: receiptFile.value,
    });
    expected.value = payload.expected_payments || expected.value;
    transferSubmissions.value = payload.transfer_submissions || transferSubmissions.value;
    receipts.value = payload.receipts || receipts.value;
    selectedPayment.value = expected.value.find((payment) => payment.row_key === selectedPayment.value.row_key) || selectedPayment.value;
    receiptFile.value = null;
    uploadMessage.value = payload.message || 'Receipt submitted for validation.';
  } catch (error: any) {
    const errors = error?.response?.data?.errors;
    uploadError.value = errors ? Object.values(errors).flat()[0] as string : (error?.response?.data?.message || 'Could not upload receipt.');
  } finally {
    uploading.value = false;
  }
}

onMounted(loadPayments);
</script>

<style scoped>
.payment-detail {
  align-items: flex-start;
  display: flex;
  gap: 12px;
  justify-content: space-between;
}

.payment-detail h2,
.detail-section h3,
.account-panel h3 {
  font-size: 18px;
  font-weight: 700;
  margin: 0 0 4px;
}

.payment-detail p,
.account-panel p {
  color: var(--ion-color-medium);
  font-size: 14px;
  margin: 2px 0;
}

.amount-grid {
  display: grid;
  gap: 8px;
  grid-template-columns: repeat(3, 1fr);
  margin: 16px 0;
}

.amount-grid div {
  background: var(--ion-color-step-50, #f7f7f7);
  border: 1px solid var(--ion-color-step-150, #e0e0e0);
  border-radius: 8px;
  padding: 10px;
}

.amount-grid span {
  color: var(--ion-color-medium);
  display: block;
  font-size: 12px;
}

.amount-grid strong {
  display: block;
  font-size: 16px;
  margin-top: 4px;
}

.account-panel,
.detail-section {
  margin-top: 16px;
}

.account-panel {
  background: var(--ion-color-primary-tint);
  border: 1px solid var(--ion-color-primary);
  border-radius: 8px;
  color: var(--ion-color-primary-contrast);
  padding: 12px;
}

.account-panel p,
.account-panel ion-note {
  color: var(--ion-color-primary-contrast);
}

.file-upload {
  align-items: center;
  border: 1px dashed var(--ion-color-step-300, #c8c7cc);
  border-radius: 8px;
  display: flex;
  justify-content: center;
  margin: 12px 0;
  min-height: 48px;
  padding: 12px;
}

.file-upload input {
  display: none;
}
</style>
