# Guia del Event Planner

Este documento explica como funciona el Event Planner, con enfasis en tareas, formularios, plantillas y el catalogo de formularios para superadmin.

## Proposito

El Event Planner permite planificar eventos de club, distrito, asociacion o union. Dentro de cada evento se administran tareas operativas, participantes, documentos, transporte, presupuesto, pagos y liquidaciones.

La parte importante para entrenamiento es entender que una tarea puede completarse de varias formas:

- con un formulario custom creado para esa tarea;
- con un formulario global reutilizable por `task_key`;
- con un handler fijo que abre una pantalla especial, como participantes, documentos o transporte;
- o sin formulario, cuando solo se marca como completada.

## Conceptos Principales

### Evento

Un evento vive en la tabla `events`.

Campos importantes:

- `scope_type`: indica quien organiza el evento. Puede ser `club`, `district`, `association` o `union`.
- `scope_id`: id del organizador segun el `scope_type`.
- `club_id`: club base usado por el sistema para relacionar el evento.
- `event_type`: tipo de evento, por ejemplo `camporee`, `camp`, `museum_trip`.
- `target_club_types`: tipos de clubes objetivo, cuando aplica.

Un evento de asociacion puede estar dirigido a varios clubes. En ese caso, las tareas pueden ser responsabilidad del organizador, del distrito o de cada club.

### Tarea de Evento

Una tarea vive en la tabla `event_tasks`.

Campos importantes:

- `title`: nombre visible de la tarea.
- `description`: explicacion de la tarea.
- `responsibility_level`: define quien completa la tarea. Puede ser `organizer`, `district`, `association` o `club`, segun el evento.
- `status`: estado general de la tarea.
- `checklist_json`: metadata de la tarea. Aqui viven `task_key` y `custom_form_schema`.

Ejemplo de `checklist_json`:

```json
{
  "source": "event_checklist",
  "task_key": "medical_forms",
  "custom_form_schema": {
    "mode": "single",
    "fields": [
      {
        "key": "medical_forms_uploaded",
        "label": "Medical forms uploaded",
        "type": "checkbox",
        "required": true
      }
    ]
  }
}
```

## Formularios Globales

Los formularios globales viven en la tabla `task_form_schemas`.

Son schemas reutilizables por `task_key`, sin depender de club, tipo de club o tipo de evento.

Ejemplos:

- `emergency_contacts`
- `chaperone_assignments`
- `camp_reservation`

Si una tarea tiene `task_key = "emergency_contacts"` y no tiene `custom_form_schema`, el sistema busca un formulario global con key `emergency_contacts`.

Los formularios globales son buenos para procesos generales que se completan igual en casi cualquier evento.

Desde superadmin se pueden crear nuevos formularios globales en:

```text
Superadmin > Formularios de tareas > Formularios globales > Nuevo formulario global
```

El creador visual permite definir:

- `key`: identificador global del formulario.
- `name`: nombre visible para administracion.
- `description`: explicacion interna.
- `mode`: `single` para un solo formulario o `registry` para registros repetibles.
- campos del formulario, incluyendo etiqueta, key, tipo, requerido, ayuda y opciones para selects.

El `key` debe escribirse en formato snake_case, por ejemplo `medical_forms` o `club_safety_checklist`.

Usar un formulario global nuevo cuando el mismo formulario debe estar disponible para cualquier tarea que use ese `task_key`, sin depender del club o del tipo de evento.

No usar formularios globales para keys que ya tienen handler fijo del sistema:

- `finalize_attendee_list`: abre participantes.
- `permission_slips` o `permission_slip`: abre documentos.
- `transportation_plan`: abre el modal de transporte.

Esas keys no se tratan como formularios globales porque no capturan datos con `schema_json`; navegan a pantallas existentes.

## Handlers Fijos

Los handlers fijos son comportamientos del sistema que no viven en `task_form_schemas`.

Actualmente existen estos handlers:

