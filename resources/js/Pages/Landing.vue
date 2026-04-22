<script setup>
import { Head } from '@inertiajs/vue3'
import { ref } from 'vue'

const menuOpen = ref(false)

const levels = [
    { step: '01', title: 'Union', description: 'Control general, publicacion masiva y seguimiento estructural.' },
    { step: '02', title: 'Asociacion', description: 'Adaptacion local del plan anual y gestion completa del territorio.' },
    { step: '03', title: 'Distrito', description: 'Coordinacion distrital y publicacion de eventos hacia clubes.' },
    { step: '04', title: 'Iglesia', description: 'Vinculacion organizativa dentro de la estructura local.' },
    { step: '05', title: 'Club', description: 'Ejecucion del programa, finanzas, clases, miembros y comunicacion.' },
]

const problems = [
    { icon: '◎', title: 'Desorganizacion', description: 'Reduce la falta de coordinacion entre Union, Asociacion, Distrito y Club.' },
    { icon: '↺', title: 'Procesos manuales', description: 'Automatiza asistencia, finanzas y reportes para evitar retrabajo.' },
    { icon: '✓', title: 'Sin seguimiento', description: 'Facilita el control de requisitos, carpetas e investidura.' },
    { icon: '⇄', title: 'Comunicacion deficiente', description: 'Aclara el flujo entre directiva, staff y padres.' },
    { icon: '¤', title: 'Perdida de datos', description: 'Centraliza registros y mejora la transparencia financiera.' },
    { icon: '⚡', title: 'Escalabilidad', description: 'Permite operar multiples asociaciones y estructuras con coherencia.' },
]

const solutions = [
    { icon: '🗓', title: 'Planificacion', description: 'Plan anual y local automatizado, con herencia entre niveles y publicacion controlada.' },
    { icon: '$', title: 'Finanzas', description: 'Recibos digitales, control de gastos, saldos pendientes e historial completo.' },
    { icon: '✓', title: 'Asistencia', description: 'Seguimiento y progreso en tiempo real por club, clase y sesion.' },
    { icon: '👥', title: 'Miembros', description: 'Gestion de miembros, staff, familias y datos administrativos.' },
    { icon: '⌂', title: 'Portal para padres', description: 'Evidencia digital, comunicacion clara y seguimiento del avance.' },
    { icon: '▥', title: 'Reportes', description: 'Reportes avanzados en tiempo real con exportacion para analisis externo.' },
]

const portals = [
    { tag: 'Portal de Union', title: 'Visibilidad y control a nivel Union', description: 'Dashboard general de la estructura, catalogo y requisitos, plan anual con publicacion masiva, y reportes de asistencia, progreso y efectividad.' },
    { tag: 'Portal de Asociacion', title: 'Adaptacion local del plan', description: 'Hereda la planeacion desde la Union, la adapta a necesidades locales, agrega eventos y gestiona distritos, evaluadores, iglesias y clubes.' },
    { tag: 'Portal de Distrito', title: 'Coordinacion distrital', description: 'Recibe eventos heredados, crea actividades distritales propias y publica el calendario hacia todos sus clubes.' },
    { tag: 'Portal del director', title: 'Centro de mando del club', description: 'Perfil y sistema de evaluacion, finanzas, planificacion con exportacion PDF/ICS, clases, miembros, staff y vinculacion con padres.' },
    { tag: 'Portal de Staff', title: 'Herramientas para el equipo', description: 'Calendario del club, planes de clase, registro de asistencia y pagos, y solicitudes de cambio al director.' },
    { tag: 'Portal de Padres', title: 'Transparencia para las familias', description: 'Calendario con eventos heredados, acceso a recibos, carga de evidencias y vista resumida del progreso con seguimiento de aprobacion.' },
]

const cascade = [
    { step: '1', title: 'Union publica', description: 'Define la base del plan anual para toda la red.' },
    { step: '2', title: 'Asociacion adapta', description: 'Ajusta el plan segun su realidad y necesidades locales.' },
    { step: '3', title: 'Distrito agrega', description: 'Suma actividades distritales al calendario heredado.' },
    { step: '4', title: 'Club visualiza', description: 'Opera con un calendario consolidado y listo para ejecutar.' },
]

