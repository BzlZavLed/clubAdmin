<template>
  <ion-page>
    <ion-header>
      <ion-toolbar>
        <app-back-button />
        <ion-title>Children</ion-title>
      </ion-toolbar>
    </ion-header>
    <ion-content class="ion-padding">
      <div class="action-row">
        <ion-button size="small" :disabled="!clubOptions.length" @click="openCreate">New child</ion-button>
        <ion-button size="small" fill="outline" :disabled="!clubOptions.length" @click="openLink">Link existing</ion-button>
      </div>

      <div v-if="!clubOptions.length" class="invite-code-panel">
        <h2>Church invite code</h2>
        <p>Ask your church or club staff for the invite code, then enter it here to enable the club selector for Adventurer and Pathfinder children.</p>
        <ion-list inset>
          <ion-item>
            <ion-input
              v-model="inviteCode"
              label="Invite code"
              label-placement="stacked"
              autocapitalize="characters"
              :disabled="applyingInvite"
            />
          </ion-item>
        </ion-list>
        <ion-note v-if="inviteMessage" color="success">{{ inviteMessage }}</ion-note>
        <ion-note v-if="inviteError" color="danger">{{ inviteError }}</ion-note>
        <ion-button expand="block" :disabled="applyingInvite || !inviteCode.trim()" @click="applyInviteCode">
          {{ applyingInvite ? 'Checking...' : 'Apply invite code' }}
        </ion-button>
      </div>

      <ion-list inset>
        <ion-item v-for="child in children" :key="child.member_id" button @click="openEdit(child)">
          <ion-label>
            <h2>{{ child.name }}</h2>
            <p>{{ child.member_label }} · {{ child.club_name }} · {{ child.class_name || 'No class' }}</p>
          </ion-label>
        </ion-item>
      </ion-list>
      <ion-note v-if="!children.length">No children linked to this parent account.</ion-note>

      <ion-modal :is-open="childModalOpen" @didDismiss="closeChildModal">
        <ion-header>
          <ion-toolbar>
            <ion-title>{{ editingChild ? 'Edit child' : 'New child' }}</ion-title>
            <ion-buttons slot="end">
              <ion-button @click="closeChildModal">Close</ion-button>
            </ion-buttons>
          </ion-toolbar>
        </ion-header>
        <ion-content class="ion-padding">
          <ion-list inset>
            <ion-item v-if="!editingChild">
              <ion-select v-model="form.club_id" label="Club" label-placement="stacked" interface="popover">
                <ion-select-option v-for="club in clubOptions" :key="club.id" :value="club.id">
                  {{ club.club_name }} · {{ club.club_type }}
                </ion-select-option>
              </ion-select>
            </ion-item>
            <ion-item v-if="!editingChild">
              <ion-select v-model="form.member_type" label="Member type" label-placement="stacked" interface="popover">
                <ion-select-option value="adventurers">Adventurer</ion-select-option>
                <ion-select-option value="pathfinders">Pathfinder</ion-select-option>
              </ion-select>
            </ion-item>
            <ion-item>
              <ion-input v-model="form.applicant_name" label="Name" label-placement="stacked" />
            </ion-item>
            <ion-item>
              <ion-input v-model="form.birthdate" type="date" label="Birthdate" label-placement="stacked" />
            </ion-item>
            <ion-item>
              <ion-input v-model="form.grade" label="Grade" label-placement="stacked" />
            </ion-item>
            <ion-item>
              <ion-input v-model="form.cell_number" label="Phone" label-placement="stacked" />
            </ion-item>
            <ion-item>
              <ion-input v-model="form.email_address" type="email" label="Email" label-placement="stacked" />
            </ion-item>
            <ion-item>
              <ion-input v-model="form.parent_name" label="Parent name" label-placement="stacked" />
            </ion-item>
            <ion-item>
              <ion-input v-model="form.parent_cell" label="Parent phone" label-placement="stacked" />
            </ion-item>
            <ion-item>
              <ion-input v-model="form.mailing_address" label="Mailing address" label-placement="stacked" />
            </ion-item>
            <ion-item>
              <ion-input v-model="form.home_address" label="Home address" label-placement="stacked" />
            </ion-item>
            <ion-item>
              <ion-input v-model="form.emergency_contact" label="Emergency contact" label-placement="stacked" />
            </ion-item>
            <ion-item>
              <ion-textarea v-model="form.health_history" label="Health history" label-placement="stacked" auto-grow />
            </ion-item>
            <ion-item>
              <ion-textarea v-model="form.allergies" label="Allergies" label-placement="stacked" auto-grow />
            </ion-item>
            <ion-item>
              <ion-textarea v-model="form.physical_restrictions" label="Physical restrictions" label-placement="stacked" auto-grow />
            </ion-item>
            <ion-item>
              <ion-input v-model="form.signature" label="Signature" label-placement="stacked" />
            </ion-item>
          </ion-list>

          <ion-note v-if="!editingChild && !clubOptions.length">
            This parent account has no Adventurer or Pathfinder clubs in its church.
          </ion-note>
          <ion-note v-if="formError" color="danger">{{ formError }}</ion-note>
          <ion-button expand="block" :disabled="saving" @click="saveChild">
            {{ saving ? 'Saving...' : 'Save' }}
          </ion-button>
        </ion-content>
      </ion-modal>

      <ion-modal :is-open="linkModalOpen" @didDismiss="linkModalOpen = false">
        <ion-header>
          <ion-toolbar>
            <ion-title>Link existing child</ion-title>
            <ion-buttons slot="end">
              <ion-button @click="linkModalOpen = false">Close</ion-button>
            </ion-buttons>
          </ion-toolbar>
        </ion-header>
        <ion-content class="ion-padding">
          <ion-searchbar v-model="linkSearch" placeholder="Search by child name" @ionInput="loadLinkable" />
          <ion-list inset>
            <ion-item v-for="candidate in linkable" :key="`${candidate.member_type}-${candidate.id_data}`">
              <ion-label>
                <h2>{{ candidate.name }}</h2>
                <p>{{ candidate.member_type }} · {{ candidate.club_name || 'No club' }}</p>
              </ion-label>
              <ion-button slot="end" size="small" :disabled="linking" @click="linkChild(candidate)">Link</ion-button>
            </ion-item>
          </ion-list>
          <ion-note v-if="!linking && !linkable.length">No linkable children found for this parent church/account.</ion-note>
        </ion-content>
      </ion-modal>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
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
  IonSearchbar,
  IonSelect,
  IonSelectOption,
  IonTextarea,
  IonTitle,
  IonToolbar,
} from '@ionic/vue';
import { mobileApi } from '@/services/api';
import AppBackButton from '@/components/AppBackButton.vue';