| Handler | Keys o condiciones | Resultado |
| --- | --- | --- |
| `Participants tab` | `finalize_attendee_list` | Abre la pestana de participantes. |
| `Documents tab` | `permission_slips`, `permission_slip` o keywords como `medical`, `insurance`, `rental`, `doc`, `permission` | Abre la pestana de documentos. |
| `Transportation modal` | `transportation_plan` | Abre el modal especializado de transporte. |

Un `custom_form_schema` en una tarea viva siempre tiene prioridad sobre estos handlers. Por eso una tarea como `First aid kit & medical forms Club` puede abrir su formulario custom aunque el titulo contenga `medical`.

Para keywords de documentos que no son keys reservadas, un formulario global real puede tener prioridad si existe en `task_form_schemas`. Por ejemplo, `medical_forms` puede ser formulario global si se crea explicitamente. En cambio `permission_slips` queda reservado para documentos.

## Participantes en Eventos Jerarquicos

En eventos creados por encima del club, por ejemplo distrito, asociacion o union, la pestana `Participantes` cambia segun quien la esta viendo.

### Vista del Organizador

Si el evento es de asociacion, union o distrito, y el usuario que lo ve no esta actuando como club, la pestana muestra un resumen por club.

El organizador ve:

- clubes objetivo del evento;
- estado de inscripcion del club;
- miembros confirmados por el club;
- miembros que ya tienen pago completo registrado;
- miembros que estan confirmados y pagados;
- staff confirmado por el club.

El organizador no marca asistencia individual desde esta vista. Solo revisa avance por club.

### Vista del Club

El club objetivo es quien marca la lista real de asistencia.

Desde la vista del club se puede:

- agregar miembros;
- agregar padres;
- agregar staff;
- confirmar o cancelar participantes;
- finalizar la lista de asistentes.

Cuando se agrega staff desde el club, el sistema guarda `staff_id` en `event_participants`. Esto permite que el organizador vea el conteo correcto de staff confirmado por club.

### Regla de Responsabilidad

La regla es:

```text
Eventos de club:
  el club maneja participantes directamente.

Eventos por encima del club:
  cada club maneja sus propios participantes.
  el organizador ve resumen por club.
```

## Plantillas Guardadas

Las plantillas guardadas viven en la tabla `event_task_templates`.

Sirven como catalogo para crear tareas automaticamente cuando se genera o se siembra un evento.

Cada plantilla se identifica principalmente por:

- `club_id`
- `event_type`
- `title`

Tambien puede tener:

- `task_key`
- `form_schema_json`
- `is_active`
- `is_custom`

Esto permite que una tarea con el mismo nombre o el mismo `task_key` tenga un formulario diferente segun el club o el tipo de evento.

Ejemplo:

| Club | Event type | Tarea | task_key | Formulario |
| --- | --- | --- | --- | --- |
| Club A | `camporee` | First aid kit & medical forms | `medical_forms` | Checklist medico completo |
| Club A | `museum_trip` | First aid kit & medical forms | `medical_forms` | Checklist simple de salida |
| Club B | `camporee` | First aid kit & medical forms | `medical_forms` | Checklist adaptado al club |

Cuando se crea un evento nuevo y se usan plantillas guardadas, el `form_schema_json` de la plantilla se copia a la tarea real como `custom_form_schema`.

Eso significa que la tarea viva tendra su propio formulario custom y no dependera del formulario global.

## Tareas de Eventos

La seccion `Tareas de eventos` en el catalogo de superadmin muestra tareas reales ya creadas en eventos existentes.

Esta seccion sirve para diagnosticar y corregir casos concretos.

Por ejemplo:

- una tarea aparece como `Documents tab`, pero deberia abrir un formulario;
- una tarea tiene el `task_key` equivocado;
- una tarea necesita un `custom_form_schema`;
- una tarea heredo una plantilla incorrecta;
- una tarea debe quitar su formulario custom y volver al comportamiento global.

Los cambios aqui afectan solo esa tarea real. No necesariamente cambian eventos futuros, excepto que al guardar una tarea tambien se sincroniza una plantilla guardada para ese club y tipo de evento.

## Prioridad de Formularios y Handlers

Cuando un usuario abre una tarea desde el Event Planner, el sistema decide que abrir en este orden:

1. `custom_form_schema` en la tarea real.
2. Handler especial de participantes.
3. Handler especial de transporte.
4. Handler fijo de documentos para `permission_slips` o `permission_slip`.
5. Formulario global por `task_key`.
6. Handler de documentos por keywords, si no existe formulario global para esa key.
7. Sin formulario.

La regla mas importante es esta:

Si la tarea tiene `custom_form_schema`, ese formulario tiene prioridad sobre el redirect a documentos.

Esto corrige el caso donde una tarea como `First aid kit & medical forms Club` contiene palabras como `medical` o `forms`, pero la asociacion ya habia creado un formulario custom. Antes podia abrir la pestana de documentos por las palabras del titulo. Ahora debe abrir el formulario custom.

## Catalogo de Superadmin

Ruta:

```text
/super-admin/event-task-forms
```

Menu:

```text
Superadmin > Formularios de tareas
```

La pantalla tiene cuatro secciones.

### 1. Handlers Fijos

Muestra los redirects o modales del sistema que no usan `schema_json`.

Usar esta seccion para entender por que una tarea abre participantes, documentos o transporte aunque no tenga formulario asignado.

Los handlers fijos no se editan desde el constructor visual. Si una tarea debe capturar datos diferentes, se debe usar un `custom_form_schema`, una plantilla guardada o un formulario global con una key no reservada.

### 2. Formularios Globales

Crear y editar schemas globales por `task_key`.

Usar cuando:

- el formulario debe ser igual para todos;
- el `task_key` ya representa un flujo general;
- no se necesita variacion por evento, club o contexto.

No usar cuando:

- un camporee necesita datos distintos a un museo;
- un club o tipo de evento requiere campos especificos;
- solo una tarea viva esta mal configurada.

Para crear uno nuevo:

1. Hacer clic en `Nuevo formulario global`.
2. Definir el `key`, nombre y descripcion.
3. Elegir `Un solo registro` o `Lista repetible`.
4. Agregar campos con etiqueta, key, tipo y si son requeridos.
5. Guardar.

Despues de guardarlo, cualquier tarea que use ese `task_key` podra usar ese formulario global, siempre que la tarea no tenga un `custom_form_schema`.

### 3. Plantillas Guardadas

Edita templates que se usan para crear tareas en eventos futuros.

Usar cuando:

- quieres cambiar que formulario se copiara a nuevos eventos;
- un tipo de evento necesita un formulario diferente;
- una tarea debe existir con una forma especifica para cierto club y `event_type`;
- quieres activar o desactivar una plantilla.

Notas:

- `form_schema_json` se copia a la tarea viva como `custom_form_schema`.
- Si `form_schema_json` esta vacio, la tarea dependera de `task_key` o de un handler especial.
- La plantilla actual esta ligada a `club_id`, no a `club_type` como llave directa.

El modal `Editar plantilla de tarea` permite modificar datos de la plantilla y tambien construir graficamente el `form_schema_json`.

Este cambio afecta eventos futuros que usen esa plantilla. No cambia automaticamente tareas ya existentes en eventos anteriores.

Si se apaga `Usar formulario custom en esta plantilla`, la plantilla queda sin `form_schema_json`. En ese caso, las tareas creadas desde esa plantilla dependeran del `task_key` o de un handler especial.

### 4. Tareas de Eventos

Edita tareas reales ya existentes.

Usar cuando:

- un usuario reporta que una tarea abre el lugar equivocado;
- una tarea especifica necesita un formulario diferente;
- quieres ver el `active_handler` actual;
- necesitas corregir `task_key` o `custom_form_schema` en una tarea puntual.

Handlers comunes:

- `Custom form`: abre el formulario custom definido en la tarea.
- `Documents tab`: abre la pestana de documentos.
- `Participants tab`: abre participantes.
- `Transportation modal`: abre el modal de transporte.
- `Global form`: usa formulario global por `task_key`.
- `Missing global schema`: la tarea tiene una key conocida, pero no existe schema global cargado para esa key.
- `No form handler`: no hay formulario ni handler especial.

El modal `Editar formulario activo` permite corregir una tarea real ya creada. Tambien incluye constructor grafico para el `custom_form_schema`.

