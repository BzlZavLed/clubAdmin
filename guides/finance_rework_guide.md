# Guia de reacomodo de finanzas

Fecha de referencia: 2026-05-08  
Rama actual de trabajo: `feature/frontend-finance-reorg`  
Commit base del primer reacomodo: `edbf498 first rework`

## Fechas y estado

| Area | Estado | Fecha |
| --- | --- | --- |
| Inicio documentado de la rama | Hecho | 2026-05-08 |
| Primera reorganizacion frontend-only | Hecho | 2026-05-08 |
| Documento de continuidad de la rama | Hecho | 2026-05-08 |
| Revision mobile completa de finanzas | Pendiente | Sin fecha |
| Diseno tecnico del motor financiero | Pendiente | Sin fecha |
| Refactor backend del motor financiero | Pendiente | Sin fecha |
| Cierre total de la rama | Pendiente | Sin fecha |

La rama se considera parcialmente lista desde 2026-05-08 para validar la nueva organizacion visual de finanzas.

La rama se considerara completamente lista cuando esten hechos estos puntos:

1. La navegacion financiera este validada en desktop y mobile.
2. Caja, Balances y transferencias, y Reportes financieros esten visualmente claros para el usuario.
3. El motor backend tenga una decision tomada: dejarlo como esta o iniciar refactor con migraciones.
4. Si se hace refactor backend, ingresos, gastos, transferencias, recibos, comprobantes y cancelaciones deben quedar trazables en reportes.
5. `npm run build` y las pruebas relevantes pasen antes de merge.

## Definicion de la rama

Esta rama representa el reacomodo progresivo del modulo financiero del club.

El alcance inmediato de la rama es ordenar la experiencia del usuario sin romper el motor financiero que ya esta funcionando. El resultado actual debe entenderse como una primera capa de organizacion: misma data, mismas rutas principales, pero con una estructura mental mas clara para el usuario.

La direccion final de esta rama es servir como base para un futuro refactor mas profundo del motor financiero, donde ingresos, gastos, transferencias, recibos, comprobantes y correcciones queden trazados de forma mas uniforme.

En resumen:

- Donde estamos: primera reorganizacion frontend-only de finanzas.
- Hacia donde vamos: motor financiero mas ordenado, auditable y basado en movimientos trazables.
- Regla de seguridad: no cambiar saldos, recibos, transferencias ni cancelaciones desde frontend solamente.
- Uso de este archivo: leerlo antes de continuar cualquier trabajo de esta rama.

## Proposito

El objetivo es que el modulo financiero del club sea mas facil de entender y usar sin perder las herramientas que ya funcionan.

La idea general es reacomodar finanzas en tres areas:

1. `Caja`: captura operativa de ingresos y gastos.
2. `Balances y transferencias`: donde esta el dinero, por cuenta, efectivo/banco, transferencias locales y transferencias hacia arriba cuando aplique.
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

Vistas que ahora muestran el flujo financiero compartido:

- `resources/js/Pages/ClubDirector/Payments.vue`
- `resources/js/Pages/ClubDirector/Expenses.vue`
- `resources/js/Pages/ClubDirector/Treasury.vue`
- `resources/js/Pages/ClubDirector/MyClubFinances.vue`
- `resources/js/Pages/ClubDirector/Reports/Finances.vue`
- `resources/js/Pages/ClubDirector/Reports/Accounts.vue`
- `resources/js/Pages/ClubDirector/AccountingCorrections.vue`

Validacion hecha en la primera fase:

- `npm run build`
- `git diff --check`

### Pendiente, sin fecha

Trabajo aun no realizado:

- Validar mobile de todas las paginas financieras.
- Decidir si el motor financiero actual se conserva o se refactoriza.
- Disenar migraciones si se decide refactor backend.
- Normalizar reportes para que todas las cancelaciones, recibos y comprobantes sean trazables desde una misma lectura contable.

## Mapa actual de herramientas

### Caja

Ruta de ingresos:

- `/club-director/payments`
- Componente: `resources/js/Pages/ClubDirector/Payments.vue`
- Uso: registrar ingresos del club, pagos por concepto, pagos manuales, pagos de eventos y recibos.

Ruta de gastos:

- `/club-director/expenses`
- Componente: `resources/js/Pages/ClubDirector/Expenses.vue`
- Uso: registrar egresos contra cuentas `pay_to`, subir comprobantes y manejar reembolsos cuando aplique.