const financeHighlights = [
    { icon: '#', title: 'Recibos automaticos', description: 'Numerados y generados al instante para cada operacion.' },
    { icon: '⌂', title: 'Vinculacion de padres', description: 'Actualizacion automatica al asociar una familia al club.' },
    { icon: '!', title: 'Pagos pendientes', description: 'Control claro de saldos y deudas en tiempo real.' },
    { icon: '↻', title: 'Historial completo', description: 'Registro de todas las transacciones y correcciones contables.' },
]

const reportHighlights = [
    { icon: 'A', title: 'Asistencia por niveles', description: 'Desglose completo por club, clase y sesion.' },
    { icon: 'R', title: 'Progreso de requisitos', description: 'Avance individual y grupal en tiempo real.' },
    { icon: 'P', title: 'Efectividad de programas', description: 'Mide el impacto de cada actividad.' },
    { icon: 'CSV', title: 'Exportacion CSV', description: 'Descarga datos para analisis externo y seguimiento adicional.' },
]

const benefits = [
    { title: 'Menos carga administrativa', description: 'La automatizacion reduce significativamente el trabajo manual.' },
    { title: 'Mayor transparencia', description: 'Finanzas y progreso visibles para todos los niveles.' },
    { title: 'Mejor comunicacion', description: 'Flujo claro entre directiva, staff y padres.' },
    { title: 'Escalabilidad total', description: 'Crece con multiples asociaciones y una digitalizacion completa.' },
]

const nextSteps = [
    { step: 'Paso 1', title: 'Reunion inicial', description: 'Conocemos sus necesidades y estructura.' },
    { step: 'Paso 2', title: 'Configuracion', description: 'Adaptamos la plataforma a la organizacion.' },
    { step: 'Paso 3', title: 'Despliegue', description: 'Implementacion progresiva con acompanamiento.' },
]

const closeMenu = () => {
    menuOpen.value = false
}
</script>