Este cambio afecta esa tarea viva. Es el lugar correcto para corregir un problema reportado en un evento especifico.

Desde este modal tambien se puede usar `Asignar formulario existente`.

Opciones disponibles:

- Formularios globales: asignan el `task_key` del formulario global y quitan el `custom_form_schema` de la tarea. La tarea usara el schema global mientras exista ese `task_key`.
- Plantillas guardadas: copian el `form_schema_json` de la plantilla hacia la tarea como `custom_form_schema`.

Los handlers fijos no aparecen en `Asignar formulario existente`, porque no son formularios. Para usar un handler fijo, basta con que la tarea tenga la key correspondiente o que el titulo coincida con sus keywords.

Usar `Asignar formulario existente` cuando la tarea no tiene formulario asignado o cuando ya existe un formulario correcto en el catalogo y no se quiere construir uno desde cero.

Si se apaga `Usar formulario custom en esta tarea`, al guardar se quita el `custom_form_schema` de esa tarea. Entonces la tarea vuelve a depender de su `task_key` o de un handler como documentos, participantes o transporte.

### Diferencia Entre Editar Plantilla y Editar Formulario Activo

Los dos modales se parecen porque ambos pueden construir un formulario, pero operan sobre niveles diferentes:

| Modal | Edita | Afecta |
| --- | --- | --- |
| `Editar plantilla de tarea` | `event_task_templates.form_schema_json` | Eventos futuros que usen esa plantilla |
| `Editar formulario activo` | `event_tasks.checklist_json.custom_form_schema` | Una tarea real ya creada |

Regla practica:

- Si el problema es de un evento existente, usar `Editar formulario activo`.
- Si el cambio debe aplicarse a eventos futuros, usar `Editar plantilla de tarea`.
- Si el formulario debe ser general para todos los contextos, usar `Formularios globales`.

Los tres constructores permiten usar JSON avanzado, pero para usuarios no tecnicos se recomienda usar el constructor grafico.

## Formato del Schema

Un schema debe tener esta forma base:

```json
{
  "mode": "single",
  "fields": [
    {
      "key": "field_key",
      "label": "Field label",
      "type": "text",
      "required": true
    }
  ]
}
```

`mode` puede ser:

- `single`: un solo formulario.
- `registry`: registros repetibles, como una lista de personas, items o verificaciones.

Tipos de campo usados por el Event Planner:

- `text`
- `textarea`
- `number`
- `date`
- `time`
- `select`
- `checkbox`
- `image`

Ejemplo con `select`:

```json
{
  "mode": "single",
  "fields": [
    {
      "key": "risk_level",
      "label": "Risk level",
      "type": "select",
      "required": true,
      "options": ["Low", "Medium", "High"]
    }
  ]
}
```

Ejemplo de formulario tipo registro:

```json
{
  "mode": "registry",
  "fields": [
    {
      "key": "item",
      "label": "Item",
      "type": "text",
      "required": true
    },
    {
      "key": "ready",
      "label": "Ready",
      "type": "checkbox",
      "required": false
    }
  ]
}
```

## Caso Practico: First Aid Kit & Medical Forms

Problema:

Una asociacion crea una tarea llamada `First aid kit & medical forms Club`, asignada a clubes. El club abre la tarea, pero el sistema lo manda a documentos en lugar de abrir el formulario custom.

Causa:

El titulo contiene palabras como `medical` y `forms`, que antes activaban el handler de documentos antes de revisar el formulario custom.

Solucion:

1. Ir a `Superadmin > Formularios de tareas`.
2. Filtrar por `medical` o por el evento.
3. Buscar la tarea en `Tareas de eventos`.
4. Revisar `Activo`.
5. Si aparece `Documents tab`, editar la tarea.
6. Agregar o corregir `custom_form_schema`.
7. Guardar.
8. Volver a abrir la tarea desde la vista del club.

Resultado esperado:

La tarea debe abrir `Custom form`.

## Recomendaciones de Uso