const route = useRoute();
const router = useRouter();
const children = ref<any[]>([]);
const clubOptions = ref<any[]>([]);
const childModalOpen = ref(false);
const linkModalOpen = ref(false);
const editingChild = ref<any>(null);
const saving = ref(false);
const linking = ref(false);
const applyingInvite = ref(false);
const formError = ref('');
const inviteCode = ref('');
const inviteError = ref('');
const inviteMessage = ref('');
const linkSearch = ref('');
const linkable = ref<any[]>([]);

const form = reactive({
  club_id: '',
  member_type: 'pathfinders',
  applicant_name: '',
  birthdate: '',
  grade: '',
  cell_number: '',
  email_address: '',
  parent_name: '',
  parent_cell: '',
  mailing_address: '',
  home_address: '',
  emergency_contact: '',
  health_history: '',
  allergies: '',
  physical_restrictions: '',
  signature: '',
});

async function load() {
  const payload = await mobileApi.parentChildren();
  children.value = payload.data || [];
  clubOptions.value = payload.club_options || [];
}

function openRequestedChild() {
  const requestedChild = route.query.child;
  if (!requestedChild) return;

  const childId = Array.isArray(requestedChild) ? requestedChild[0] : requestedChild;
  const child = children.value.find((item) => String(item.member_id) === String(childId));

  if (child) {
    openEdit(child);
  }

  router.replace({ path: '/parent/children' });
}