<template>
    <Head>
        <title>Sistema Integral de Gestion de Clubes SDA</title>
        <meta
            name="description"
            content="Sistema Integral de Gestion de Clubes SDA - Administracion, planificacion y control en una sola plataforma."
        >
        <link
            href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
            rel="stylesheet"
        >
    </Head>

    <div class="landing-page" id="inicio">
        <header class="landing-header sticky-top">
            <nav class="navbar navbar-expand-lg py-3">
                <div class="container-xxl px-3 px-md-4">
                    <a class="navbar-brand landing-brand d-flex align-items-center gap-3 me-0" href="#inicio" @click="closeMenu">
                        <span class="landing-brand-mark">✦</span>
                        <span class="landing-brand-text">Sistema Integral de Gestion de Clubes SDA</span>
                    </a>

                    <button
                        class="navbar-toggler landing-toggler border-0 shadow-none"
                        type="button"
                        aria-label="Mostrar menu"
                        :aria-expanded="menuOpen ? 'true' : 'false'"
                        @click="menuOpen = !menuOpen"
                    >
                        <span class="landing-toggler-icon"></span>
                    </button>

                    <div class="collapse navbar-collapse justify-content-lg-end" :class="{ show: menuOpen }">
                        <ul class="navbar-nav align-items-lg-center ms-lg-auto gap-lg-2 pt-3 pt-lg-0">
                            <li class="nav-item"><a class="nav-link landing-nav-link" href="#vision" @click="closeMenu">Vision</a></li>
                            <li class="nav-item"><a class="nav-link landing-nav-link" href="#solucion" @click="closeMenu">Solucion</a></li>
                            <li class="nav-item"><a class="nav-link landing-nav-link" href="#portales" @click="closeMenu">Portales</a></li>
                            <li class="nav-item"><a class="nav-link landing-nav-link" href="#reportes" @click="closeMenu">Reportes</a></li>
                            <li class="nav-item"><a class="nav-link landing-nav-link" href="#pasos" @click="closeMenu">Proximos pasos</a></li>
                            <li class="nav-item ms-lg-2">
                                <a class="btn landing-btn landing-btn-sm w-100 w-lg-auto" href="/login" @click="closeMenu">Acceder</a>
                            </li>
                        </ul>
                    </div>
                </div>
            </nav>
        </header>

        <main>
            <section class="landing-section landing-hero">
                <div class="container-xxl px-3 px-md-4">
                    <div class="row g-4 g-xl-5 align-items-center">
                        <div class="col-12 col-xl-6">
                            <div class="landing-eyebrow">Administracion, planificacion y control en una sola plataforma</div>
                            <h1 class="landing-display">Sistema Integral de Gestion de Clubes SDA</h1>
                            <p class="landing-lead">
                                Una plataforma disenada para digitalizar la administracion de clubes de Aventureros,
                                Conquistadores y Guias Mayores, conectando Union, Asociacion, Distrito, Iglesia y Club
                                en un solo flujo de trabajo.
                            </p>
                            <div class="d-flex flex-column flex-sm-row gap-3 mt-4">
                                <a class="btn landing-btn landing-btn-primary" href="/login">Acceder al sistema</a>
                                <a class="btn landing-btn landing-btn-secondary" href="#solucion">Conocer mas</a>
                            </div>
                        </div>

                        <div class="col-12 col-xl-6">
                            <div class="landing-panel">
                                <svg class="landing-hero-art" viewBox="0 0 600 520" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                    <rect x="355" y="35" width="160" height="82" rx="8" stroke="#F2C3CA" stroke-width="3"/>
                                    <path d="M435 118V170" stroke="#F2C3CA" stroke-width="3"/>
                                    <text x="385" y="88" fill="#F2C3CA" font-size="34" font-family="Arial, sans-serif">CLUB</text>
                                    <circle cx="240" cy="75" r="9" fill="#F2C3CA"/>
                                    <path d="M110 116C110 71 146 35 191 35C236 35 272 71 272 116C272 147 257 177 228 199L191 227L154 199C125 177 110 147 110 116Z" stroke="#F2C3CA" stroke-width="3"/>
                                    <path d="M191 88V208" stroke="#F2C3CA" stroke-width="3"/>
                                    <path d="M191 131L160 111" stroke="#F2C3CA" stroke-width="3"/>
                                    <g stroke="#F2C3CA" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="165" cy="312" r="28"/>
                                        <path d="M152 340L141 404"/>
                                        <path d="M177 341L191 404"/>
                                        <path d="M142 371L112 410"/>
                                        <path d="M177 371L214 403"/>
                                        <path d="M143 351L113 332"/>
                                        <path d="M176 352L208 334"/>
                                        <path d="M140 407H206" opacity="0.6"/>
                                        <circle cx="314" cy="252" r="28"/>
                                        <path d="M300 279L290 337"/>
                                        <path d="M326 279L339 337"/>
                                        <path d="M292 311L263 347"/>
                                        <path d="M327 311L362 342"/>
                                        <path d="M291 292L261 275"/>
                                        <path d="M328 294L359 278"/>
                                        <circle cx="452" cy="289" r="28"/>
                                        <path d="M440 318L431 385"/>
                                        <path d="M466 318L479 386"/>
                                        <path d="M434 348L405 387"/>
                                        <path d="M468 349L505 388"/>
                                        <path d="M434 330L405 311"/>
                                        <path d="M467 331L499 313"/>
                                        <circle cx="555" cy="255" r="28"/>
                                        <path d="M543 283L533 352"/>
                                        <path d="M569 283L580 352"/>
                                        <path d="M536 315L508 351"/>
                                        <path d="M569 315L598 353"/>
                                        <path d="M536 296L507 279"/>
                                        <path d="M569 297L598 280"/>
                                    </g>
                                    <path d="M75 456H560" stroke="#F2C3CA" stroke-width="3" stroke-dasharray="2 12" opacity="0.35"/>
                                </svg>

                                <div class="row g-3 position-relative">
                                    <div class="col-12">
                                        <div class="landing-stat">
                                            <strong>Gestion centralizada</strong>
                                            <span>Automatizada y escalable en todos los niveles.</span>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <div class="landing-stat">
                                            <strong>Planeacion en cascada</strong>
                                            <span>La Union publica, la Asociacion adapta, el Distrito agrega y el Club visualiza.</span>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <div class="landing-stat">
                                            <strong>Portal para familias</strong>
                                            <span>Transparencia, recibos, evidencias y seguimiento del progreso.</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section id="vision" class="landing-section">
                <div class="container-xxl px-3 px-md-4">
                    <div class="landing-section-head">
                        <h2>Vision general</h2>
                        <p>La plataforma organiza la gestion de clubes en cinco niveles conectados, con visibilidad y control desde la estructura regional hasta la operacion local.</p>
                    </div>
                    <div class="row g-4 row-cols-1 row-cols-md-2 row-cols-xl-5">
                        <div v-for="level in levels" :key="level.title" class="col">
                            <article class="landing-card h-100">
                                <div class="landing-step">{{ level.step }}</div>
                                <h3>{{ level.title }}</h3>
                                <p>{{ level.description }}</p>
                            </article>
                        </div>
                    </div>
                </div>
            </section>

            <section class="landing-section">
                <div class="container-xxl px-3 px-md-4">
                    <div class="landing-section-head">
                        <h2>Que problemas resuelve?</h2>
                        <p>El sistema elimina los puntos de friccion mas comunes en la administracion de clubes.</p>
                    </div>
                    <div class="row g-4 row-cols-1 row-cols-md-2 row-cols-xl-3">
                        <div v-for="problem in problems" :key="problem.title" class="col">
                            <article class="landing-card h-100">
                                <div class="landing-icon">{{ problem.icon }}</div>
                                <h3>{{ problem.title }}</h3>
                                <p>{{ problem.description }}</p>
                            </article>
                        </div>
                    </div>
                </div>
            </section>

            <section id="solucion" class="landing-section">
                <div class="container-xxl px-3 px-md-4">
                    <div class="landing-section-head">
                        <h2>Solucion propuesta</h2>
                        <p>Una plataforma completa que integra administracion, seguimiento y comunicacion en un solo lugar.</p>
                    </div>
                    <div class="row g-4 row-cols-1 row-cols-md-2 row-cols-xl-3">
                        <div v-for="solution in solutions" :key="solution.title" class="col">
                            <article class="landing-card h-100 landing-card-soft">
                                <div class="landing-icon">{{ solution.icon }}</div>
                                <h3>{{ solution.title }}</h3>
                                <p>{{ solution.description }}</p>
                            </article>
                        </div>
                    </div>
                </div>
            </section>

            <section id="portales" class="landing-section">
                <div class="container-xxl px-3 px-md-4">
                    <div class="landing-section-head">
                        <h2>Portales por nivel</h2>
                        <p>Cada perfil cuenta con herramientas especificas, manteniendo una experiencia coherente entre niveles.</p>
                    </div>
                    <div class="row g-4 row-cols-1 row-cols-xl-2">
                        <div v-for="portal in portals" :key="portal.title" class="col">
                            <article class="landing-card h-100">
                                <div class="landing-pill">{{ portal.tag }}</div>
                                <h3>{{ portal.title }}</h3>
                                <p>{{ portal.description }}</p>
                            </article>
                        </div>
                    </div>
                </div>
            </section>

            <section class="landing-section">
                <div class="container-xxl px-3 px-md-4">
                    <div class="landing-section-head">
                        <h2>Planificacion en cascada</h2>
                        <p>Cada nivel hereda y enriquece el plan, garantizando coherencia en toda la organizacion.</p>
                    </div>
                    <div class="row g-4 row-cols-1 row-cols-md-2 row-cols-xl-4">
                        <div v-for="item in cascade" :key="item.title" class="col">
                            <article class="landing-card h-100 landing-timeline-card">
                                <div class="landing-timeline-dot">{{ item.step }}</div>
                                <h3>{{ item.title }}</h3>
                                <p>{{ item.description }}</p>
                            </article>
                        </div>
                    </div>
                </div>
            </section>

            <section class="landing-section">
                <div class="container-xxl px-3 px-md-4">
                    <div class="row g-4">
                        <div class="col-12 col-xl-6">
                            <article class="landing-card h-100 landing-summary">
                                <div class="landing-kicker">Sistema financiero completo</div>
                                <h2>Control financiero claro y trazable</h2>
                                <div class="row g-3 mt-1">
                                    <div v-for="item in financeHighlights" :key="item.title" class="col-12">
                                        <div class="landing-highlight">
                                            <div class="landing-icon landing-icon-sm">{{ item.icon }}</div>
                                            <div>
                                                <strong>{{ item.title }}</strong>
                                                <p class="mb-0">{{ item.description }}</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </article>
                        </div>

                        <div class="col-12 col-xl-6">
                            <article class="landing-card h-100 landing-summary">
                                <div id="reportes" class="landing-kicker">Reportes inteligentes</div>
                                <h2>Visibilidad operativa en tiempo real</h2>
                                <div class="row g-3 mt-1">
                                    <div v-for="item in reportHighlights" :key="item.title" class="col-12">
                                        <div class="landing-highlight">
                                            <div class="landing-icon landing-icon-sm">{{ item.icon }}</div>
                                            <div>
                                                <strong>{{ item.title }}</strong>
                                                <p class="mb-0">{{ item.description }}</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </article>
                        </div>
                    </div>
                </div>
            </section>

            <section class="landing-section">
                <div class="container-xxl px-3 px-md-4">
                    <div class="row g-4 row-cols-1 row-cols-md-2 row-cols-xl-3">
                        <div class="col">
                            <article class="landing-card h-100">
                                <div class="landing-kicker">Validacion de documentos</div>
                                <h3>Autenticidad y auditoria</h3>
                                <p>Cada documento PDF puede incluir un codigo QR verificable, con snapshot historico para trazabilidad total en revisiones y validaciones.</p>
                            </article>
                        </div>
                        <div class="col">
                            <article class="landing-card h-100">
                                <div class="landing-kicker">Integraciones</div>
                                <h3>Ecosistema conectado</h3>
                                <p>Exporta o sincroniza eventos con Google Calendar, Apple Calendar y Outlook, ademas de integrarse con MyChurchAdmin.</p>
                            </article>
                        </div>
                        <div class="col">
                            <article class="landing-card h-100">
                                <div class="landing-kicker">Soporte bilingue</div>
                                <h3>Espanol e English</h3>
                                <p>La experiencia contempla operacion bilingue para organizaciones y usuarios en ambos idiomas.</p>
                            </article>
                        </div>
                    </div>
                </div>
            </section>

            <section class="landing-section">
                <div class="container-xxl px-3 px-md-4">
                    <div class="landing-section-head">
                        <h2>Beneficios para la organizacion</h2>
                        <p>La plataforma no solo administra, tambien fortalece la estructura y la calidad del programa de clubes.</p>
                    </div>
                    <div class="row g-4 row-cols-1 row-cols-md-2">
                        <div v-for="benefit in benefits" :key="benefit.title" class="col">
                            <article class="landing-card h-100 landing-card-soft">
                                <h3>{{ benefit.title }}</h3>
                                <p>{{ benefit.description }}</p>
                            </article>
                        </div>
                    </div>
                </div>
            </section>

            <section id="pasos" class="landing-section pb-5">
                <div class="container-xxl px-3 px-md-4">
                    <div class="landing-cta">
                        <div class="landing-kicker">Proximos pasos</div>
                        <h2>Despliegue progresivo segun la necesidad de su organizacion</h2>
                        <p class="landing-lead landing-lead-compact">
                            El proceso propuesto comienza con una reunion inicial, continua con la configuracion adaptada
                            a la estructura de la organizacion y avanza hacia una implementacion progresiva con acompanamiento.
                        </p>

                        <div class="row g-4 row-cols-1 row-cols-md-3 mt-1">
                            <div v-for="step in nextSteps" :key="step.step" class="col">
                                <article class="landing-card h-100">
                                    <div class="landing-step">{{ step.step }}</div>
                                    <h3>{{ step.title }}</h3>
                                    <p>{{ step.description }}</p>
                                </article>
                            </div>
                        </div>

                        <div class="d-flex flex-column flex-sm-row gap-3 mt-4">
                            <a class="btn landing-btn landing-btn-primary" href="/login">Ir al login</a>
                            <a
                                class="btn landing-btn landing-btn-secondary"
                                href="mailto:?subject=Solicitud%20de%20Demo%20-%20Sistema%20Integral%20de%20Gestion%20de%20Clubes%20SDA"
                            >
                                Solicitar demo
                            </a>
                        </div>
                    </div>
                </div>
            </section>
        </main>

        <footer class="landing-footer">
            <div class="container-xxl px-3 px-md-4">
                <p class="mb-0">Landing de acceso al sistema, adaptable para moviles y enlazada al flujo actual de autenticacion.</p>
            </div>
        </footer>
    </div>