- Usa formularios globales para procesos estables y generales.
- Usa plantillas guardadas para formularios que deben repetirse en eventos futuros.
- Usa tareas de eventos para corregir un caso real ya creado.
- Si hay conflicto entre `task_key` y `custom_form_schema`, el `custom_form_schema` gana.
- Mantener `key` de campos en formato snake_case.
- No reutilizar el mismo `key` para campos diferentes dentro del mismo formulario.
- Antes de quitar un `custom_form_schema`, confirmar que el `task_key` o handler resultante es el correcto.

## Ciclo de Aprendizaje de Tareas y Formularios

El flujo normal del sistema es:

1. Se crea un evento.
2. El Event Planner usa AI y la descripcion del evento para generar una lista sugerida de tareas.
3. Las tareas sugeridas se crean como tareas reales del evento en `event_tasks`.
4. El sistema tambien puede guardar o actualizar plantillas en `event_task_templates` para reutilizarlas en eventos futuros.
5. El usuario revisa las tareas en el Event Planner.
6. Si una tarea necesita capturar datos especificos, el usuario crea o edita un formulario custom.
7. Ese formulario queda guardado en la tarea real como `custom_form_schema`.
8. Al guardarse la tarea, el sistema sincroniza una plantilla guardada para ese club y tipo de evento.

Esto permite que el sistema aprenda de la operacion real: las tareas generadas por AI pueden convertirse en plantillas reutilizables y los formularios creados por usuarios pueden alimentar futuros eventos similares.

### Escenario Cubierto

Caso:

Un club crea un evento. AI sugiere una tarea como `First aid kit & medical forms`. El usuario revisa la tarea y crea un formulario custom para esa tarea.

Luego otro club o evento obtiene una tarea igual o parecida, pero el usuario necesita completarla con un formulario diferente.

El sistema cubre este caso de esta manera:

- Cada tarea real de evento puede tener su propio `custom_form_schema`.
- Dos eventos pueden tener la misma tarea pero formularios distintos.
- Dos clubes pueden tener la misma tarea pero formularios distintos.
- El mismo `task_key` puede existir con distintos formularios si viene de plantillas guardadas distintas.
- Las plantillas guardadas se separan por `club_id`, `event_type` y `title`.
- El formulario custom de una tarea viva siempre tiene prioridad sobre el formulario global o el handler de documentos.

Ejemplo:

| Club | Evento | Tarea | task_key | Formulario activo |
| --- | --- | --- | --- | --- |
| Club A | Camporee | First aid kit & medical forms | `medical_forms` | Checklist completo de camporee |
| Club B | Camporee | First aid kit & medical forms | `medical_forms` | Checklist adaptado al Club B |
| Club A | Museo | First aid kit & medical forms | `medical_forms` | Checklist simple de excursion |

Aunque el `task_key` sea el mismo, el formulario activo puede ser distinto porque cada tarea real puede tener su propio `custom_form_schema`, y cada plantilla guardada puede tener su propio `form_schema_json`.

### Que Pasa con Eventos Nuevos

La seccion `Tareas de eventos` del catalogo de superadmin se alimenta automaticamente de cualquier tarea nueva creada en eventos reales. No necesita configuracion manual.

Si manana se crea un evento y AI genera tareas nuevas, esas tareas apareceran en `Tareas de eventos`.

Si esas tareas se guardan o se sincronizan como templates, tambien podran aparecer en `Plantillas guardadas`.

### Limites Actuales

El sistema no hace matching semantico avanzado en el catalogo.

Actualmente la reutilizacion de plantillas depende principalmente de:

- `club_id`
- `event_type`
- `title`

Esto significa:

- Si el titulo cambia mucho, puede crearse una plantilla nueva aunque conceptualmente sea parecida.
- Si dos usuarios usan el mismo club, mismo tipo de evento y mismo titulo, la plantilla guardada puede actualizarse con la version mas reciente.
- Las tareas vivas existentes no se rompen cuando cambia una plantilla, porque conservan su propio `custom_form_schema`.
- `club_type` todavia no es una llave directa del catalogo de plantillas.

### Diferencia Entre Tarea Viva y Plantilla Futura

Una tarea viva es la tarea real dentro de un evento existente.

