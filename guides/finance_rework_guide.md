# Guia de reacomodo de finanzas

Fecha de referencia inicial: 2026-05-08
Ultima actualizacion documentada: 2026-05-14
Rama actual de trabajo: `feature/frontend-finance-reorg`
Commit base del primer reacomodo: `edbf498 first rework`

## Fechas y estado

| Area | Estado | Fecha |
| --- | --- | --- |
| Inicio documentado de la rama | Hecho | 2026-05-08 |
| Primera reorganizacion frontend-only | Hecho | 2026-05-08 |
| Documento de continuidad de la rama | Hecho | 2026-05-08 |
| Revision mobile completa de finanzas | Pendiente | Sin fecha |
| Diseno tecnico del motor financiero | En progreso | 2026-05-13 |
| Pruebas de cobertura del motor financiero | Hecho | 2026-05-13 |
| Primera vista nueva de Caja sobre el motor | Hecho inicial | 2026-05-13 |
| Vista nueva de Contabilidad sobre el motor | Hecho inicial | 2026-05-13 |
| Escritura centralizada desde el motor financiero | Hecho inicial | 2026-05-13 |
| Limpieza visual de Contabilidad nueva | Hecho inicial | 2026-05-13 |
| Correcciones integradas al libro contable | Hecho inicial | 2026-05-13 |
| Recibos de cancelacion y referencias en PDF de saldos | Hecho inicial | 2026-05-14 |
| Limpieza del sidebar financiero | Hecho inicial | 2026-05-14 |
| Bootstrap de Caja y Contabilidad desde el motor financiero | Hecho inicial | 2026-05-14 |
| Escrituras principales extraidas a servicios del motor | Hecho inicial | 2026-05-14 |
| Redireccion de vistas historicas financieras al motor | Hecho inicial | 2026-05-14 |
| Refactor backend del motor financiero | Pendiente | Sin fecha |
| Cierre total de la rama | Pendiente | Sin fecha |

La rama se considera parcialmente lista desde 2026-05-08 para validar la nueva organizacion visual de finanzas.

La rama se considerara completamente lista cuando esten hechos estos puntos:

1. La navegacion financiera este validada en desktop y mobile.
2. Caja, Contabilidad y Reportes financieros esten visualmente claros para el usuario.
3. El motor backend tenga una decision tomada: dejarlo como esta o iniciar refactor con migraciones.
4. Si se hace refactor backend, ingresos, gastos, transferencias, recibos, comprobantes y cancelaciones deben quedar trazables en reportes.
5. `npm run build` y las pruebas relevantes pasen antes de merge.

## Definicion de la rama

Esta rama representa el reacomodo progresivo del modulo financiero del club.

El alcance inmediato de la rama es ordenar la experiencia del usuario sin romper el motor financiero que ya esta funcionando. El resultado actual debe entenderse como una primera capa de organizacion: misma data, mismas rutas principales, pero con una estructura mental mas clara para el usuario.

La direccion final de esta rama es servir como base para un futuro refactor mas profundo del motor financiero, donde ingresos, gastos, transferencias, recibos, comprobantes y correcciones queden trazados de forma mas uniforme.

En resumen:

- Donde estamos: nuevas vistas de `Caja` y `Contabilidad` ya leen y escriben mediante el motor financiero.
- Hacia donde vamos: motor financiero mas ordenado, auditable y basado en movimientos trazables.
- Regla de seguridad: las rutas historicas siguen activas hasta validar completamente las vistas nuevas.
- Uso de este archivo: leerlo antes de continuar cualquier trabajo de esta rama.

## Proposito

El objetivo es que el modulo financiero del club sea mas facil de entender y usar sin perder las herramientas que ya funcionan.

La idea general actual es reacomodar finanzas en tres areas:

1. `Caja`: captura operativa de ingresos y gastos.
2. `Contabilidad`: donde esta el dinero, por cuenta, efectivo/banco, transferencias locales, transferencias hacia arriba, correcciones y libro contable.
3. `Reportes financieros`: consultas generales por cuenta, concepto y rango de fechas, con trazabilidad de recibos, comprobantes y correcciones.

Este documento existe para poder retomar el trabajo varias veces sin perder contexto.

## Estado actual

### 2026-05-08

Se completo una primera fase frontend-only.

Esta fase no cambio el motor contable ni las reglas de base de datos. Solo reorganizo la experiencia visual y la navegacion para que el usuario entienda mejor donde esta cada herramienta.

Cambios hechos:

- Se creo `resources/js/Components/FinanceWorkflowNav.vue`.
- Se agrego una navegacion interna de finanzas en las vistas financieras principales.
- Se reorganizo el sidebar de director de club.
- Se reorganizo el sidebar de superadmin cuando entra a herramientas de club.
- Se agregaron traducciones nuevas en `resources/js/i18n/general.js`.

Vistas que mostraron el flujo financiero compartido durante la primera fase:

- `resources/js/Pages/ClubDirector/Payments.vue`
- `resources/js/Pages/ClubDirector/Expenses.vue`
- `resources/js/Pages/ClubDirector/Treasury.vue`
- `resources/js/Pages/ClubDirector/MyClubFinances.vue`
- `resources/js/Pages/ClubDirector/Reports/Finances.vue`
- `resources/js/Pages/ClubDirector/Reports/Accounts.vue`
- `resources/js/Pages/ClubDirector/AccountingCorrections.vue`

Nota 2026-05-14: varias de estas pantallas historicas ya fueron eliminadas despues de que `Caja` y `Contabilidad` quedaron como vistas principales.

Validacion hecha en la primera fase:

- `npm run build`
- `git diff --check`

### Pendiente, sin fecha

Trabajo aun no realizado:

- Validar mobile de todas las paginas financieras.
- Validar con uso real si `Caja` y `Contabilidad` ya sustituyen la mayor parte de las pantallas historicas.
- Terminar de decidir que rutas historicas se pueden borrar despues de comparar comportamiento con las vistas nuevas.
- Disenar migraciones solo si se decide crear un ledger fisico.
- Normalizar reportes para que todas las cancelaciones, recibos y comprobantes sean trazables desde una misma lectura contable.

### 2026-05-13

Se inicio la segunda fase backend sin reemplazar las pantallas actuales.

La decision tecnica fue crear una capa de motor financiero agnostica antes de cambiar el flujo visible. Esta capa no cambia todavia como se guardan ingresos, gastos, transferencias, recibos ni correcciones. Su objetivo es leer el motor actual y exponerlo con una forma comun para que luego las vistas puedan migrarse de forma incremental.

Cambios hechos:

- Se creo `app/Services/Finance/FinanceEngine.php`.
- Se creo `app/Services/Finance/FinanceActionCatalog.php`.
- Se creo `app/Services/Finance/FinanceMovementReader.php`.
- Se creo `app/Http/Controllers/FinanceEngineController.php`.
- Se creo `tests/Feature/FinanceEngineWorkflowTest.php`.
- Se agregaron endpoints internos del motor:
  - `GET /club-director/finance-engine/actionables`
  - `GET /club-director/finance-engine/movements`

La lectura normalizada une, por ahora:

- `payments` como movimientos de ingreso.
- `expenses` como movimientos de gasto.
- `treasury_movements` como movimientos de transferencia.

Cada movimiento normalizado intenta exponer:

- Origen del registro (`model`, `id`, `movement_id`).
- Dominio (`income`, `expense`, `transfer`).
- Cuenta (`pay_to`) y ubicacion del dinero.
- Fecha, monto positivo y monto firmado.
- Recibo o comprobante cuando existe.
- Estado de cancelacion/reversa.
- Estado de custodia cuando el dinero fue cobrado por staff.

Regla importante de esta fase:

- Las pantallas existentes siguen usando sus controladores actuales.
- Las vistas nuevas escriben mediante endpoints del motor financiero.
- La escritura del motor delega en los flujos existentes para mantener reglas, recibos, comprobantes, saldos y validaciones estables.

Cobertura de pruebas agregada:

- Catalogo de acciones del motor, incluyendo Caja, Transferencias, Contabilidad y Reportes.
- Ingresos con conceptos creados previamente y con conceptos manuales.
- Conceptos con alcances personalizados.
- Gastos con comprobante.
- Reportes financieros actuales y lectura normalizada del motor.
- Transferencias locales entre cuentas usando efectivo/banco en ambas direcciones.
- Transferencias de eventos hacia arriba con recibo de deposito.
- Cancelaciones con movimiento opuesto y enlaces circulares.
- Dinero cobrado por staff, entrega pendiente y validacion por director.

Validacion hecha:

- `php artisan test --filter=FinanceEngineWorkflowTest`

### 2026-05-13, Caja nueva

Se creo una primera vista nueva de Caja sin modificar las vistas actuales de ingresos, gastos, tesoreria ni reportes.

Ruta nueva:

- `/club-director/finance/cashbox`

Archivos agregados o conectados:

- `resources/js/Pages/ClubDirector/Finance/Cashbox.vue`
- `resources/js/Services/api.js`
- `routes/web.php`
- `app/Services/Finance/FinanceActionCatalog.php`

Alcance actual de la vista:

- Registrar ingreso con concepto guardado.
- Registrar ingreso manual.
- Crear conceptos reutilizables desde el selector de conceptos.
- Guardar alcance del concepto para diferenciar conceptos globales, del club o personalizados.
- Crear conceptos reutilizables sin exigir fecha limite.
- Registrar pagadores con nombres personalizados cuando no existen como miembros o contactos del sistema.
- Registrar ingresos de eventos con desglose obligatorio/opcional basico.
- Registrar gasto con cuenta, origen efectivo/banco y comprobante.
- Mostrar resumen de efectivo, banco y total disponible desde el motor financiero.
- Mostrar movimientos normalizados del motor financiero filtrables por ingreso, gasto o transferencia.
- Mostrar balances por cuenta desde la lectura normalizada del motor.

Regla de seguridad:

- La vista nueva escribe por endpoints del motor financiero.
- La lectura posterior viene de `/club-director/finance-engine/movements`.
- Las vistas antiguas siguen intactas y siguen disponibles para comparar comportamiento.
- La creacion de conceptos desde Caja debe mantenerse simple: nombre, monto, tipo, alcance y configuracion reutilizable; fecha limite solo aplica si el concepto la necesita.

Validacion hecha:

- `npm run build`
- `php artisan test tests/Feature/FinanceEngineWorkflowTest.php tests/Feature/AccountingCorrectionTest.php`

### 2026-05-13, Contabilidad nueva

Se creo una primera vista nueva de Contabilidad sin retirar Tesoreria, Correcciones ni Reportes actuales.

Ruta nueva:

- `/club-director/finance/accounting`

Archivos agregados o conectados:

- `resources/js/Pages/ClubDirector/Finance/Accounting.vue`
- `resources/js/Components/Nav/ClubDirectorNav.vue`
- `resources/js/Components/Nav/SuperAdminNav.vue`
- `resources/js/i18n/general.js`
- `routes/web.php`
- `app/Services/Finance/FinanceActionCatalog.php`
- `tests/Feature/FinanceEngineWorkflowTest.php`

Alcance actual de la vista:

- Registrar transferencias locales entre cuentas.
- Registrar transferencias de eventos hacia la organizacion dueña.
- Registrar movimientos de efectivo a banco y banco a efectivo.
- Ver saldos por cuenta y ubicacion del dinero.
- Validar entregas de dinero hechas por staff.
- Revertir ingresos, gastos y reembolsos desde un solo espacio.
- Revisar el libro contable normalizado con filtros por dominio, cuenta y fechas.
- Acceder al PDF de saldos desde la seccion de saldos.

Decision de diseño:

- `Caja` queda para captura diaria de ingresos y gastos.
- `Contabilidad` concentra transferencias, correcciones, saldos y libro contable.
- Las pantallas existentes siguen disponibles hasta que la nueva vista este validada por uso real.

### 2026-05-13, Motor financiero con escritura

Se agrego una primera capa de escritura al motor financiero.

Archivos principales:

- `app/Services/Finance/FinanceWriter.php`
- `app/Services/Finance/FinanceEngine.php`
- `app/Http/Controllers/FinanceEngineController.php`
- `routes/web.php`
- `resources/js/Services/api.js`
- `resources/js/Pages/ClubDirector/Finance/Cashbox.vue`
- `resources/js/Pages/ClubDirector/Finance/Accounting.vue`
- `tests/Feature/FinanceEngineWorkflowTest.php`

Endpoints nuevos:

- `POST /club-director/finance-engine/concepts`
- `POST /club-director/finance-engine/income`
- `POST /club-director/finance-engine/expenses`
- `POST /club-director/finance-engine/transfers`
- `POST /club-director/finance-engine/staff-remittances/validate`
- `POST /club-director/finance-engine/event-settlements/{event}`
- `POST /club-director/finance-engine/corrections/payments/{payment}/reverse`
- `POST /club-director/finance-engine/corrections/expenses/{expense}/reverse`
- `POST /club-director/finance-engine/corrections/reimbursements/{expense}/reverse`

Decision tecnica:

- La escritura del motor es una capa de compatibilidad inicial.
- Los endpoints del motor delegan en los flujos existentes que ya validan y guardan correctamente.
- Las vistas nuevas (`Caja` y `Contabilidad`) ya escriben contra el motor financiero.
- Las rutas historicas siguen activas para no romper pantallas existentes.
- La siguiente fase podria extraer la logica de los controladores antiguos a servicios puros, pero no es obligatorio para validar la experiencia nueva.

Nota 2026-05-14: esa siguiente fase ya empezo. Las rutas principales del motor ya no llaman directamente a los controladores historicos; ahora pasan por servicios propios dentro de `app/Services/Finance`.

Validacion hecha:

- `npm run build`
- `php artisan test tests/Feature/FinanceEngineWorkflowTest.php tests/Feature/AccountingCorrectionTest.php`
- `git diff --check`

### 2026-05-13, Limpieza visual de Contabilidad

Se limpio la vista nueva de Contabilidad para que no se sienta como un puente hacia las pantallas historicas.

Cambios hechos:

- Se removio `FinanceWorkflowNav` de `resources/js/Pages/ClubDirector/Finance/Accounting.vue`.
- Se agrego una navegacion interna propia del modulo:
  - `Transferencias`
  - `Saldos`
  - `Libro contable`
  - `Eventos`
  - `Staff`
- Se ajusto el orden visual final de la pagina:
  - encabezado con enlaces internos
  - balances generales
  - transferencias y saldos por cuenta
  - libro contable normalizado
  - transferencias de eventos, entregas de staff y auditoria rapida de transferencias internas
- Se rediseño el encabezado para mostrar solo el modulo, el club activo, selector de club cuando aplique y recarga.
- Se ajustaron tarjetas de saldo para efectivo, banco y balance total.
- Se removio el enlace visual a `Reporte completo` desde la tarjeta de saldos.
- Se dejo el enlace de `PDF de saldos` dentro de la seccion de saldos.
- Se movieron las correcciones contables al `Libro contable normalizado`:
  - cada fila reversible muestra `Corregir`
  - el boton abre un modal con resumen del movimiento, fecha y motivo
  - las filas corregidas muestran `Corregido` y enlazan a la fila de correccion
  - las filas de correccion muestran `Correccion` y enlazan al movimiento original
- Los saldos de `Contabilidad` y `Tesoreria` ignoran movimientos originales cancelados y sus movimientos de correccion para que las tarjetas de balance se actualicen despues de corregir un registro.

Decision visual:

- La vista de `Contabilidad` debe mostrar solo lo que el modulo resuelve.
- No debe iniciar con enlaces generales a Caja, Gastos, Reportes o paginas historicas.
- Las correcciones deben ser contextuales al movimiento del libro, no una seccion separada que obligue a buscar el registro dos veces.
- Las rutas historicas siguen existiendo, pero no deben dominar la experiencia de la vista nueva.

Validacion hecha:

- `npm run build`
- `php artisan test tests/Feature/FinanceEngineWorkflowTest.php tests/Feature/AccountingCorrectionTest.php`
- `git diff --check`

### 2026-05-14, Recibos y cancelaciones en reportes

Se ajusto la trazabilidad de recibos para el reporte de saldos por cuenta y para las correcciones de ingresos.

Cambios hechos:

- El PDF `account-balances.pdf` ahora recibe referencias de recibos generados (`RCPT-...`) ademas de comprobantes adjuntos (`PAY-...`, `EXP-...`, `REIMB-...`).
- Los ingresos del reporte de saldos ahora incluyen el recibo generado por el sistema cuando existe.
- Los ingresos de cancelacion creados por correcciones contables generan su propio recibo imprimible.
- El recibo imprimible de una cancelacion se titula `Recibo de cancelación` y muestra el movimiento original que esta cancelando.
- Las referencias de recibos quedan visibles en la version JSON y en el PDF de saldos.

Decision tecnica:

- Las cancelaciones de ingresos siguen siendo movimientos `payments` negativos con `payment_type = internal`.
- El recibo de cancelacion reutiliza `payment_receipts` para mantener numeracion, validacion QR y descarga por la ruta normal de recibos.
- Las cancelaciones de gastos siguen mostrando referencias de comprobantes y enlaces de correccion en reportes; no se agrego una tabla nueva de recibos de gasto.

Validacion hecha:

- `php artisan test tests/Feature/FinanceEngineWorkflowTest.php tests/Feature/AccountingCorrectionTest.php`
- `git diff --check`

### 2026-05-14, Limpieza del sidebar financiero

