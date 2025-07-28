
<script setup>
import { onMounted, ref, nextTick } from 'vue'
import html2pdf from 'html2pdf.js'

const props = defineProps({
    report: Object,
    disableAutoDownload: Boolean
})
const pdfArea = ref(null)
const check = (val) => val ? '✓' : ''
const formatDate = (date) => new Date(date).toLocaleDateString()
const emit = defineEmits(['pdf-done'])
onMounted(async () => {
    

    if (!props.disableAutoDownload) {
        await nextTick()
        if (pdfArea.value) {
            html2pdf()
                .from(pdfArea.value)
                .set({
                    margin: 0.5,
                    filename: `report-${props.report.id}.pdf`,
                    html2canvas: { scale: 2 },
                    jsPDF: { unit: 'in', format: 'letter', orientation: 'portrait' }
                })
                .save()
                .then(() => {
                    emit('pdf-done')
                })
                .catch((err) => {
                    console.log(err)
                    console.error('PDF generation failed:', err)
                })
        }
    }
})
</script><template>
    <div ref="pdfArea" class="pdf-container">
        <!-- Watermark Club Name 
        <div class="watermark">{{ report.club?.club_name }}</div>-->

        <!-- Header -->
        <div class="header">
            <h1>Assistance Report</h1>
            <p class="club-name"><strong>Club:</strong> {{ report.club?.club_name }}</p>
            <p><strong>Class:</strong> {{ report.class_name }}</p>
            <p><strong>Staff:</strong> {{ report.staff_name }}</p>
            <p><strong>Date:</strong> {{ formatDate(report.date) }}</p>
            <p><strong>Month:</strong> {{ report.month }} | <strong>Year:</strong> {{ report.year }}</p>
        </div>

        <!-- Report Table -->
        <table class="report-table">
            <thead>
                <tr>
                    <th>Adventurer</th>
                    <th>Asistencia</th>
                    <th>Puntualidad</th>
                    <th>Uniforme</th>
                    <th>Conductor</th>
                    <th>Cuota</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(merit, i) in report.merits" :key="i">
                    <td>{{ merit.mem_adv_name }}</td>
                    <td>{{ check(merit.asistencia) }}</td>
                    <td>{{ check(merit.puntualidad) }}</td>
                    <td>{{ check(merit.uniforme) }}</td>
                    <td>{{ check(merit.conductor) }}</td>
                    <td>{{ check(merit.cuota) }}</td>
                    <td>{{ merit.total }}</td>
                </tr>
            </tbody>
        </table>
        
    </div>
</template>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700&display=swap');

.pdf-container {
    font-family: 'Nunito', sans-serif;
    position: relative;
    padding: 2rem;
    background: white;
    color: #333;
}

/* Watermark */
.watermark {
    position: absolute;
    top: 40%;
    left: 20%;
    font-size: 3rem;
    color: rgba(200, 200, 200, 0.2);
    transform: rotate(-30deg);
    white-space: nowrap;
    pointer-events: none;
    z-index: 0;
}

.header {
    z-index: 1;
    position: relative;
    margin-bottom: 1.5rem;
}

.header h1 {
    font-size: 1.75rem;
    margin-bottom: 0.5rem;
    color: #1a202c;
}

.header p {
    margin: 0.25rem 0;
}

.club-name {
    font-size: 1.1rem;
    color: #2563eb;
}

/* Table Styling */
.report-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 1rem;
    font-size: 0.9rem;
}

.report-table th,
.report-table td {
    border: 1px solid #ccc;
    padding: 0.4rem 0.6rem;
    text-align: center;
}

.report-table th {
    background-color: #f1f5f9;
    font-weight: 600;
    color: #374151;
}
</style>