Correcciones:

- `/club-director/accounting-corrections`
- Componente: `resources/js/Pages/ClubDirector/AccountingCorrections.vue`
- Uso: revertir movimientos erroneos creando movimientos opuestos, sin borrar historial.

### Balances y transferencias

Configuracion de cuentas y conceptos:

- `/club-director/my-club-finances`
- Componente: `resources/js/Pages/ClubDirector/MyClubFinances.vue`
- Uso: administrar cuentas `pay_to` y conceptos de pago.

Tesoreria:

- `/club-director/treasury`
- Componente: `resources/js/Pages/ClubDirector/Treasury.vue`
- Uso: ver efectivo/banco, registrar depositos, retiros, movimientos locales y transferencias externas de eventos.

Nota importante:

- `/club-director/event-settlements` existe como endpoint JSON.
- No debe ponerse directo en el menu como si fuera una pagina.
- El flujo de transferencias de eventos debe verse desde `Tesoreria` hasta que exista una pagina dedicada.

### Reportes financieros

Reporte general:

- `/club-director/reports/finances`
- Componente: `resources/js/Pages/ClubDirector/Reports/Finances.vue`
- Uso: reporte por cuenta, concepto, ubicacion del dinero y rango de fechas.

Reporte de movimientos / saldos por cuenta:

- `/club-director/reports/accounts`
- Componente: `resources/js/Pages/ClubDirector/Reports/Accounts.vue`
- Uso: saldos, movimientos por cuenta, ingresos, gastos, recibos y comprobantes asociados.

## Decisiones tomadas

### La primera fase fue frontend-only

Se decidio empezar por frontend para evitar romper la rama desplegada o el motor contable actual.

Esto permite validar con usuarios si la nueva forma mental tiene sentido antes de cambiar reglas internas.

### No se eliminaron herramientas

Las rutas actuales siguen existiendo. Solo se agruparon mejor.

### Correcciones siguen siendo parte del flujo

Aunque correcciones no es captura normal de caja, se dejo visible dentro del flujo financiero porque forma parte de la trazabilidad y auditoria.

### Transferencias de eventos viven en Tesoreria

La transferencia hacia asociacion, union u organizador del evento depende de dinero recibido por el club. Por ahora ese flujo debe estar dentro de Tesoreria porque ahi tambien se ve si el dinero esta en efectivo o banco.

## Objetivo funcional final

El destino deseado es que finanzas trabaje como un flujo simple:

1. El club cobra ingresos en `Caja`.
2. El club registra gastos en `Caja`.
3. El sistema sabe si el dinero esta en efectivo o banco.
4. Tesoreria permite mover dinero entre efectivo y banco.
5. Tesoreria permite transferir hacia arriba cuando el dinero corresponde a eventos o conceptos que deben salir del club.
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
- Si se cambia backend sin migraciones cuidadosas, se puede romper informacion contable ya capturada.
- El endpoint `/club-director/event-settlements` no es una pagina; debe mantenerse fuera del menu directo.

## Propuesta de siguientes fases

### Fase 2: Pulir UX sin backend

- Revisar mobile de todas las paginas financieras.
- Homologar nombres visibles:
  - `Caja`
  - `Ingresos de caja`
  - `Gastos de caja`
  - `Balances y transferencias`
  - `Reportes financieros`
- Mejorar `Treasury.vue` para que se sienta como el centro de ubicacion del dinero.
- Mejorar `Reports/Finances.vue` para que sea el reporte general por cuenta/concepto/rango.
- Mejorar `Reports/Accounts.vue` para que sea mas claramente reporte de movimientos y saldos.

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
3. Revisar `resources/js/Components/FinanceWorkflowNav.vue`.
4. Revisar `resources/js/Components/Nav/ClubDirectorNav.vue`.
5. Revisar `resources/js/Pages/ClubDirector/Treasury.vue`.
6. Si el trabajo es solo visual, mantenerlo en frontend.
7. Si el trabajo cambia saldos, recibos, transferencias o cancelaciones, tratarlo como cambio backend y crear plan de migracion.

## Frase corta de contexto para Codex

"Estamos en el reacomodo del modulo financiero del club. Ya existe una primera fase frontend-only en `feature/frontend-finance-reorg` que reorganiza finanzas en Caja, Balances y transferencias, y Reportes financieros. Leer `guides/finance_rework_guide.md` antes de continuar."