Se limpio la navegacion visible del modulo financiero para que el usuario no tenga varias entradas antiguas compitiendo con el nuevo flujo.

Cambios hechos:

- En el sidebar de director de club, la seccion financiera ahora muestra solo:
  - `Caja`: `/club-director/finance/cashbox`
  - `Contabilidad`: `/club-director/finance/accounting`
- En el sidebar de superadmin, cuando entra a herramientas de club, se aplica la misma limpieza.
- El componente `FinanceWorkflowNav` tambien quedo reducido a `Caja` y `Contabilidad`.
- Se removieron de la navegacion visible las entradas historicas de ingresos, gastos, tesoreria, cuentas/conceptos, correcciones y reportes financieros.

Decision tecnica:

- No se eliminaron rutas, controladores ni vistas historicas en esta fase.
- Las rutas antiguas siguen disponibles por compatibilidad hasta terminar la comparacion funcional entre pantallas historicas y vistas nuevas.
- La limpieza es visual/de navegacion; el borrado real de rutas o archivos debe hacerse solo cuando `Caja` y `Contabilidad` ya no dependan de esos flujos internamente.

Validacion hecha:

- `npm run build`
- `git diff --check`

### 2026-05-14, Bootstrap de vistas desde el motor financiero

Se movio la lectura inicial de las vistas nuevas hacia endpoints propios del motor financiero.

Cambios hechos:

- Se creo `app/Services/Finance/FinanceBootstrapper.php`.
- Se agregaron endpoints:
  - `GET /club-director/finance-engine/cashbox`
  - `GET /club-director/finance-engine/accounting`
- `Caja` ahora carga miembros, staff, clases, conceptos, cuentas, gastos y movimientos normalizados desde `club.finance-engine.cashbox`.
- `Contabilidad` ahora carga tesoreria, saldos, transferencias recientes, entregas de staff, transferencias de eventos y libro contable desde `club.finance-engine.accounting`.
- `FinanceActionCatalog` dejo de anunciar rutas historicas de pagos, gastos, tesoreria, cuentas/conceptos y reportes financieros.

Decision tecnica:

- Esta fase elimina dependencias frontend de las rutas historicas para las vistas nuevas.
- No borra todavia los controladores o rutas historicas porque:
  - algunas rutas siguen existiendo como compatibilidad directa;
  - todavia hay pantallas historicas que pueden llamarlas directamente;
- los PDFs historicos todavia conservan sus nombres de ruta, aunque ya pueden apuntar al motor.
- Siguiente paso recomendado: terminar de revisar que pantallas o pruebas siguen usando rutas historicas antes de borrar controladores o routes.

Validacion esperada:

- `npm run build`
- `php artisan test tests/Feature/FinanceEngineWorkflowTest.php tests/Feature/AccountingCorrectionTest.php`
- `git diff --check`

### 2026-05-14, Escrituras principales en servicios del motor

Se extrajeron las escrituras usadas por las rutas nuevas del motor financiero hacia servicios propios.

Servicios agregados:

- `app/Services/Finance/FinanceConceptWriter.php`
- `app/Services/Finance/FinanceIncomeWriter.php`
- `app/Services/Finance/FinanceExpenseWriter.php`
- `app/Services/Finance/FinanceTransferWriter.php`
- `app/Services/Finance/FinanceEventSettlementWriter.php`
- `app/Services/Finance/FinanceCorrectionWriter.php`

`FinanceWriter` ahora coordina esos servicios y ya no llama directamente a estos controladores historicos para las rutas del motor:

- `ClubController` para crear conceptos.
- `ClubPaymentController` para guardar ingresos.
- `ExpenseController` para guardar gastos.
- `ClubTreasuryController` para transferencias y validacion de entregas de staff.
- `EventClubSettlementController` para transferencias de eventos hacia arriba.
- `AccountingCorrectionController` para reversas/correcciones. Este controlador fue eliminado en la limpieza posterior; el flujo quedo en `FinanceCorrectionWriter`.

Validacion hecha:

- `php -l` sobre los servicios financieros nuevos.
- `php artisan test tests/Feature/FinanceEngineWorkflowTest.php tests/Feature/AccountingCorrectionTest.php`

Pendiente despues de este paso:

- Las rutas y controladores historicos siguen activos porque otras pantallas no migradas pueden seguir usandolos.
- Antes de borrar rutas antiguas, revisar `resources/js/Services/api.js`, vistas historicas y pruebas que todavia referencian rutas como `club.director.treasury.*`, `club.director.expenses.*`, `club.payments.*` y `club.director.accounting-corrections.*`.
- Los PDFs historicos se migran en el siguiente paso para mantener compatibilidad de enlaces sin depender visualmente de las pantallas antiguas.