</template>

<style scoped>
.landing-page {
    --landing-bg: #050505;
    --landing-bg-soft: #0d0d0d;
    --landing-surface: #121212;
    --landing-surface-2: #171717;
    --landing-border: rgba(255, 255, 255, 0.08);
    --landing-text: #ffffff;
    --landing-muted: rgba(255, 255, 255, 0.78);
    --landing-accent: #f2c3ca;
    --landing-accent-strong: #f8d6db;
    --landing-shadow: 0 24px 70px rgba(0, 0, 0, 0.32);
    background:
        radial-gradient(circle at top right, rgba(242, 195, 202, 0.07), transparent 22%),
        radial-gradient(circle at 0% 20%, rgba(255, 255, 255, 0.03), transparent 18%),
        var(--landing-bg);
    color: var(--landing-text);
    min-height: 100vh;
    overflow-x: hidden;
}

:global(html),
:global(body) {
    background: #050505;
}

.landing-page,
.landing-page * {
    box-sizing: border-box;
}

.landing-page a {
    text-decoration: none;
}

.landing-header {
    background: rgba(5, 5, 5, 0.9);
    backdrop-filter: blur(16px);
    border-bottom: 1px solid var(--landing-border);
}

.landing-brand {
    color: var(--landing-text);
    min-width: 0;
}

