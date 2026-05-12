# Consolidacion del rol Club Director

## Estado actual

### 2026-05-12

- Se inicio la fase de consolidacion del rol `club_director`.
- Se inventariaron las paginas principales del rol en `resources/js/Pages/ClubDirector`.
- Se revisaron las rutas `club-director/*`; actualmente hay 58 rutas relacionadas con el rol.
- Se verifico que los `route(...)` usados por las paginas de Club Director existen en Ziggy/Laravel.
- Se encontro y corrigio un detalle de navegacion: el dashboard del menu usaba `dashboard` para marcar activo, pero la ruta real es `club.dashboard`.
- La ruta `/club-director/event-settlements` no aparece como item independiente del menu porque se consume dentro de `Treasury.vue` en la seccion `Transferencias de eventos`.

## Mapa de modulos

### Dashboard

- Ruta: `/club-director/dashboard`
- Nombre de ruta: `club.dashboard`
- Vista: `ClubDirectorDashboard`
- Proposito: resumen inicial del club activo y su jerarquia.
- Conexiones:
  - Usa `ClubHelper::activeClubForUser`.
  - Depende de iglesia, distrito, asociacion y union para mostrar contexto jerarquico.

### Mi club - Administracion

- Ruta: `/club-director/my-club`
- Vista: `ClubDirector/MyClub.vue`
- Proposito: administracion base del club, iglesia, director y objetivos.
- Conexiones:
  - Superadmin puede entrar con contexto activo de club.
  - Se conecta con iglesias, distritos y jerarquia superior.
  - Gestiona objetivos del club mediante rutas `clubs.objectives.*`.

### Cuentas y conceptos

- Ruta: `/club-director/my-club-finances`
- Vista: `ClubDirector/MyClubFinances.vue`
- Proposito: configurar cuentas `pay_to`, conceptos de pago y datos bancarios del club.
- Conexiones:
  - Alimenta pagos de padres, pagos de club, tesoreria y reportes financieros.
  - Datos bancarios del club se muestran en `/parent/payments`.
  - Conceptos de evento se integran con `payment_allocations`.

### Ingresos

- Ruta: `/club-director/payments`
- Vista: `ClubDirector/Payments.vue`
- Controlador: `ClubPaymentController`
- Proposito: registrar pagos, aprobar/rechazar comprobantes enviados por padres y generar recibos.
- Conexiones:
  - Padres envian comprobantes desde `/parent/payments`.
  - Recibos se descargan con `payment-receipts.download`.
  - Pagos de eventos alimentan participantes inscritos, tesoreria y transferencias hacia arriba.
  - Registros se reflejan en reportes financieros y reportes por cuenta.

### Tesoreria

- Ruta: `/club-director/treasury`
- Vista: `ClubDirector/Treasury.vue`
- Controlador: `ClubTreasuryController`
- Proposito: saldos por ubicacion, movimientos internos, depositos/retiros y transferencias de eventos.
- Conexiones:
  - Lee `event-settlements` para depositos de eventos hacia organizadores superiores.
  - Depende de `bank_infos` para habilitar transferencias electronicas.
  - Se alimenta de pagos, gastos y movimientos de tesoreria.

### Gastos

- Ruta: `/club-director/expenses`
- Vista: `ClubDirector/Expenses.vue`
- Controlador: `ExpenseController`
- Proposito: registrar gastos, comprobantes, reembolsos y recibos de reembolso.
- Conexiones:
  - Afecta cuentas locales `pay_to`.
  - Reembolsos generan movimientos relacionados en tesoreria/reportes.
  - Correcciones contables pueden revertir gastos y reembolsos.

### Correcciones contables

- Ruta: `/club-director/accounting-corrections`
- Vista: `ClubDirector/AccountingCorrections.vue`
- Controlador: `AccountingCorrectionController`
- Proposito: revertir ingresos, gastos y reembolsos sin eliminar registros.
- Conexiones:
  - Usa campos `is_cancelled`, `related_canceled_movement_id` y `canceling_id`.
  - Los reportes financieros deben mostrar las relaciones de cancelacion.
  - Tambien esta disponible para `superadmin`.

### Miembros