### 2026-05-14, Redireccion de vistas historicas y PDFs

Se redirigieron las paginas historicas financieras del club hacia las vistas nuevas del motor.

Rutas GET que ahora redirigen a `Contabilidad`:

- `/club-director/my-club-finances`
- `/club-director/treasury`
- `/club-director/reports/finances`
- `/club-director/reports/accounts`
- `/club-director/accounting-corrections`

Rutas GET que ahora redirigen a `Caja`:

- `/club-director/payments`
- `/club-director/expenses`

Tambien se movieron los PDFs financieros hacia el controlador del motor:

- `financial.report.pdf` ahora genera el PDF del libro contable normalizado.
- `financial.accounts.pdf` ahora genera el PDF de saldos/contabilidad desde el motor.
- Se agregaron rutas explicitas nuevas:
  - `club.finance-engine.movements.pdf`
  - `club.finance-engine.accounting.pdf`

Decision tecnica:

- Se conservaron los nombres de rutas historicas para que enlaces antiguos no fallen.
- Las rutas POST historicas de transferencias, gastos y correcciones principales apuntan al motor donde ya existe un escritor nuevo.
- Algunas rutas historicas quedan vivas por compatibilidad con operaciones no migradas completamente, como aprobaciones de comprobantes de padres, carga de comprobantes de gasto y reembolsos.

Validacion hecha:

- `php artisan test tests/Feature/FinanceEngineWorkflowTest.php tests/Feature/AccountingCorrectionTest.php`

### 2026-05-14, Limpieza inicial de codigo historico

Se empezo a borrar codigo financiero que ya no esta enlazado desde rutas visibles ni desde las vistas nuevas del motor.

Codigo eliminado:

- `app/Http/Controllers/AccountingCorrectionController.php`.
- Pantallas historicas de club director ya reemplazadas por redirecciones:
  - `resources/js/Pages/ClubDirector/AccountingCorrections.vue`
  - `resources/js/Pages/ClubDirector/Expenses.vue`
  - `resources/js/Pages/ClubDirector/MyClubFinances.vue`
  - `resources/js/Pages/ClubDirector/Treasury.vue`
  - `resources/js/Pages/ClubDirector/Reports/Accounts.vue`
- Helpers JS que solo alimentaban esas pantallas historicas.
- Metodos PDF historicos de `ReportController` que ya fueron reemplazados por `FinanceEngineController`.
- Plantillas Blade PDF historicas:
  - `resources/views/reports/account_balances.blade.php`
  - `resources/views/reports/financial_ledger_print.blade.php`

Codigo conservado por compatibilidad:

- Rutas historicas GET, porque redirigen a `Caja` o `Contabilidad`.
- Rutas historicas POST cuyo nombre sigue siendo usado por pruebas o por integraciones compatibles, pero ya escriben por el motor cuando aplica.
- Endpoints JSON de tesoreria y transferencias de eventos, porque todavia hay pruebas y reportes que validan esos contratos.
- `ExpenseController` queda reducido a operaciones de comprobantes/reembolsos que aun no se han reemplazado con un flujo especifico del motor.

Pendiente despues de esta limpieza:

- Integrar la gestion tardia de comprobantes de gasto y liquidacion de reembolsos dentro de `Caja` o `Contabilidad`.
- Cuando eso quede cubierto, borrar las ultimas rutas de `ExpenseController`.

## Mapa actual de herramientas

### Caja

Vista unificada nueva:

- `/club-director/finance/cashbox`
- Componente: `resources/js/Pages/ClubDirector/Finance/Cashbox.vue`
- Uso: nueva vista unificada para registrar ingresos y gastos sin retirar las pantallas historicas.

Ruta historica de ingresos:

- `/club-director/payments`
- Componente: `resources/js/Pages/ClubDirector/Payments.vue`
- Estado actual: redirige a `/club-director/finance/cashbox`.
- Uso historico: registrar ingresos del club, pagos por concepto, pagos manuales, pagos de eventos y recibos.

Ruta historica de gastos:

- `/club-director/expenses`
- Componente historico: eliminado.
- Estado actual: redirige a `/club-director/finance/cashbox`.
- Uso historico: registrar egresos contra cuentas `pay_to`, subir comprobantes y manejar reembolsos cuando aplique.