.landing-brand:hover {
    color: var(--landing-text);
}

.landing-brand-mark {
    width: 2.75rem;
    height: 2.75rem;
    border-radius: 0.95rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(180deg, rgba(242, 195, 202, 0.15), rgba(255, 255, 255, 0.03));
    border: 1px solid rgba(242, 195, 202, 0.24);
    color: var(--landing-accent);
    flex: 0 0 auto;
}

.landing-brand-text {
    font-size: 1rem;
    font-weight: 700;
    line-height: 1.2;
    white-space: normal;
}

.landing-toggler {
    padding: 0.5rem;
}

.landing-toggler-icon,
.landing-toggler-icon::before,
.landing-toggler-icon::after {
    display: block;
    width: 1.5rem;
    height: 2px;
    background: var(--landing-text);
    border-radius: 999px;
    transition: transform 0.2s ease, opacity 0.2s ease;
    content: "";
}

.landing-toggler-icon {
    position: relative;
}

.landing-toggler-icon::before {
    position: absolute;
    top: -0.45rem;
    left: 0;
}

.landing-toggler-icon::after {
    position: absolute;
    top: 0.45rem;
    left: 0;
}

.landing-nav-link {
    color: var(--landing-muted);
    font-size: 0.96rem;
    padding: 0.65rem 0.85rem !important;
}

