# Mobile Member and Parent App Plan

## Architecture

- Keep Laravel as the single backend at `https://adminmyclub.com`.
- Keep the existing Inertia app for staff, director, and parent web administration.
- Build the packaged mobile app in `mobile/` with Ionic Vue and Capacitor.
- Mobile views are bundled into the native app. The app reads and writes through `/api/mobile/*`.

## Scope

- Member mobile access is limited to Pathfinders and Master Guides.
- Adventurer interaction stays parent-led.
- Member submissions must be tied to a staff/director-created workplan task.
- GPS/location tracking is a safety module tied only to offsite active workplan events or class plans.

## New Task Data Model

- `workplan_tasks`: staff-created task tied to a workplan event, optionally a class plan, class, form schema, carpeta requirement, or investiture requirement.
- `workplan_task_assignments`: task-to-member assignments and current completion state.
- `workplan_task_submissions`: member/parent submitted responses for assigned tasks.
- `workplan_task_submission_files`: uploaded files/photos linked to a submission.

## Location Safety Data Model

- `workplan_events` gains offsite and location-tracking flags.
- `location_sharing_consents`: parent consent for a member and event/class plan scope.
- `location_tracking_sessions`: active/scheduled location-sharing session for an offsite workplan event.
- `location_tracking_participants`: members included in a tracking session.
- `location_pings`: device location samples received from the native app.
- `location_access_logs`: audit trail for staff/parent location views.

## Intended Mobile API

- `POST /api/mobile/login`
- `POST /api/mobile/logout`
- `GET /api/mobile/me`
- `GET /api/mobile/tasks`
- `POST /api/mobile/tasks/{assignment}/submit`
- `GET /api/mobile/workplan`
- `GET /api/mobile/announcements`
- `GET /api/mobile/location/session`
- `POST /api/mobile/location/consents`
- `POST /api/mobile/location/ping`

## Capacitor Notes

- Android project has been generated under `mobile/android`.
- iOS generation requires CocoaPods installed locally, then:

```bash
cd mobile
npx cap add ios
```

- Build/sync workflow:

```bash
cd mobile
npm install
npm run build
npx cap sync
```