Una plantilla guardada es una definicion reusable para crear tareas en eventos futuros.

Cambiar una tarea viva corrige ese evento puntual. Tambien puede sincronizar una plantilla para eventos futuros del mismo club y tipo de evento.

Cambiar una plantilla guardada afecta eventos futuros que usen esa plantilla, pero no cambia automaticamente las tareas ya creadas en eventos existentes.

## Reglas de Decision para Soporte

Cuando alguien reporte que una tarea abre el formulario equivocado:

1. Buscar la tarea en `Tareas de eventos`.
2. Revisar `Activo`.
3. Si dice `Documents tab` y debe ser formulario, usar `Asignar formulario existente` o agregar `custom_form_schema`.
4. Si dice `Global form`, revisar si el `task_key` apunta al formulario global correcto.
5. Si dice `Missing global schema`, crear el formulario global faltante o asignar un formulario custom/plantilla.
6. Si el problema debe corregirse para eventos futuros, revisar tambien `Plantillas guardadas`.
7. Si solo es ese evento, editar solo la tarea viva.

## Notas Tecnicas

Archivos principales:

- `app/Models/TaskFormSchema.php`
- `app/Models/EventTaskTemplate.php`
- `app/Models/EventTask.php`
- `app/Http/Controllers/TaskFormController.php`
- `app/Http/Controllers/EventTaskController.php`
- `app/Http/Controllers/SuperAdminEventTaskFormCatalogController.php`
- `app/Services/EventTaskTemplateService.php`
- `resources/js/Pages/EventPlanner/Show.vue`
- `resources/js/Pages/SuperAdmin/EventTaskFormCatalog.vue`

Tablas principales:

- `task_form_schemas`
- `event_task_templates`
- `event_tasks`
- `event_task_assignments`
- `event_participants`
- `task_form_responses`

Relacion general:

```text
task_form_schemas
  formulario global por task_key

event_task_templates
  plantilla guardada para crear tareas futuras

event_tasks
  tarea real dentro de un evento

task_form_responses
  respuesta guardada del formulario
```

## Glosario

- `task_key`: identificador logico de una tarea o formulario.
- `custom_form_schema`: formulario custom guardado directamente en una tarea real.
- `form_schema_json`: formulario custom guardado en una plantilla.
- `schema_json`: formulario global.
- `handler`: comportamiento que se abre al completar una tarea.
- `template`: plantilla usada para crear tareas automaticamente.
- `live task`: tarea real dentro de un evento existente.

## Vista de Preparacion del Evento

La vista `Ver preparacion` concentra en una sola pantalla el estado operativo del evento.

Su objetivo es responder rapidamente:

- Que clubes estan listos.
- Que clubes tienen pendientes.
- Que clubes requieren atencion critica.
- Por que un club no esta listo.
- Que recordatorios deberian enviarse.
- Si el evento esta listo para cierre final.

### Senales que Usa

La preparacion se calcula en tiempo real. No guarda un estado separado.

Usa estas fuentes:

- Clubes involucrados en el evento.
- Participantes confirmados por cada club.
- Miembros inscritos por pago obligatorio completo.
- Staff inscrito por pago obligatorio completo.
- Tareas asignadas por nivel: organizador, asociacion, distrito o club.
- Formularios de tareas completados cuando correspondan.
- Documentos cargados al evento.
- Pagos recibidos por conceptos del evento.
- Depositos o comprobantes registrados hacia el organizador.
- Conceptos financieros del evento, separados entre obligatorios y opcionales.

### Estados por Club

Cada club puede quedar en uno de estos estados:

| Estado | Significado |
| --- | --- |
| `Preparacion completa` | No tiene alertas ni pendientes detectados. |
| `Pendientes por completar` | Hay trabajo pendiente, pero no necesariamente critico. |
| `Atencion critica requerida` | Hay un problema critico que debe resolverse antes de considerar listo al club. |

Estos estados no bloquean tecnicamente el sistema. Son indicadores operativos para priorizar seguimiento.

Ejemplos de alertas criticas:

- Club que declino el evento.
- Club marcado como dirigido/targeted sin participantes, pagos, tareas completadas ni documentos.

