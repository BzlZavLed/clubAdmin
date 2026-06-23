<template>
  <section class="calendar-shell">
    <div class="calendar-toolbar">
      <ion-button fill="clear" size="small" aria-label="Previous month" @click="shiftMonth(-1)">
        <ion-icon slot="icon-only" :icon="chevronBackOutline" />
      </ion-button>
      <div class="calendar-title">
        <strong>{{ monthLabel }}</strong>
        <span>{{ selectedDateLabel }}</span>
      </div>
      <ion-button fill="clear" size="small" aria-label="Next month" @click="shiftMonth(1)">
        <ion-icon slot="icon-only" :icon="chevronForwardOutline" />
      </ion-button>
    </div>

    <div class="calendar-weekdays">
      <span v-for="day in weekdays" :key="day">{{ day }}</span>
    </div>

    <div class="calendar-grid">
      <button
        v-for="(cell, index) in calendarCells"
        :key="cell?.date || `blank-${index}`"
        type="button"
        class="calendar-cell"
        :class="{ blank: !cell, today: cell && isToday(cell.date), selected: cell && selectedDate === cell.date }"
        :disabled="!cell"
        @click="cell && selectDate(cell.date)"
      >
        <span v-if="cell" class="day-number">{{ cell.label }}</span>
        <span v-if="cell?.events?.length" class="event-dots">
          <span
            v-for="event in cell.events.slice(0, 3)"
            :key="`${event.id}-${cell.date}`"
            class="event-dot"
            :class="eventDotClass(event)"
          />
        </span>
      </button>
    </div>

    <div class="calendar-agenda">
      <div class="agenda-header">
        <h2>{{ selectedDateLongLabel }}</h2>
        <span>{{ selectedEvents.length }}</span>
      </div>

      <ion-list v-if="selectedEvents.length" inset>
        <ion-item v-for="event in selectedEvents" :key="`${event.id}-${event._occurrence_date}`" @click="openEvent(event)">
          <ion-label>
            <h2>{{ event.title }}</h2>
            <p>{{ timeRange(event) || 'All day' }} · {{ event.club_name }}</p>
            <p>{{ event.location || 'No location' }}</p>
          </ion-label>
        </ion-item>
      </ion-list>
      <ion-note v-else>No events on this date.</ion-note>
    </div>

    <ion-modal :is-open="Boolean(activeEvent)" @didDismiss="activeEvent = null">
      <ion-header>
        <ion-toolbar>
          <ion-title>Event</ion-title>
          <ion-buttons slot="end">
            <ion-button @click="activeEvent = null">Close</ion-button>
          </ion-buttons>
        </ion-toolbar>
      </ion-header>
      <ion-content class="ion-padding" v-if="activeEvent">
        <section class="event-detail">
          <p class="eyebrow">{{ activeEvent.club_name }}</p>
          <h1>{{ activeEvent.title }}</h1>
          <p>{{ activeEvent.date }}{{ activeEvent.end_date && activeEvent.end_date !== activeEvent.date ? ` to ${activeEvent.end_date}` : '' }}</p>
          <p>{{ timeRange(activeEvent) || 'All day' }}</p>
          <p>{{ activeEvent.location || 'No location' }}</p>
          <p v-if="activeEvent.description">{{ activeEvent.description }}</p>
          <ion-badge v-if="activeEvent.is_offsite" color="warning">
            Offsite{{ activeEvent.location_tracking_allowed ? ' · safety tracking available' : '' }}
          </ion-badge>
        </section>

        <ion-list v-if="activeEvent.class_plans?.length" inset>
          <ion-item v-for="plan in activeEvent.class_plans" :key="plan.id">
            <ion-label>
              <h2>{{ plan.title }}</h2>
              <p>{{ plan.status }}</p>
            </ion-label>
          </ion-item>
        </ion-list>
      </ion-content>
    </ion-modal>
  </section>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import {
  IonBadge,
  IonButton,
  IonButtons,
  IonContent,
  IonHeader,
  IonIcon,
  IonItem,
  IonLabel,
  IonList,
  IonModal,
  IonNote,
  IonTitle,
  IonToolbar,
} from '@ionic/vue';
import { chevronBackOutline, chevronForwardOutline } from 'ionicons/icons';

const props = defineProps<{
  events: any[];
}>();

const todayIso = new Date().toISOString().slice(0, 10);
const monthCursor = ref(todayIso);
const selectedDate = ref(todayIso);
const activeEvent = ref<any>(null);
const weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