.landing-nav-link:hover,
.landing-nav-link:focus {
    color: var(--landing-accent-strong);
}

.landing-section {
    padding: 4rem 0;
}

.landing-hero {
    padding-top: 4.5rem;
}

.landing-eyebrow {
    display: inline-flex;
    align-items: center;
    padding: 0.65rem 1rem;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.08);
    color: var(--landing-accent-strong);
    font-size: 0.92rem;
    margin-bottom: 1.25rem;
}

.landing-display {
    font-size: clamp(2.9rem, 7vw, 5.4rem);
    line-height: 0.98;
    letter-spacing: 0;
    margin: 0 0 1.25rem;
    max-width: 10ch;
    color: var(--landing-text);
}

.landing-lead {
    font-size: 1.12rem;
    line-height: 1.8;
    color: var(--landing-muted);
    max-width: 60ch;
    margin: 0;
}

.landing-lead-compact {
    max-width: 62ch;
}

.landing-btn {
    min-height: 3.25rem;
    padding: 0.85rem 1.35rem;
    border-radius: 0.95rem;
    font-weight: 700;
    border: 1px solid rgba(255, 255, 255, 0.12);
    transition: transform 0.2s ease, border-color 0.2s ease, background-color 0.2s ease;
}

.landing-btn:hover {
    transform: translateY(-1px);
}

.landing-btn-sm {
    min-height: 2.8rem;
    padding: 0.65rem 1rem;
}

