import axios from 'axios';
import { Preferences } from '@capacitor/preferences';

const apiBaseUrl = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000';

export const api = axios.create({
  baseURL: `${apiBaseUrl.replace(/\/$/, '')}/api/mobile`,
  timeout: 15000,
  headers: {
    Accept: 'application/json',
  },
});

api.interceptors.request.use(async (config) => {
  const { value } = await Preferences.get({ key: 'mobile_api_token' });
  if (value) {
    config.headers.Authorization = `Bearer ${value}`;
  }
  return config;
});

export const mobileApi = {
  async login(email: string, password: string) {
    const response = await api.post('/login', { email, password });
    const token = response.data?.token;
    if (token) {
      await Preferences.set({ key: 'mobile_api_token', value: token });
    }
    return response.data;
  },

  async logout() {
    try {
      await api.post('/logout');
    } finally {
      await Preferences.remove({ key: 'mobile_api_token' });
    }
  },

  async me() {
    const response = await api.get('/me');
    return response.data;
  },

  async tasks() {
    const response = await api.get('/tasks');
    return response.data;
  },

  async locationSession() {
    const response = await api.get('/location/session');
    return response.data;
  },

  async parentDashboard() {
    const response = await api.get('/parent/dashboard');
    return response.data;
  },

  async parentChildren() {
    const response = await api.get('/parent/children');
    return response.data;
  },

  async parentApplyChurchInvite(inviteCode: string) {
    const response = await api.post('/parent/church-invite', { invite_code: inviteCode });
    return response.data;
  },

  async parentLinkableChildren(name = '') {
    const response = await api.get('/parent/children/linkable', {
      params: name ? { name } : {},
    });
    return response.data;
  },

  async parentLinkChild(payload: { member_type: string; id_data: number }) {
    const response = await api.post('/parent/children/link', payload);
    return response.data;
  },

  async parentCreateChild(payload: Record<string, unknown>) {
    const response = await api.post('/parent/children', payload);
    return response.data;
  },

  async parentUpdateChild(memberId: string | number, payload: Record<string, unknown>) {
    const response = await api.put(`/parent/children/${memberId}`, payload);
    return response.data;
  },

  async parentPayments() {
    const response = await api.get('/parent/payments');
    return response.data;
  },

  async parentSubmitTransfer(payload: {
    payment_concept_id: string | number;
    member_id: string | number;
    amount: string | number;
    payment_date: string;
    reference?: string;
    notes?: string;
    receipt_image: File;
  }) {
    const formData = new FormData();
    Object.entries(payload).forEach(([key, value]) => {
      if (value !== undefined && value !== null) {
        formData.append(key, value instanceof File ? value : String(value));
      }
    });

    const response = await api.post('/parent/payments/transfers', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
    return response.data;
  },

  async parentReceipt(id: string | number) {
    const response = await api.get(`/parent/receipts/${id}`);
    return response.data;
  },

  async parentWorkplan() {
    const response = await api.get('/parent/workplan');
    return response.data;
  },
};
