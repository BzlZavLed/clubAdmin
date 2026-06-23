import { Geolocation } from '@capacitor/geolocation';
import { Device } from '@capacitor/device';
import { api } from './api';

export async function requestForegroundLocationPermission() {
  return Geolocation.requestPermissions({
    permissions: ['location'],
  });
}

export async function sendLocationPing(sessionId: number, participantId: number, isBackground = false) {
  const position = await Geolocation.getCurrentPosition({
    enableHighAccuracy: true,
    timeout: 15000,
  });
  const device = await Device.getInfo();

  return api.post('/location/ping', {
    session_id: sessionId,
    participant_id: participantId,
    latitude: position.coords.latitude,
    longitude: position.coords.longitude,
    accuracy_meters: position.coords.accuracy,
    altitude_meters: position.coords.altitude,
    speed_mps: position.coords.speed,
    heading_degrees: position.coords.heading,
    is_background: isBackground,
    recorded_at: new Date(position.timestamp).toISOString(),
    device: {
      platform: device.platform,
      model: device.model,
      operating_system: device.operatingSystem,
      os_version: device.osVersion,
    },
  });
}