async function applyInviteCode() {
  applyingInvite.value = true;
  inviteError.value = '';
  inviteMessage.value = '';

  try {
    const payload = await mobileApi.parentApplyChurchInvite(inviteCode.value);
    clubOptions.value = payload.club_options || [];
    inviteMessage.value = payload.church?.church_name
      ? `Church enabled: ${payload.church.church_name}.`
      : 'Church enabled.';
    inviteCode.value = '';
    await load();
  } catch (error: any) {
    const errors = error?.response?.data?.errors;
    inviteError.value = errors ? Object.values(errors).flat()[0] as string : (error?.response?.data?.message || 'Could not apply invite code.');
  } finally {
    applyingInvite.value = false;
  }
}

function resetForm() {
  Object.assign(form, {
    club_id: clubOptions.value[0]?.id || '',
    member_type: clubOptions.value[0]?.club_type === 'adventurers' ? 'adventurers' : 'pathfinders',
    applicant_name: '',
    birthdate: '',
    grade: '',
    cell_number: '',
    email_address: '',
    parent_name: '',
    parent_cell: '',
    mailing_address: '',
    home_address: '',
    emergency_contact: '',
    health_history: '',
    allergies: '',
    physical_restrictions: '',
    signature: '',
  });
  formError.value = '';
}

function openCreate() {
  editingChild.value = null;
  resetForm();
  childModalOpen.value = true;
}

function openEdit(child: any) {
  editingChild.value = child;
  Object.assign(form, {
    club_id: child.club_id || '',
    member_type: child.member_type === 'adventurers' ? 'adventurers' : 'pathfinders',
    applicant_name: child.applicant_name || child.name || '',
    birthdate: child.birthdate || '',
    grade: child.grade || '',
    cell_number: child.cell_number || '',
    email_address: child.email_address || '',
    parent_name: child.parent_name || '',
    parent_cell: child.parent_cell || '',
    mailing_address: child.mailing_address || '',
    home_address: child.home_address || '',
    emergency_contact: child.emergency_contact || '',
    health_history: child.health_history || '',
    allergies: child.allergies || '',
    physical_restrictions: child.physical_restrictions || '',
    signature: child.signature || '',
  });
  formError.value = '';
  childModalOpen.value = true;
}

function closeChildModal() {
  childModalOpen.value = false;
  editingChild.value = null;
}

async function saveChild() {
  saving.value = true;
  formError.value = '';
  try {
    if (editingChild.value) {
      await mobileApi.parentUpdateChild(editingChild.value.member_id, { ...form });
    } else {
      await mobileApi.parentCreateChild({ ...form });
    }
    closeChildModal();
    await load();
  } catch (error: any) {
    const errors = error?.response?.data?.errors;
    formError.value = errors ? Object.values(errors).flat()[0] as string : (error?.response?.data?.message || 'Could not save child.');
  } finally {
    saving.value = false;
  }
}

async function openLink() {
  linkModalOpen.value = true;
  linkSearch.value = '';
  await loadLinkable();
}

async function loadLinkable() {
  linking.value = true;
  try {
    const payload = await mobileApi.parentLinkableChildren(linkSearch.value);
    linkable.value = payload.data || [];
  } finally {
    linking.value = false;
  }
}

async function linkChild(candidate: any) {
  linking.value = true;
  try {
    await mobileApi.parentLinkChild({
      member_type: candidate.member_type,
      id_data: candidate.id_data,
    });
    linkModalOpen.value = false;
    await load();
  } finally {
    linking.value = false;
  }
}

onMounted(async () => {
  await load();
  openRequestedChild();
});
</script>

<style scoped>
.invite-code-panel {
  border: 1px solid var(--ion-color-step-200, #d7d8da);
  border-radius: 8px;
  margin: 12px 0 18px;
  padding: 14px;
}

.invite-code-panel h2 {
  font-size: 18px;
  font-weight: 700;
  margin: 0 0 6px;
}

.invite-code-panel p {
  color: var(--ion-color-medium);
  font-size: 14px;
  line-height: 1.4;
  margin: 0 0 10px;
}
</style>
