import { createRouter, createWebHistory } from '@ionic/vue-router';
import LoginPage from '@/views/LoginPage.vue';
import HomePage from '@/views/HomePage.vue';
import TasksPage from '@/views/TasksPage.vue';
import LocationSafetyPage from '@/views/LocationSafetyPage.vue';
import AppTabs from '@/components/AppTabs.vue';
import ParentDashboardPage from '@/views/ParentDashboardPage.vue';
import ParentChildrenPage from '@/views/ParentChildrenPage.vue';
import ParentPaymentsPage from '@/views/ParentPaymentsPage.vue';
import ParentReceiptPage from '@/views/ParentReceiptPage.vue';
import ParentWorkplanPage from '@/views/ParentWorkplanPage.vue';

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    { path: '/', redirect: '/login' },
    { path: '/login', name: 'login', component: LoginPage },
    {
      path: '/',
      component: AppTabs,
      children: [
        { path: 'home', name: 'home', component: HomePage },
        { path: 'parent', name: 'parent-dashboard', component: ParentDashboardPage },
        { path: 'parent/children', name: 'parent-children', component: ParentChildrenPage },
        { path: 'parent/payments', name: 'parent-payments', component: ParentPaymentsPage },
        { path: 'parent/receipts/:id', name: 'parent-receipt', component: ParentReceiptPage },
        { path: 'parent/workplan', name: 'parent-workplan', component: ParentWorkplanPage },
        { path: 'tasks', name: 'tasks', component: TasksPage },
        { path: 'location-safety', name: 'location-safety', component: LocationSafetyPage },
      ],
    },
  ],
});

export default router;