Correcciones:

- `/club-director/accounting-corrections`
- Componente historico: eliminado.
- Estado actual: redirige a `/club-director/finance/accounting`.
- Uso historico: ruta historica para revertir movimientos erroneos creando movimientos opuestos, sin borrar historial.

### Contabilidad

Contabilidad:

- `/club-director/finance/accounting`
- Componente: `resources/js/Pages/ClubDirector/Finance/Accounting.vue`
- Uso: nueva vista de transferencias, saldos, validacion de entregas de staff y libro contable con correcciones contextuales desde cada movimiento.

Configuracion de cuentas y conceptos:

- `/club-director/my-club-finances`
- Componente historico: eliminado.
- Estado actual: redirige a `/club-director/finance/accounting`.
- Uso historico: administrar cuentas `pay_to` y conceptos de pago.

Tesoreria:

- `/club-director/treasury`
- Componente historico: eliminado.
- Estado actual: redirige a `/club-director/finance/accounting`.
- Uso historico: ver efectivo/banco, registrar depositos, retiros, movimientos locales y transferencias externas de eventos.

Nota importante:

- `/club-director/event-settlements` existe como endpoint JSON.
- No debe ponerse directo en el menu como si fuera una pagina.
- El flujo de transferencias de eventos ya se puede operar desde la nueva vista `Contabilidad`.
- `Tesoreria` conserva algunos endpoints JSON por compatibilidad, pero la pantalla historica ya fue eliminada.

### Reportes financieros

Reporte general:

- `/club-director/reports/finances`
- Componente: `resources/js/Pages/ClubDirector/Reports/Finances.vue`
- Estado actual: redirige a `/club-director/finance/accounting`.
- Uso historico: reporte por cuenta, concepto, ubicacion del dinero y rango de fechas.

Reporte de movimientos / saldos por cuenta:

- `/club-director/reports/accounts`
- Componente historico: eliminado.
- Estado actual: redirige a `/club-director/finance/accounting`.
- Uso historico: saldos, movimientos por cuenta, ingresos, gastos, recibos y comprobantes asociados.

## Decisiones tomadas

### La primera fase fue frontend-only

Se decidio empezar por frontend para evitar romper la rama desplegada o el motor contable actual.

Esto permite validar con usuarios si la nueva forma mental tiene sentido antes de cambiar reglas internas.

### Limpieza sin romper rutas historicas

Las rutas historicas principales siguen existiendo para compatibilidad, pero varias rutas GET ya redirigen a `Caja` o `Contabilidad`.

Las pantallas historicas que ya no eran alcanzables desde rutas Inertia fueron eliminadas para evitar mantener dos experiencias financieras paralelas.

### Correcciones siguen siendo parte del flujo

Aunque correcciones no es captura normal de caja, sigue dentro del flujo financiero porque forma parte de la trazabilidad y auditoria.

Decision actual:

- En la vista nueva, la correccion vive en el `Libro contable normalizado`.
- El usuario corrige desde la fila del movimiento, mediante modal.
- El movimiento original y su movimiento de correccion quedan enlazados visualmente.
- La ruta historica `/club-director/accounting-corrections` se conserva como enlace compatible, pero redirige a `Contabilidad`.

### Transferencias de eventos viven en el flujo contable

La transferencia hacia asociacion, union u organizador del evento depende de dinero recibido por el club y de que este disponible en banco.

Estado actual:

- La ruta historica `Tesoreria` conserva ese flujo.
- La nueva vista `Contabilidad` tambien lo muestra y lo escribe por el motor financiero.
- El usuario final deberia terminar usando `Contabilidad` como punto principal para transferencias y auditoria.

## Objetivo funcional final

El destino deseado es que finanzas trabaje como un flujo simple:

1. El club cobra ingresos en `Caja`.
2. El club registra gastos en `Caja`.
3. El sistema sabe si el dinero esta en efectivo o banco.
4. `Contabilidad` permite mover dinero entre efectivo y banco.
5. `Contabilidad` permite transferir hacia arriba cuando el dinero corresponde a eventos o conceptos que deben salir del club.
6. Los reportes muestran saldos, movimientos, conceptos, cuentas, recibos, comprobantes y correcciones.

## Lo que si puede hacerse solo en frontend

Se puede seguir mejorando sin tocar backend:

- Renombrar secciones y textos para que sean mas claros.
- Mejorar layout responsive.
- Agregar pestañas o filtros visuales.
- Reordenar tablas y paneles.
- Consolidar navegacion entre paginas financieras.
- Agregar avisos explicativos cuando una funcion depende de otra.
- Ocultar enlaces directos a endpoints que no son paginas.

## Lo que requiere backend

Para hacer un refactor real del motor financiero, no basta frontend.

Probablemente se necesitara backend para:

- Normalizar movimientos en una tabla tipo ledger.
- Garantizar que cada ingreso, gasto, transferencia, deposito, retiro, reembolso y correccion sea un movimiento trazable.
- Mantener relaciones circulares de cancelacion:
  - movimiento original: `is_cancelled = true`
  - movimiento original: `related_cancelled_movement_id`
  - movimiento de cancelacion: `canceling_id`
- Asociar cada ingreso con recibo generado.
- Asociar cada gasto con ticket o factura cargada.
- Definir con precision la ubicacion del dinero: `cash`, `bank`, `external`, `internal`.
- Limitar transferencias hacia arriba segun montos efectivamente cobrados para esos conceptos.
- Hacer reportes confiables desde el ledger y no desde calculos dispersos.

## Riesgos actuales

- Algunas paginas financieras todavia tienen su propia logica visual y no comparten un layout financiero completo.
- La navegacion ya esta mejor agrupada, pero el motor interno sigue siendo el mismo.
- El motor financiero ya tiene endpoints de escritura con servicios propios, pero varias pantallas historicas y reportes siguen vivos.
- Si se cambia backend sin migraciones cuidadosas, se puede romper informacion contable ya capturada.
- El endpoint `/club-director/event-settlements` no es una pagina; debe mantenerse fuera del menu directo.

## Propuesta de siguientes fases

### Fase 2: Pulir UX de vistas nuevas

- Revisar mobile de todas las paginas financieras.
- Homologar nombres visibles:
  - `Caja`
  - `Contabilidad`
  - `Reportes financieros`
- Validar que `Cashbox.vue` cubra captura diaria de ingresos y gastos sin depender visualmente de las paginas historicas.
- Validar que `Accounting.vue` cubra transferencias, saldos, eventos, staff, correcciones y libro contable sin enlaces innecesarios.
- Mantener `Treasury.vue`, `Reports/Finances.vue`, `Reports/Accounts.vue` y `AccountingCorrections.vue` disponibles hasta cerrar validacion funcional.

### Fase 3: Diseno tecnico del motor

Antes de escribir migraciones, documentar:

- Entidades actuales.
- Tablas actuales que generan dinero o afectan saldo.
- Campos necesarios para un ledger.
- Como migrar datos existentes.
- Como se validaran recibos y comprobantes.
- Como se van a manejar cancelaciones y reversos.

### Fase 4: Refactor backend incremental

Implementar por partes:

1. Campos de cancelacion y relaciones entre movimientos.
2. Reportes leyendo esos enlaces.
3. Normalizacion de ubicacion del dinero.
4. Transferencias locales.
5. Transferencias hacia arriba por eventos.
6. Reporte final por cuentas, conceptos y fechas.

## Checklist para retomar trabajo

Cuando se vuelva a trabajar este modulo:

1. Leer este archivo.
2. Confirmar rama con `git status --short --branch`.
3. Revisar `resources/js/Pages/ClubDirector/Finance/Cashbox.vue`.
4. Revisar `resources/js/Pages/ClubDirector/Finance/Accounting.vue`.
5. Revisar `app/Services/Finance/FinanceEngine.php` y `app/Services/Finance/FinanceWriter.php`.
6. Revisar `resources/js/Components/FinanceWorkflowNav.vue` si el cambio afecta navegacion financiera.
7. Revisar `resources/js/Components/Nav/ClubDirectorNav.vue`.
8. Revisar rutas historicas en `routes/web.php` si el cambio afecta compatibilidad o redirecciones.
9. Si el trabajo es solo visual, mantenerlo en frontend.
10. Si el trabajo cambia saldos, recibos, transferencias o cancelaciones, tratarlo como cambio backend y cubrirlo con pruebas.

## Frase corta de contexto para Codex

"Estamos en el reacomodo del modulo financiero del club en `feature/frontend-finance-reorg`. Ya existen vistas nuevas de `Caja` y `Contabilidad`; ambas leen y escriben por el motor financiero. Las rutas historicas principales siguen activas como redirecciones o endpoints compatibles, pero varias pantallas antiguas ya fueron eliminadas. Leer `guides/finance_rework_guide.md` antes de continuar."