.landing-btn-primary {
    background: linear-gradient(180deg, #181818, #0d0d0d);
    color: var(--landing-text);
    border-color: rgba(242, 195, 202, 0.28);
    box-shadow: 0 14px 32px rgba(0, 0, 0, 0.28);
}

.landing-btn-primary:hover,
.landing-btn-primary:focus {
    background: #171717;
    color: var(--landing-text);
    border-color: rgba(242, 195, 202, 0.45);
}

.landing-btn-secondary {
    background: rgba(255, 255, 255, 0.02);
    color: var(--landing-text);
}

.landing-btn-secondary:hover,
.landing-btn-secondary:focus {
    background: rgba(255, 255, 255, 0.06);
    color: var(--landing-text);
}

.landing-panel,
.landing-card,
.landing-cta {
    background: linear-gradient(180deg, rgba(20, 20, 20, 0.98), rgba(10, 10, 10, 0.98));
    border: 1px solid var(--landing-border);
    box-shadow: var(--landing-shadow);
}

.landing-panel {
    position: relative;
    border-radius: 1.75rem;
    overflow: hidden;
    padding: 1.25rem;
    min-height: 100%;
}

.landing-hero-art {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    opacity: 0.2;
    pointer-events: none;
}

.landing-stat {
    position: relative;
    z-index: 1;
    border-radius: 1.15rem;
    padding: 1rem 1.1rem;
    background: rgba(4, 4, 4, 0.88);
    border: 1px solid rgba(255, 255, 255, 0.08);
}

.landing-stat strong,
.landing-highlight strong {
    display: block;
    margin-bottom: 0.25rem;
    color: var(--landing-text);
}

.landing-stat span,
.landing-card p,
.landing-highlight p,
.landing-section-head p,
.landing-footer p {
    color: var(--landing-muted);
}

.landing-section-head {
    max-width: 62rem;
    margin-bottom: 1.75rem;
}

.landing-section-head h2,
.landing-summary h2,
.landing-cta h2 {
    font-size: clamp(2rem, 4vw, 3rem);
    line-height: 1.08;
    margin-bottom: 0.85rem;
    color: var(--landing-text);
}

.landing-card {
    height: 100%;
    border-radius: 1.4rem;
    padding: 1.4rem;
}

.landing-card-soft {
    background: linear-gradient(180deg, rgba(24, 24, 24, 0.98), rgba(12, 12, 12, 0.98));
}

.landing-card h3 {
    font-size: 1.28rem;
    line-height: 1.2;
    margin-bottom: 0.85rem;
    color: var(--landing-text);
}

.landing-step,
.landing-kicker {
    color: var(--landing-accent-strong);
    font-size: 0.86rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    margin-bottom: 0.8rem;
}

.landing-pill {
    display: inline-flex;
    align-items: center;
    padding: 0.4rem 0.8rem;
    border-radius: 999px;
    margin-bottom: 0.95rem;
    background: rgba(242, 195, 202, 0.1);
    border: 1px solid rgba(242, 195, 202, 0.18);
    color: var(--landing-accent-strong);
    font-size: 0.82rem;
    font-weight: 600;
}

.landing-icon {
    width: 3rem;
    height: 3rem;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 1rem;
    background: #0a0a0a;
    border: 1px solid rgba(242, 195, 202, 0.3);
    color: var(--landing-text);
    flex: 0 0 auto;
}

.landing-icon-sm {
    width: 2.7rem;
    height: 2.7rem;
    margin-bottom: 0;
    font-size: 0.9rem;
}

.landing-timeline-card {
    position: relative;
}

.landing-timeline-dot {
    width: 3.25rem;
    height: 3.25rem;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 1rem;
    background: #090909;
    border: 3px solid var(--landing-accent);
    color: var(--landing-accent-strong);
    font-weight: 800;
}

.landing-summary,
.landing-cta {
    border-radius: 1.6rem;
    padding: 1.5rem;
}

.landing-highlight {
    display: flex;
    gap: 1rem;
    align-items: flex-start;
    padding: 1rem;
    border-radius: 1.1rem;
    background: rgba(255, 255, 255, 0.02);
    border: 1px solid rgba(255, 255, 255, 0.08);
}

.landing-footer {
    padding: 1.25rem 0 2rem;
    color: var(--landing-muted);
}

@media (max-width: 991.98px) {
    .landing-header .navbar-collapse {
        margin-top: 1rem;
        padding: 0.85rem;
        border-radius: 1.2rem;
        border: 1px solid var(--landing-border);
        background: rgba(10, 10, 10, 0.96);
    }

    .landing-display {
        max-width: 12ch;
    }
}

@media (max-width: 767.98px) {
    .landing-section {
        padding: 3rem 0;
    }

    .landing-hero {
        padding-top: 2.5rem;
    }

    .landing-display {
        font-size: clamp(2.5rem, 12vw, 3.8rem);
        max-width: none;
    }

    .landing-lead {
        font-size: 1rem;
        line-height: 1.75;
    }

    .landing-panel,
    .landing-card,
    .landing-cta,
    .landing-summary {
        border-radius: 1.25rem;
    }

    .landing-brand-text {
        font-size: 0.94rem;
    }
}
</style>