Ejemplos de pendientes:

- Club todavia no confirmo participacion, pero ya tiene algun avance registrado.
- Hay dinero cobrado pendiente de depositar.
- Miembros confirmados con pago obligatorio pendiente.
- Staff confirmado con pago obligatorio pendiente.
- Tareas asignadas al club que siguen pendientes, aunque ya esten vencidas.
- Tareas del organizador que siguen pendientes, aunque ya esten vencidas.
- Clubes sin participantes confirmados o inscritos todavia.

### Recordatorios

La vista genera una lista de recordatorios sugeridos, pero actualmente no envia correos automaticamente.

Esto es intencional porque todavia no hay procesador de correo configurado para el proyecto.

Por ahora los recordatorios funcionan como una bandeja operativa:

- Indican a que club o responsable se deberia avisar.
- Muestran la razon del aviso.
- Incluyen un mensaje base en texto.
- Marcan el estado del procesador como `placeholder`.

### Como Activar Envio Real de Recordatorios en el Futuro

Cuando exista un procesador de correo o servicio transaccional, implementar estos pasos:

1. Crear una tabla para registrar recordatorios enviados, por ejemplo `event_readiness_reminders`.
2. Guardar `event_id`, `scope_type`, `scope_id`, `reason`, `message`, `sent_at`, `sent_to_user_id`, `status` y `provider_message_id`.
3. Crear un `Mailable` o `Notification`, por ejemplo `EventReadinessReminderNotification`.
4. Crear un job en cola, por ejemplo `SendEventReadinessReminderJob`.
5. Cambiar la vista para que el boton `Enviar recordatorios` dispare un endpoint seguro.
6. El endpoint debe regenerar la preparacion, filtrar recordatorios pendientes y encolar los envios.
7. Antes de enviar, revisar si ya existe un recordatorio igual enviado recientemente para evitar duplicados.
8. Configurar SMTP, Mailgun, SES u otro proveedor en `.env`.
9. Activar queue worker en produccion.

Variables esperadas en `.env` cuando se habilite correo:

```text
MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME=
QUEUE_CONNECTION=database
```

Mientras esto no exista, no se debe prometer envio automatico al usuario final. La vista solo muestra los recordatorios que deben gestionarse manualmente.

### Cierre del Evento

La seccion de cierre muestra si el evento esta listo para cierre final.

Actualmente es un checklist calculado, no un bloqueo irreversible.

El cierre se considera listo cuando:

- No hay alertas criticas.
- Todos los clubes visibles estan listos.
- No hay depositos pendientes.
- El evento esta en curso o ya finalizo.

### PDF de Preparacion

La vista `Ver preparacion` tiene su propio PDF: `Exportar preparacion PDF`.

Este PDF no es la lista general de participantes. Imprime:

- Resumen de clubes listos, pendientes y con atencion critica.
- Estado por club.
- Inscritos de miembros y staff.
- Avance de tareas.
- Documentos cargados.
- Depositos pendientes.
- Alertas por club.
- Recordatorios sugeridos.
- Checklist de cierre.

La lista general de participantes sigue existiendo como PDF separado desde la seccion de participantes.

### Reporte Financiero del Evento

La vista de preparacion incluye un reporte financiero resumido del evento.

Este reporte muestra:

- Todos los clubes visibles que fueron dirigidos o involucrados en el evento, aunque no hayan pagado todavia.
- El estado del club en la preparacion.
- El total obligatorio esperado.
- El total pagado.
- El monto pendiente de depositar al organizador.
- Una columna por cada concepto del desglose financiero del evento.
- Si cada concepto es obligatorio u opcional.
- Un desglose por miembro o staff para ver quien pago cada concepto.

Los clubes `targeted` sin ningun avance aparecen con atencion critica, pero siguen apareciendo en el reporte financiero con montos en cero para que no desaparezcan del seguimiento.

Una version futura puede guardar un snapshot final con:

- Lista general de participantes.
- Estado de pagos.
- Estado de depositos.
- Estado de tareas y formularios.
- Documentos/comprobantes asociados.