const normalizedEvents = computed(() =>
  (props.events || []).flatMap((event) => {
    const start = normalizeDate(event.date);
    const end = normalizeDate(event.end_date || event.date);
    return expandDateRange(start, end).map((date) => ({
      ...event,
      _occurrence_date: date,
    }));
  })
);

const eventsByDate = computed(() => {
  const grouped: Record<string, any[]> = {};
  for (const event of normalizedEvents.value) {
    if (!grouped[event._occurrence_date]) grouped[event._occurrence_date] = [];
    grouped[event._occurrence_date].push(event);
  }
  Object.values(grouped).forEach((list) => list.sort(sortEvents));
  return grouped;
});

const calendarCells = computed(() => {
  const date = new Date(`${monthCursor.value}T00:00:00`);
  const year = date.getFullYear();
  const month = date.getMonth();
  const first = new Date(year, month, 1);
  const days = new Date(year, month + 1, 0).getDate();
  const cells: Array<any | null> = [];

  for (let i = 0; i < first.getDay(); i += 1) cells.push(null);
  for (let day = 1; day <= days; day += 1) {
    const dateStr = formatDate(year, month + 1, day);
    cells.push({
      label: day,
      date: dateStr,
      events: eventsByDate.value[dateStr] || [],
    });
  }
  return cells;
});

const monthLabel = computed(() =>
  new Date(`${monthCursor.value}T00:00:00`).toLocaleDateString(undefined, {
    month: 'long',
    year: 'numeric',
  })
);

const selectedEvents = computed(() => eventsByDate.value[selectedDate.value] || []);
const selectedDateLabel = computed(() => shortDate(selectedDate.value));
const selectedDateLongLabel = computed(() =>
  new Date(`${selectedDate.value}T00:00:00`).toLocaleDateString(undefined, {
    weekday: 'long',
    month: 'long',
    day: 'numeric',
  })
);

function normalizeDate(value: unknown) {
  if (!value) return '';
  const str = String(value);
  return str.includes('T') ? str.slice(0, 10) : str;
}

function formatDate(year: number, month: number, day: number) {
  return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

function expandDateRange(startStr: string, endStr: string) {
  if (!startStr) return [];
  const start = new Date(`${startStr}T00:00:00`);
  const end = new Date(`${endStr || startStr}T00:00:00`);
  if (Number.isNaN(start.getTime())) return [];
  if (Number.isNaN(end.getTime()) || end < start) return [startStr];

  const dates: string[] = [];
  const cursor = new Date(start);
  while (cursor <= end) {
    dates.push(formatDate(cursor.getFullYear(), cursor.getMonth() + 1, cursor.getDate()));
    cursor.setDate(cursor.getDate() + 1);
  }
  return dates;
}

function sortEvents(a: any, b: any) {
  if (a.start_time === b.start_time) return String(a.title || '').localeCompare(String(b.title || ''));
  if (!a.start_time) return 1;
  if (!b.start_time) return -1;
  return String(a.start_time).localeCompare(String(b.start_time));
}

function shiftMonth(delta: number) {
  const date = new Date(`${monthCursor.value}T00:00:00`);
  date.setDate(1);
  date.setMonth(date.getMonth() + delta);
  monthCursor.value = date.toISOString().slice(0, 10);
}

function selectDate(date: string) {
  selectedDate.value = date;
}

function openEvent(event: any) {
  activeEvent.value = event;
}

function isToday(date: string) {
  return date === todayIso;
}

function shortDate(date: string) {
  return new Date(`${date}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

function formatTime(value: string) {
  if (!value) return '';
  const [hour, minute] = value.split(':');
  const date = new Date();
  date.setHours(Number(hour), Number(minute || 0));
  return date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
}

function timeRange(event: any) {
  const start = event?.start_time ? formatTime(event.start_time) : '';
  const end = event?.end_time ? formatTime(event.end_time) : '';
  if (start && end) return `${start} - ${end}`;
  return start || end || '';
}

function eventDotClass(event: any) {
  if (event.is_offsite) return 'warning';
  if (event.meeting_type === 'sabbath') return 'primary';
  if (event.meeting_type === 'sunday') return 'success';
  return 'neutral';
}

watch(
  () => props.events,
  (events) => {
    const firstDate = normalizeDate(events?.[0]?.date);
    if (firstDate) {
      monthCursor.value = firstDate;
      selectedDate.value = firstDate;
    }
  },
  { immediate: true }
);
</script>