- Ruta: `/club-director/members`
- Vista: `ClubDirector/Members.vue`
- Controlador principal: `MemberAdventurerController`
- Proposito: gestion de miembros adventurers/pathfinders, clases, seguro, SDA/no SDA, padres y archivos.
- Conexiones:
  - Padre registrado o creado desde superadmin se vincula por `members.parent_id`.
  - Miembros no SDA aparecen en cuidado pastoral de distrito.
  - Miembros alimentan pagos, eventos, reportes, investidura y asistencia.
  - Clases y asignaciones alimentan carpetas/investidura y planificacion.

### Personal y cuentas

- Ruta: `/club-director/staff`
- Vista: `ClubDirector/Staff.vue`
- Controladores: `StaffAdventurerController`, `StaffApprovalController`, `UserApprovalController`
- Proposito: gestionar personal, usuarios del club, subroles, aprobaciones y asignaciones.
- Conexiones:
  - Personal puede entrar al rol `club_personal`.
  - Asignaciones se usan en plan de trabajo, asistencia, investidura y clases.
  - Padres con hijos en el club se listan para vinculacion/seguimiento.

### Plan de trabajo

- Ruta: `/club-director/workplan`
- Vista: `ClubDirector/Workplan.vue`
- Controlador: `WorkplanController`
- Proposito: crear/editar plan de trabajo, eventos de calendario, clases, PDF/ICS y exportacion.
- Conexiones:
  - Personal del club consume la misma base de plan en modo restringido.
  - Padre ve el calendario filtrado por sus hijos.
  - Eventos superiores de distrito/asociacion pueden heredarse.

### Eventos

- Ruta: `/events`
- Vistas: `Events/*`
- Controladores: `EventController` y controladores relacionados.
- Proposito: planner de eventos, tareas, participantes, presupuesto, documentos, vehiculos, choferes y readiness.
- Conexiones:
  - Club puede ser owner o participante de eventos creados por niveles superiores.
  - Participantes y pagos se conectan con finanzas del club y transferencias hacia asociacion/union.
  - Padres pueden pagar conceptos de evento desde portal de padres.

### Configuracion

- Ruta: `/club-director/settings`
- Vista: `ClubDirector/Settings.vue`
- Controlador: `ClubSettingsController`
- Proposito: logo, configuracion del club, catalogo e integracion con datos bancarios.
- Conexiones:
  - Logo se usa en PDFs y recibos.
  - Configuracion de catalogo afecta carpetas/honores.
  - Datos bancarios publicados se muestran a padres.

### Reportes

- Asistencia: `/club-director/reports/assistance`
- Finanzas: `/club-director/reports/finances`
- Saldos por cuenta: `/club-director/reports/accounts`
- Investidura/requisitos: `/club-director/reports/investiture-requirements`

Conexiones:

- Asistencia depende de personal, clases, miembros y eventos/reuniones.
- Finanzas depende de pagos, gastos, cuentas, tesoreria y correcciones.
- Saldos por cuenta muestra ubicacion de dinero: efectivo/banco/cuenta.
- Investidura depende de miembros, clases, requisitos, evidencias, codigos publicos y solicitudes hacia niveles superiores.

## Conexiones clave con otros roles

- Padres:
  - Ven pagos, recibos, calendario, hijos y carpeta de investidura.
  - Envia comprobantes que el club aprueba o rechaza.
  - Necesitan datos bancarios del club para depositar.
- Personal de club:
  - Usa plan de trabajo, clases, asistencia e investidura.
  - Depende de asignaciones hechas por el director.
- Distrito:
  - Ve clubes, miembros y cuidado pastoral de miembros no SDA.
  - Puede activar procesos o validaciones superiores.
- Asociacion/Union:
  - Pueden crear eventos superiores que impactan al club.
  - Reciben comprobantes/transferencias de eventos segun jerarquia.
- Superadmin:
  - Puede operar en contexto de club.
  - Puede abrir portal de padre desde miembros.
  - Puede auditar usuarios activos y logs.

## Hallazgos iniciales

- Corregido: `ClubDirectorNav.vue` usaba `route: 'dashboard'` para el dashboard. Debe usar `club.dashboard`.
- Verificado: las rutas referenciadas por `route(...)` dentro de paginas ClubDirector y el nav existen.
- Observacion: `/club-director/event-settlements` es una ruta de datos/vista operativa consumida por Tesoreria, no una pagina de menu independiente.

## Pase de consolidacion 1 - Miembro, padre, pago y reporte

### 2026-05-12

Flujo revisado:

1. `club-director/members` lista miembros activos del club y expone datos del padre/madre cuando existen en el registro.
2. Superadmin puede abrir el portal del padre desde el miembro:
   - Si ya existe `members.parent_id`, abre el portal como ese padre.
   - Si no existe usuario padre pero el miembro tiene datos de contacto, abre el portal en modo preparacion y muestra `Crear cuenta de padre`.
   - Al crear cuenta, se crea/reutiliza usuario `parent`, se marca `must_change_password`, se vincula `members.parent_id` y se mantiene el portal con sidebar de padre.
3. `parent/payments` muestra:
   - Cuentas bancarias del club por cada club de los hijos vinculados.
   - Cargos esperados por club, clase, miembro o evento.
   - Comprobantes enviados y recibos emitidos.
4. El padre envia comprobante de transferencia en `ParentPaymentController::storeTransfer`.
5. El club aprueba/rechaza en `club-director/payments` usando `ClubPaymentController::approveParentTransfer` o `rejectParentTransfer`.
6. Al aprobar, se crea un `payments` normal, se incrementa la cuenta local, se enlaza `approved_payment_id` y se genera recibo con `PaymentReceiptService`.
7. El pago aprobado alimenta:
   - Recibos del padre.
   - Reporte financiero `/club-director/reports/finances`.
   - Reportes de eventos por concepto/componente cuando el pago pertenece a evento.

Correcciones aplicadas:

- `parent/payments` ahora calcula y muestra `available_amount`, descontando comprobantes pendientes de revision.
- El backend bloquea un nuevo comprobante si lo pendiente en revision ya cubre el saldo del cargo.
- La aprobacion de comprobantes de padre ahora calcula pagos previos usando pagos directos y tambien `payment_allocations`, para no sobreaprobar cargos de evento ya cubiertos dentro de un pago combinado.
- Al crear o reutilizar una cuenta de padre desde superadmin, se re-sincronizan los recibos de pagos previos del miembro para que aparezcan en el portal del padre despues de vincularlo.

## Pase de consolidacion 2 - Tesoreria y transferencias internas

### 2026-05-12

- Se agrego soporte para transferencias locales entre cuentas internas del club desde `/club-director/treasury`.
- La transferencia registra:
  - cuenta origen (`from_pay_to`)
  - cuenta destino (`to_pay_to`)
  - ubicacion de origen (`from_location`): efectivo o banco/electronico
  - ubicacion de destino (`to_location`): efectivo o banco/electronico
  - monto, fecha, referencia, notas y comprobante opcional
- La transferencia no crea ingreso ni gasto; solo mueve saldo entre cuentas y ubicaciones.
- Puede mover fondos dentro de la misma ubicacion, por ejemplo `club_budget` banco -> `church_budget` banco.
- Puede cambiar la ubicacion del dinero, por ejemplo `club_budget` efectivo -> `church_budget` banco/electronico cuando el efectivo se entrega/deposita al tesorero de iglesia y queda bajo control externo de esa cuenta.
- El sistema bloquea transferencias si:
  - origen y destino son la misma cuenta
  - alguna cuenta no pertenece al club
  - el monto excede el saldo disponible en la ubicacion origen
  - se intenta mover fondos bancarios desde el club sin cuenta bancaria del club registrada
- El reporte financiero por cuenta ahora incluye transferencias locales en origen y destino para que el movimiento sea auditable.

Riesgo residual:

- La creacion de cuenta de padre desde el portal depende de que el miembro tenga correo del padre/madre. Si solo tiene nombre, el sistema abre el portal pero exige completar el correo antes de crear usuario.
- El envio real de email al padre con la clave temporal sigue fuera del alcance actual; por ahora se muestra la clave temporal al superadmin al crear la cuenta.

## Proximos pasos de consolidacion

1. Recorrer modulo por modulo desde UI con datos reales y anotar errores de consola/red.
2. Validar permisos por rol:
   - club_director
   - superadmin con contexto de club
   - parent
   - club_personal
3. Validar flujos de punta a punta:
   - crear miembro -> vincular padre -> padre paga -> club aprueba -> recibo -> reporte financiero
   - crear evento superior -> club registra participantes/pagos -> tesoreria transfiere -> owner ve comprobante
   - gasto -> reembolso -> correccion contable -> reporte financiero
   - clase/requisito -> evidencia -> solicitud de investidura -> PDF/codigo publico
4. Revisar reportes con movimientos cancelados/revertidos.
5. Revisar mobile y traducciones restantes solo despues de estabilizar wiring funcional.
