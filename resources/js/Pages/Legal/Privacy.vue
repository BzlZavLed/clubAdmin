<script setup>
import LocaleSwitcher from '@/Components/LocaleSwitcher.vue'
import { Head, Link } from '@inertiajs/vue3'
</script>

<template>
    <Head title="Aviso de Privacidad / Privacy Notice" />

    <main class="min-h-screen bg-slate-100 px-4 py-8 text-slate-800">
        <article class="mx-auto max-w-4xl rounded-xl bg-white p-6 shadow-sm sm:p-10">
            <div class="mb-8 flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 pb-5">
                <div>
                    <h1 class="text-3xl font-bold text-slate-950">Aviso de Privacidad / Privacy Notice</h1>
                    <p class="mt-1 text-sm text-slate-500">Vigente / Effective: August 27, 2026 · Version 2026-08-27</p>
                </div>
                <LocaleSwitcher :compact="true" />
            </div>

            <section class="space-y-5 leading-7">
                <h2 class="text-2xl font-bold text-slate-950">Español</h2>
                <p>Este portal ayuda a iglesias, clubes y organizaciones participantes a administrar cuentas, inscripciones, miembros menores de edad, actividades, comunicaciones y pagos. La organización o club que te invitó administra la información dentro de su ámbito.</p>

                <div>
                    <h3 class="font-bold text-slate-950">Información que podemos recopilar</h3>
                    <p>Datos de la cuenta y contacto del adulto; información de inscripción, contacto de emergencia y datos relacionados con la salud o seguridad del menor; participación, asistencia y progreso; pagos y recibos; documentos, firmas o imágenes que se proporcionen; y registros técnicos, de sesión y seguridad.</p>
                </div>
                <div>
                    <h3 class="font-bold text-slate-950">Cómo la usamos y compartimos</h3>
                    <p>La usamos para crear y proteger cuentas, administrar inscripciones y actividades, atender asuntos de seguridad o emergencia, enviar comunicaciones, procesar y documentar pagos, prevenir fraude y cumplir obligaciones legales. Puede ser accesible para personal autorizado del club, iglesia u organización y para proveedores que ayudan a operar el servicio, o cuando la ley, la seguridad o una autoridad competente lo requieran.</p>
                </div>
                <div>
                    <h3 class="font-bold text-slate-950">Menores de edad y autorización del adulto</h3>
                    <p>Las cuentas deben ser creadas y administradas por adultos. Al proporcionar datos de un menor, declaras que eres su padre, madre o tutor legal, o que cuentas con autorización para hacerlo. Los menores no deben crear cuentas ni proporcionar información directamente sin la participación del adulto responsable.</p>
                </div>
                <div>
                    <h3 class="font-bold text-slate-950">Tus opciones y solicitudes</h3>
                    <p>Tú decides si proporcionas información, aunque algunos datos son necesarios para crear la cuenta o prestar funciones solicitadas. Dependiendo de la ley aplicable, puedes solicitar acceso, corrección o eliminación. La eliminación puede estar limitada cuando sea necesario conservar información por seguridad, prevención de fraude, transacciones, obligaciones legales o gestión legítima de registros.</p>
                    <p class="mt-2">Puedes usar las opciones de eliminación disponibles en <strong>Perfil</strong> o comunicarte con el director del club o con la organización que te invitó. Para proteger la cuenta, podremos verificar tu identidad y autoridad antes de completar una solicitud.</p>
                </div>
                <div>
                    <h3 class="font-bold text-slate-950">Conservación y seguridad</h3>
                    <p>La información se conserva solamente durante el tiempo razonablemente necesario para los fines descritos y las obligaciones aplicables. Se emplean medidas administrativas, técnicas y organizativas razonables, pero ningún sistema puede garantizar seguridad absoluta.</p>
                </div>
            </section>

            <hr class="my-10 border-slate-300" />

            <section class="space-y-5 leading-7">
                <h2 class="text-2xl font-bold text-slate-950">English</h2>
                <p>This portal helps participating churches, clubs, and organizations administer accounts, enrollment, minor members, activities, communications, and payments. The organization or club that invited you administers information within its scope.</p>

                <div>
                    <h3 class="font-bold text-slate-950">Information we may collect</h3>
                    <p>Adult account and contact information; minor enrollment, emergency-contact, and health- or safety-related information; participation, attendance, and progress; payments and receipts; documents, signatures, or images that are provided; and technical, session, and security logs.</p>
                </div>
                <div>
                    <h3 class="font-bold text-slate-950">How we use and share it</h3>
                    <p>We use it to create and protect accounts, administer enrollment and activities, address safety or emergencies, send communications, process and document payments, prevent fraud, and meet legal obligations. It may be accessible to authorized club, church, or organization personnel and service providers that help operate the service, or when required by law, safety needs, or a competent authority.</p>
                </div>
                <div>
                    <h3 class="font-bold text-slate-950">Minors and adult authority</h3>
                    <p>Accounts must be created and managed by adults. By providing a minor's information, you represent that you are the minor's parent or legal guardian, or that you are otherwise authorized to provide it. Minors should not create accounts or provide information directly without the responsible adult's involvement.</p>
                </div>
                <div>
                    <h3 class="font-bold text-slate-950">Your choices and requests</h3>
                    <p>You decide whether to provide information, although some information is required to create an account or provide requested features. Depending on applicable law, you may request access, correction, or deletion. Deletion may be limited when retaining information is necessary for safety, fraud prevention, transactions, legal obligations, or legitimate records administration.</p>
                    <p class="mt-2">You may use the deletion options available under <strong>Profile</strong> or contact the club director or organization that invited you. To protect the account, we may verify your identity and authority before completing a request.</p>
                </div>
                <div>
                    <h3 class="font-bold text-slate-950">Retention and security</h3>
                    <p>Information is retained only for as long as reasonably necessary for the purposes described and applicable obligations. Reasonable administrative, technical, and organizational safeguards are used, but no system can guarantee absolute security.</p>
                </div>
            </section>

            <div class="mt-10 border-t border-slate-200 pt-6 text-center">
                <Link :href="route('login')" class="font-semibold text-blue-700 underline">Volver a iniciar sesión / Return to login</Link>
            </div>
        </article>
    </main>
</template>
