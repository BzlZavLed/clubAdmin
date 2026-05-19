<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import {
    ArrowDownTrayIcon,
    ArrowPathIcon,
    BanknotesIcon,
    ChartBarIcon,
    CheckCircleIcon,
    ClipboardDocumentIcon,
    ClipboardDocumentListIcon,
    CurrencyDollarIcon,
    PlusIcon,
    QuestionMarkCircleIcon,
    ShoppingBagIcon,
    ShoppingCartIcon,
    TrashIcon,
    UserGroupIcon,
    XMarkIcon,
} from '@heroicons/vue/24/outline'
import {
    closeFinanceEngineFundraiserEvent,
    createFinanceEngineFundraiserPartner,
    createFinanceEngineFundraiserEvent,
    createFinanceEngineFundraiserProduct,
    createFinanceEngineFundraiserSale,
    fetchFinanceEngineFundraisers,
    recordFinanceEngineFundraiserPartnerContribution,
    recordFinanceEngineFundraiserPartnerDistribution,
    updateFinanceEngineFundraiserProduct,
} from '@/Services/api'
import { useGeneral } from '@/Composables/useGeneral'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    auth_user: { type: Object, required: true },
})

const { showToast } = useGeneral()
const { tr } = useLocale()

const today = () => new Date().toISOString().slice(0, 10)

const loading = ref(false)
const refreshing = ref(false)
const savingEvent = ref(false)
const closingEvent = ref(false)
const savingPartner = ref(false)
const savingPartnerTransfer = ref(null)
const savingProduct = ref(false)
const savingProductEdit = ref(false)
const savingSale = ref(false)
const loadError = ref('')
const eventErrors = ref({})
const closeErrors = ref({})
const partnerErrors = ref({})
const partnerTransferErrors = ref({})
const productErrors = ref({})
const productEditErrors = ref({})
const saleErrors = ref({})
const selectedClubId = ref(null)
const currentClub = ref(null)
const clubs = ref([])
const partnerClubs = ref([])
const accounts = ref([])
const accountBalances = ref([])
const events = ref([])
const paymentTypes = ref(['cash', 'zelle', 'check', 'transfer'])
const selectedEventId = ref(null)
const editingProductId = ref(null)
const cajaSection = ref(null)
const receiptPreviewSale = ref(null)
const showFundraiserGuide = ref(false)
const showCloseModal = ref(false)

const eventForm = ref({
    name: '',
    fundraiser_type: 'food',
    event_date: today(),
    pay_to: 'club_budget',
    investment_total: '',
    investment_pay_to: 'club_budget',
    investment_funds_location: 'cash',
    investment_receipt_image: null,
    partner_club_id: '',
    partner_investment_share_percent: '',
    partner_earnings_share_percent: '',
    partner_notes: '',
    description: '',
})

const productForm = ref({
    name: '',
    sale_price: '',
    unit_cost: '',
    investment_amount: '',
    investment_pay_to: 'club_budget',
    investment_funds_location: 'cash',
    receipt_image: null,
    tracks_inventory: false,
    quantity_available: '',
    description: '',
    is_active: true,
})
const productEditForm = ref(null)
const partnerTransferForms = ref({})

const partnerForm = ref({
    partner_club_id: '',
    investment_share_percent: '',
    earnings_share_percent: '',
    notes: '',
})

const closeForm = ref({
    close_date: today(),
    funds_location: 'cash',
    payment_type: 'cash',
    notes: '',
})

const saleForm = ref({
    customer_name: '',
    sale_date: today(),
    payment_type: 'cash',
    zelle_phone: '',
    notes: '',
    items: [
        { fundraiser_product_id: '', quantity: 1, unit_price: '' },
    ],
})

const canSelectClub = computed(() => props.auth_user?.profile_type === 'superadmin' || clubs.value.length > 1)
const activeClubName = computed(() => currentClub.value?.club_name || clubs.value.find((club) => Number(club.id) === Number(selectedClubId.value))?.club_name || '—')
const operatingAccounts = computed(() => {
    const rows = (accounts.value || [])
        .filter((account) => account.pay_to !== 'reimbursement_to')
        .map((account) => ({
            value: account.pay_to,
            label: account.label || account.pay_to,
        }))

    if (!rows.some((account) => account.value === 'club_budget')) {
        rows.unshift({ value: 'club_budget', label: tr('Presupuesto del club', 'Club budget') })
    }

    return rows
})
const selectedEvent = computed(() => events.value.find((event) => Number(event.id) === Number(selectedEventId.value)) || events.value[0] || null)
const selectedEventPartners = computed(() => selectedEvent.value?.partners || [])
const selectedEventIsClosed = computed(() => selectedEvent.value?.status === 'closed')
const selectedEventPartnerDistributionTotal = computed(() => selectedEventPartners.value.reduce(
    (total, partner) => roundCurrency(total + Number(partner.earnings_due || 0)),
    0,
))
const selectedEventHasPendingPartnerContributions = computed(() => selectedEventPartners.value.some((partner) => Number(partner.contribution_pending || 0) > 0))
const eventPartnerClubOptions = computed(() => partnerClubs.value || [])
const partnerClubOptions = computed(() => {
    const linkedClubIds = new Set(selectedEventPartners.value.map((partner) => Number(partner.partner_club_id)))

    return (partnerClubs.value || []).filter((club) => !linkedClubIds.has(Number(club.id)))
})
const selectedEventProducts = computed(() => selectedEvent.value?.products || [])
const activeProducts = computed(() => selectedEventProducts.value.filter((product) => product.is_active))
const productById = computed(() => new Map(selectedEventProducts.value.map((product) => [Number(product.id), product])))

const saleRows = computed(() => saleForm.value.items.map((item) => {
    const product = productById.value.get(Number(item.fundraiser_product_id))
    const quantity = Math.max(Number(item.quantity || 0), 0)
    const unitPrice = item.unit_price === '' || item.unit_price === null || item.unit_price === undefined
        ? Number(product?.sale_price || 0)
        : Number(item.unit_price || 0)
    const unitCost = Number(product?.unit_cost || 0)
    const lineTotal = roundCurrency(quantity * unitPrice)
    const lineCost = roundCurrency(quantity * unitCost)

    return {
        product,
        quantity,
        unitPrice,
        unitCost,
        lineTotal,
        lineCost,
        lineGain: roundCurrency(lineTotal - lineCost),
    }
}))
const saleTotals = computed(() => saleRows.value.reduce((totals, row) => ({
    total: roundCurrency(totals.total + row.lineTotal),
    cost: roundCurrency(totals.cost + row.lineCost),
    gain: roundCurrency(totals.gain + row.lineGain),
}), { total: 0, cost: 0, gain: 0 }))
const formatMoney = (value) => {
    const amount = Number(value || 0)

    return `${amount < 0 ? '-' : ''}$${Math.abs(amount).toFixed(2)}`
}
const formatDate = (value) => value ? String(value).slice(0, 10) : '—'
const roundCurrency = (value) => Math.round((Number(value || 0) + Number.EPSILON) * 100) / 100
const saleItemLineTotal = (item) => roundCurrency(item?.line_total ?? (Number(item?.quantity || 0) * Number(item?.unit_price || 0)))
const normalizeErrors = (error) => {
    const errors = error?.response?.data?.errors || {}
    return Object.fromEntries(Object.entries(errors).map(([key, value]) => [key, Array.isArray(value) ? value[0] : value]))
}
const actionErrorMessage = (error, fallback) => error?.response?.data?.message || fallback
const firstError = (errors, key) => errors?.[key] || null
const fundraiserTypeLabel = (type) => {
    if (type === 'food') return tr('Comida / menú', 'Food / menu')
    if (type === 'products') return tr('Productos', 'Products')
    return tr('Otro', 'Other')
}
const paymentTypeLabel = (type) => {
    if (type === 'cash') return tr('Efectivo', 'Cash')
    if (type === 'zelle') return 'Zelle'
    if (type === 'check') return tr('Cheque', 'Check')
    if (type === 'transfer') return tr('Transferencia', 'Transfer')
    return type
}
const locationLabel = (location) => location === 'bank' ? tr('Banco', 'Bank') : tr('Efectivo', 'Cash')
const partnerTransferKey = (partner, type) => `${type}_${partner?.id || 'new'}`
const partnerTransferForm = (partner, type) => {
    const key = partnerTransferKey(partner, type)

    if (!partnerTransferForms.value[key]) {
        partnerTransferForms.value[key] = {
            transfer_date: today(),
            funds_location: 'cash',
            payment_type: 'transfer',
        }
    }

    return partnerTransferForms.value[key]
}
const partnerTransferError = (partner, type) => partnerTransferErrors.value[partnerTransferKey(partner, type)] || null
const productQuantityLabel = (product) => {
    if (product?.quantity_available === null || product?.quantity_available === undefined) {
        return tr('Sin cantidad', 'No quantity')
    }

    const sold = Number(product.quantity_sold || 0)
    const planned = Number(product.quantity_available || 0)

    return sold > 0
        ? `${tr('Plan', 'Plan')}: ${planned} · ${tr('Vendido', 'Sold')}: ${sold}`
        : `${planned}`
}
const foodQuantityExtensionMessage = (item) => {
    if (selectedEvent.value?.fundraiser_type !== 'food') return ''

    const product = productById.value.get(Number(item.fundraiser_product_id))
    if (!product || product.quantity_available === null || product.quantity_available === undefined) return ''

    const quantity = Number(item.quantity || 0)
    const remaining = Math.max(Number(product.quantity_available || 0) - Number(product.quantity_sold || 0), 0)

    return quantity > remaining
        ? tr('Se asumira que hay ingredientes para continuar y se actualizara la cantidad planeada.', 'Ingredients will be assumed available and the planned quantity will be updated.')
        : ''
}
const accountBalanceFor = (payTo, location = 'cash') => {
    const row = accountBalances.value.find((account) => account.account === payTo)

    return Number(row?.[`${location || 'cash'}_balance`] || 0)
}
const accountTotalBalanceFor = (payTo) => {
    const row = accountBalances.value.find((account) => account.account === payTo)

    return Number(row?.total_available ?? (Number(row?.cash_balance || 0) + Number(row?.bank_balance || 0)))
}
const productFormTemplate = (overrides = {}) => ({
    name: '',
    sale_price: '',
    unit_cost: 0,
    investment_amount: 0,
    investment_pay_to: selectedEvent.value?.pay_to || operatingAccounts.value[0]?.value || 'club_budget',
    investment_funds_location: 'cash',
    receipt_image: null,
    tracks_inventory: false,
    quantity_available: '',
    description: '',
    is_active: true,
    ...overrides,
})
const productEditTemplate = (product) => productFormTemplate({
    name: product?.name || '',
    sale_price: product?.sale_price ?? '',
    unit_cost: product?.unit_cost ?? 0,
    investment_amount: product?.investment_amount ?? 0,
    tracks_inventory: Boolean(product?.tracks_inventory),
    quantity_available: product?.quantity_available ?? '',
    description: product?.description || '',
    is_active: Boolean(product?.is_active ?? true),
})
const selectedEventInvestmentBalance = computed(() => accountBalanceFor(
    eventForm.value.investment_pay_to,
    eventForm.value.investment_funds_location,
))
const selectedEventInvestmentTotalBalance = computed(() => accountTotalBalanceFor(eventForm.value.investment_pay_to))
const eventInvestmentTransferAmount = computed(() => Math.max(
    Math.min(
        roundCurrency(Number(eventForm.value.investment_total || 0) - selectedEventInvestmentBalance.value),
        roundCurrency(selectedEventInvestmentTotalBalance.value - selectedEventInvestmentBalance.value),
    ),
    0,
))
const eventInvestmentShortfall = computed(() => Math.max(
    roundCurrency(Number(eventForm.value.investment_total || 0) - selectedEventInvestmentTotalBalance.value),
    0,
))

const applyData = (response) => {
    const payload = response?.data || response || {}

    currentClub.value = payload.club || null
    clubs.value = payload.clubs || []
    partnerClubs.value = payload.partner_clubs || []
    accounts.value = payload.accounts || []
    accountBalances.value = payload.account_balances || []
    events.value = payload.events || []
    paymentTypes.value = payload.payment_types || paymentTypes.value

    if (!selectedClubId.value && currentClub.value?.id) {
        selectedClubId.value = currentClub.value.id
    }

    if (!eventForm.value.pay_to || !operatingAccounts.value.some((account) => account.value === eventForm.value.pay_to)) {
        eventForm.value.pay_to = operatingAccounts.value[0]?.value || 'club_budget'
    }

    if (!selectedEventId.value || !events.value.some((event) => Number(event.id) === Number(selectedEventId.value))) {
        selectedEventId.value = events.value[0]?.id || null
    }
}

const loadFundraisers = async () => {
    loading.value = true
    loadError.value = ''

    try {
        const response = await fetchFinanceEngineFundraisers(selectedClubId.value)
        applyData(response.data)
    } catch (error) {
        loadError.value = actionErrorMessage(error, tr('No se pudo cargar fundraisers.', 'Could not load fundraisers.'))
        console.error(error)
    } finally {
        loading.value = false
    }
}

const refreshFundraisers = async () => {
    refreshing.value = true

    try {
        const response = await fetchFinanceEngineFundraisers(selectedClubId.value)
        applyData(response.data)
    } finally {
        refreshing.value = false
    }
}

const scrollToCaja = () => {
    cajaSection.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

const openReceiptPreview = (sale) => {
    if (!sale?.receipt?.qr_url) return

    receiptPreviewSale.value = sale
}

const closeReceiptPreview = () => {
    receiptPreviewSale.value = null
}

const openFundraiserGuide = () => {
    showFundraiserGuide.value = true
}

const closeFundraiserGuide = () => {
    showFundraiserGuide.value = false
}

const openCloseModal = () => {
    if (!selectedEvent.value || selectedEventIsClosed.value) return

    closeErrors.value = {}
    closeForm.value = {
        close_date: today(),
        funds_location: 'cash',
        payment_type: 'cash',
        notes: '',
    }
    showCloseModal.value = true
}

const closeCloseModal = () => {
    if (closingEvent.value) return

    showCloseModal.value = false
    closeErrors.value = {}
}

const copyKitchenUrl = async () => {
    if (!selectedEvent.value?.kitchen_url) return

    try {
        if (navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(selectedEvent.value.kitchen_url)
        } else {
            const input = document.createElement('textarea')
            input.value = selectedEvent.value.kitchen_url
            input.setAttribute('readonly', 'readonly')
            input.style.position = 'fixed'
            input.style.opacity = '0'
            document.body.appendChild(input)
            input.select()
            document.execCommand('copy')
            document.body.removeChild(input)
        }
        showToast(tr('Enlace de cocina copiado.', 'Kitchen link copied.'), 'success')
    } catch (error) {
        showToast(tr('No se pudo copiar el enlace.', 'Could not copy the link.'), 'error')
        console.error(error)
    }
}

const resetEventForm = () => {
    eventForm.value = {
        name: '',
        fundraiser_type: 'food',
        event_date: today(),
        pay_to: operatingAccounts.value[0]?.value || 'club_budget',
        investment_total: '',
        investment_pay_to: operatingAccounts.value[0]?.value || 'club_budget',
        investment_funds_location: 'cash',
        investment_receipt_image: null,
        partner_club_id: '',
        partner_investment_share_percent: '',
        partner_earnings_share_percent: '',
        partner_notes: '',
        description: '',
    }
}

const resetProductForm = () => {
    productForm.value = productFormTemplate()
}

const resetPartnerForm = () => {
    partnerForm.value = {
        partner_club_id: '',
        investment_share_percent: '',
        earnings_share_percent: '',
        notes: '',
    }
}

const resetSaleForm = () => {
    saleForm.value = {
        customer_name: '',
        sale_date: today(),
        payment_type: 'cash',
        zelle_phone: '',
        notes: '',
        items: [
            { fundraiser_product_id: activeProducts.value[0]?.id || '', quantity: 1, unit_price: '' },
        ],
    }
}

const onClubChange = async () => {
    selectedEventId.value = null
    await loadFundraisers()
    resetEventForm()
    resetPartnerForm()
    resetProductForm()
    resetSaleForm()
}

const submitEvent = async () => {
    savingEvent.value = true
    eventErrors.value = {}

    try {
        const response = await createFinanceEngineFundraiserEvent({
            ...eventForm.value,
            club_id: selectedClubId.value,
        })
        applyData(response.data)
        selectedEventId.value = response.event?.id || selectedEventId.value
        resetEventForm()
        showToast(tr('Fundraiser creado.', 'Fundraiser created.'), 'success')
    } catch (error) {
        eventErrors.value = normalizeErrors(error)
        showToast(actionErrorMessage(error, tr('No se pudo crear el fundraiser.', 'Could not create fundraiser.')), 'error')
        console.error(error)
    } finally {
        savingEvent.value = false
    }
}

const submitPartner = async () => {
    if (!selectedEvent.value) return

    savingPartner.value = true
    partnerErrors.value = {}

    try {
        const response = await createFinanceEngineFundraiserPartner(selectedEvent.value.id, partnerForm.value)
        applyData(response.data)
        resetPartnerForm()
        showToast(tr('Club asociado guardado.', 'Partner club saved.'), 'success')
    } catch (error) {
        partnerErrors.value = normalizeErrors(error)
        showToast(actionErrorMessage(error, tr('No se pudo asociar el club.', 'Could not add partner club.')), 'error')
        console.error(error)
    } finally {
        savingPartner.value = false
    }
}

const submitProduct = async () => {
    if (!selectedEvent.value) return

    savingProduct.value = true
    productErrors.value = {}

    try {
        const response = await createFinanceEngineFundraiserProduct(selectedEvent.value.id, productForm.value)
        applyData(response.data)
        resetProductForm()
        showToast(tr('Producto guardado.', 'Product saved.'), 'success')
    } catch (error) {
        productErrors.value = normalizeErrors(error)
        showToast(actionErrorMessage(error, tr('No se pudo guardar el producto.', 'Could not save product.')), 'error')
        console.error(error)
    } finally {
        savingProduct.value = false
    }
}

const startProductEdit = (product) => {
    editingProductId.value = product.id
    productEditErrors.value = {}
    productEditForm.value = productEditTemplate(product)
}

const cancelProductEdit = () => {
    editingProductId.value = null
    productEditForm.value = null
    productEditErrors.value = {}
}

const submitProductEdit = async (product) => {
    if (!product || !productEditForm.value) return

    savingProductEdit.value = true
    productEditErrors.value = {}

    try {
        const response = await updateFinanceEngineFundraiserProduct(product.id, productEditForm.value)
        applyData(response.data)
        cancelProductEdit()
        showToast(tr('Producto actualizado.', 'Product updated.'), 'success')
    } catch (error) {
        productEditErrors.value = normalizeErrors(error)
        showToast(actionErrorMessage(error, tr('No se pudo actualizar el producto.', 'Could not update product.')), 'error')
        console.error(error)
    } finally {
        savingProductEdit.value = false
    }
}

const onEventInvestmentReceiptChange = (event) => {
    eventForm.value.investment_receipt_image = event.target.files?.[0] || null
}

const submitSale = async () => {
    if (!selectedEvent.value) return

    savingSale.value = true
    saleErrors.value = {}

    try {
        const response = await createFinanceEngineFundraiserSale(selectedEvent.value.id, saleForm.value)
        applyData(response.data)
        resetSaleForm()
        showToast(tr('Venta registrada con recibo.', 'Sale recorded with receipt.'), 'success')
    } catch (error) {
        saleErrors.value = normalizeErrors(error)
        showToast(actionErrorMessage(error, tr('No se pudo registrar la venta.', 'Could not record sale.')), 'error')
        console.error(error)
    } finally {
        savingSale.value = false
    }
}

const submitCloseEvent = async () => {
    if (!selectedEvent.value) return

    closingEvent.value = true
    closeErrors.value = {}

    try {
        const response = await closeFinanceEngineFundraiserEvent(selectedEvent.value.id, closeForm.value)
        applyData(response.data)
        showCloseModal.value = false
        closeErrors.value = {}
        showToast(tr('Fundraiser cerrado.', 'Fundraiser closed.'), 'success')
    } catch (error) {
        closeErrors.value = normalizeErrors(error)
        showToast(actionErrorMessage(error, tr('No se pudo cerrar el fundraiser.', 'Could not close fundraiser.')), 'error')
        console.error(error)
    } finally {
        closingEvent.value = false
    }
}

const recordPartnerTransfer = async (partner, type) => {
    const key = partnerTransferKey(partner, type)
    const isContribution = type === 'contribution'
    const apiCall = isContribution
        ? recordFinanceEngineFundraiserPartnerContribution
        : recordFinanceEngineFundraiserPartnerDistribution

    savingPartnerTransfer.value = key
    partnerTransferErrors.value = { ...partnerTransferErrors.value, [key]: null }

    try {
        const response = await apiCall(partner.id, partnerTransferForm(partner, type))
        applyData(response.data)
        showToast(
            isContribution
                ? tr('Aporte asociado registrado.', 'Partner contribution recorded.')
                : tr('Ganancia asociada transferida.', 'Partner earnings transferred.'),
            'success'
        )
    } catch (error) {
        partnerTransferErrors.value = {
            ...partnerTransferErrors.value,
            [key]: actionErrorMessage(error, tr('No se pudo registrar el traslado.', 'Could not record transfer.')),
        }
        showToast(actionErrorMessage(error, tr('No se pudo registrar el traslado.', 'Could not record transfer.')), 'error')
        console.error(error)
    } finally {
        savingPartnerTransfer.value = null
    }
}

const addSaleItem = () => {
    saleForm.value.items.push({ fundraiser_product_id: activeProducts.value[0]?.id || '', quantity: 1, unit_price: '' })
}

const removeSaleItem = (index) => {
    if (saleForm.value.items.length === 1) {
        saleForm.value.items = [{ fundraiser_product_id: '', quantity: 1, unit_price: '' }]
        return
    }

    saleForm.value.items.splice(index, 1)
}

watch(selectedEventId, () => {
    cancelProductEdit()
    closeErrors.value = {}
    showCloseModal.value = false
    partnerErrors.value = {}
    partnerTransferErrors.value = {}
    resetProductForm()
    resetPartnerForm()
    resetSaleForm()
})

onMounted(() => loadFundraisers())
</script>

<template>
    <PathfinderLayout>
        <template #title>{{ tr('Fundraisers', 'Fundraisers') }}</template>

        <div class="space-y-5">
            <section class="border-b border-gray-200 pb-4">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p class="text-sm font-medium uppercase tracking-wide text-gray-500">{{ tr('Modulo financiero', 'Finance module') }}</p>
                        <h2 class="mt-1 text-xl font-semibold text-gray-900">{{ tr('Ventas para recaudar fondos', 'Fundraiser sales') }}</h2>
                        <p class="mt-1 text-sm text-gray-600">
                            {{ tr('Agrupa ventas por fundraiser, registra costos e inventario opcional, y envia cada ingreso al libro financiero.', 'Group sales by fundraiser, track costs and optional inventory, and post each income to the finance ledger.') }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-2 sm:min-w-64">
                        <label v-if="canSelectClub" class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                            {{ tr('Club activo', 'Active club') }}
                        </label>
                        <select
                            v-if="canSelectClub"
                            v-model="selectedClubId"
                            @change="onClubChange"
                            class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                        >
                            <option v-for="club in clubs" :key="club.id" :value="club.id">{{ club.club_name }}</option>
                        </select>
                        <div v-else class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-gray-800">
                            {{ activeClubName }}
                        </div>
                    </div>
                </div>
            </section>

            <div v-if="loadError" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                {{ loadError }}
            </div>

            <div class="flex justify-end gap-2">
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-800 hover:bg-blue-100"
                    @click="openFundraiserGuide"
                >
                    <QuestionMarkCircleIcon class="h-4 w-4" />
                    {{ tr('Guia', 'Guide') }}
                </button>
                <a
                    v-if="selectedEvent?.fundraiser_type === 'food' && selectedEvent.kitchen_url"
                    :href="selectedEvent.kitchen_url"
                    target="_blank"
                    rel="noopener"
                    class="inline-flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-800 hover:bg-emerald-100"
                >
                    <ShoppingBagIcon class="h-4 w-4" />
                    {{ tr('Cocina', 'Kitchen') }}
                </a>
                <button
                    v-if="selectedEvent?.fundraiser_type === 'food' && selectedEvent.kitchen_url"
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg border border-emerald-200 bg-white px-3 py-2 text-sm font-semibold text-emerald-800 hover:bg-emerald-50"
                    @click="copyKitchenUrl"
                >
                    <ClipboardDocumentIcon class="h-4 w-4" />
                    {{ tr('Copiar enlace', 'Copy link') }}
                </button>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-60"
                    :disabled="!selectedEvent"
                    @click="scrollToCaja"
                >
                    <CurrencyDollarIcon class="h-4 w-4" />
                    {{ tr('Caja', 'Register') }}
                </button>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-60"
                    :disabled="loading || refreshing"
                    @click="refreshFundraisers"
                >
                    <ArrowPathIcon class="h-4 w-4" :class="{ 'animate-spin': refreshing }" />
                    {{ tr('Actualizar', 'Refresh') }}
                </button>
            </div>

            <section class="grid gap-4 xl:grid-cols-[minmax(0,1.1fr)_minmax(360px,0.9fr)]">
                <form class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm" @submit.prevent="submitEvent">
                    <div class="mb-4 flex items-center gap-2">
                        <ShoppingCartIcon class="h-5 w-5 text-emerald-600" />
                        <h3 class="text-base font-semibold text-gray-900">{{ tr('Registrar fundraiser', 'Register fundraiser') }}</h3>
                    </div>

                    <div class="grid gap-3 md:grid-cols-2">
                        <div>
                            <label class="text-sm font-medium text-gray-700">{{ tr('Nombre', 'Name') }}</label>
                            <input
                                v-model="eventForm.name"
                                type="text"
                                class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                                placeholder="Venta de comida"
                            >
                            <p v-if="firstError(eventErrors, 'name')" class="mt-1 text-xs text-rose-600">{{ firstError(eventErrors, 'name') }}</p>
                        </div>

                        <div>
                            <label class="text-sm font-medium text-gray-700">{{ tr('Tipo', 'Type') }}</label>
                            <select v-model="eventForm.fundraiser_type" class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500">
                                <option value="food">{{ tr('Comida / menú', 'Food / menu') }}</option>
                                <option value="products">{{ tr('Productos', 'Products') }}</option>
                                <option value="other">{{ tr('Otro', 'Other') }}</option>
                            </select>
                        </div>

                        <div>
                            <label class="text-sm font-medium text-gray-700">{{ tr('Fecha', 'Date') }}</label>
                            <input v-model="eventForm.event_date" type="date" class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500">
                        </div>

                        <div>
                            <label class="text-sm font-medium text-gray-700">{{ tr('Cuenta destino', 'Destination account') }}</label>
                            <select v-model="eventForm.pay_to" class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500">
                                <option v-for="account in operatingAccounts" :key="account.value" :value="account.value">{{ account.label }}</option>
                            </select>
                            <p v-if="firstError(eventErrors, 'pay_to')" class="mt-1 text-xs text-rose-600">{{ firstError(eventErrors, 'pay_to') }}</p>
                        </div>

                        <div>
                            <label class="text-sm font-medium text-gray-700">{{ tr('Inversion general', 'General investment') }}</label>
                            <input v-model="eventForm.investment_total" type="number" min="0" step="0.01" class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500">
                            <p v-if="firstError(eventErrors, 'investment_total')" class="mt-1 text-xs text-rose-600">{{ firstError(eventErrors, 'investment_total') }}</p>
                        </div>

                        <div v-if="Number(eventForm.investment_total || 0) > 0">
                            <label class="text-sm font-medium text-gray-700">{{ tr('Cuenta de inversion', 'Investment account') }}</label>
                            <select v-model="eventForm.investment_pay_to" class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500">
                                <option v-for="account in operatingAccounts" :key="account.value" :value="account.value">{{ account.label }}</option>
                            </select>
                        </div>

                        <div v-if="Number(eventForm.investment_total || 0) > 0">
                            <label class="text-sm font-medium text-gray-700">{{ tr('Origen', 'Source') }}</label>
                            <select v-model="eventForm.investment_funds_location" class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500">
                                <option value="cash">{{ tr('Efectivo', 'Cash') }}</option>
                                <option value="bank">{{ tr('Banco', 'Bank') }}</option>
                            </select>
                            <p class="mt-1 text-xs text-gray-500">
                                {{ tr('Disponible en origen', 'Available in origin') }}: {{ formatMoney(selectedEventInvestmentBalance) }}
                                · {{ tr('Cuenta total', 'Account total') }}: {{ formatMoney(selectedEventInvestmentTotalBalance) }}
                            </p>
                            <p v-if="eventInvestmentTransferAmount > 0" class="mt-1 text-xs font-medium text-blue-700">
                                {{ tr('Se registrara transferencia interna hacia', 'Internal transfer will be recorded to') }} {{ locationLabel(eventForm.investment_funds_location) }} {{ tr('por', 'for') }} {{ formatMoney(eventInvestmentTransferAmount) }}.
                            </p>
                            <p v-if="eventInvestmentShortfall > 0" class="mt-1 text-xs font-medium text-amber-700">
                                {{ tr('Se creara reembolso pendiente por', 'Pending reimbursement will be created for') }} {{ formatMoney(eventInvestmentShortfall) }}.
                            </p>
                        </div>

                        <div v-if="Number(eventForm.investment_total || 0) > 0">
                            <label class="text-sm font-medium text-gray-700">{{ tr('Comprobante de inversion', 'Investment proof') }}</label>
                            <input type="file" accept="image/*" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm file:mr-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-1 file:text-sm file:font-semibold file:text-gray-700" @change="onEventInvestmentReceiptChange">
                        </div>

                        <div v-if="eventPartnerClubOptions.length > 0" class="md:col-span-2 rounded-lg border border-blue-100 bg-blue-50/60 p-3">
                            <div class="mb-3">
                                <h4 class="text-sm font-semibold text-gray-900">{{ tr('Club asociado inicial', 'Initial partner club') }}</h4>
                                <p class="mt-1 text-xs text-gray-600">
                                    {{ tr('Opcional. Si otro club aportara a la inversion, agregalo desde la creacion para registrar su aporte contra este fundraiser.', 'Optional. If another club will contribute to the investment, add it during creation so its contribution is recorded against this fundraiser.') }}
                                </p>
                            </div>
                            <div class="grid gap-3 md:grid-cols-[minmax(0,1.3fr)_120px_120px_minmax(0,1fr)]">
                                <div>
                                    <label class="text-sm font-medium text-gray-700">{{ tr('Club asociado', 'Partner club') }}</label>
                                    <select v-model="eventForm.partner_club_id" class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500">
                                        <option value="">{{ tr('Sin asociado inicial', 'No initial partner') }}</option>
                                        <option v-for="club in eventPartnerClubOptions" :key="club.id" :value="club.id">{{ club.club_name }}</option>
                                    </select>
                                    <p v-if="firstError(eventErrors, 'partner_club_id')" class="mt-1 text-xs text-rose-600">{{ firstError(eventErrors, 'partner_club_id') }}</p>
                                </div>
                                <div>
                                    <label class="text-sm font-medium text-gray-700">{{ tr('% inversion', '% investment') }}</label>
                                    <input v-model="eventForm.partner_investment_share_percent" type="number" min="0" max="100" step="0.01" class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500">
                                    <p v-if="firstError(eventErrors, 'partner_investment_share_percent')" class="mt-1 text-xs text-rose-600">{{ firstError(eventErrors, 'partner_investment_share_percent') }}</p>
                                </div>
                                <div>
                                    <label class="text-sm font-medium text-gray-700">{{ tr('% recaudado', '% raised') }}</label>
                                    <input v-model="eventForm.partner_earnings_share_percent" type="number" min="0" max="100" step="0.01" class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500">
                                    <p v-if="firstError(eventErrors, 'partner_earnings_share_percent')" class="mt-1 text-xs text-rose-600">{{ firstError(eventErrors, 'partner_earnings_share_percent') }}</p>
                                </div>
                                <div>
                                    <label class="text-sm font-medium text-gray-700">{{ tr('Notas', 'Notes') }}</label>
                                    <input v-model="eventForm.partner_notes" type="text" class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500">
                                </div>
                            </div>
                        </div>

                        <div class="md:col-span-2">
                            <label class="text-sm font-medium text-gray-700">{{ tr('Notas', 'Notes') }}</label>
                            <textarea v-model="eventForm.description" rows="2" class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500" />
                        </div>
                    </div>

                    <div class="mt-4 flex justify-end">
                        <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-60" :disabled="savingEvent">
                            <PlusIcon class="h-4 w-4" />
                            {{ savingEvent ? tr('Guardando...', 'Saving...') : tr('Crear fundraiser', 'Create fundraiser') }}
                        </button>
                    </div>
                </form>

                <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                    <div class="mb-4 flex items-center gap-2">
                        <ClipboardDocumentListIcon class="h-5 w-5 text-gray-600" />
                        <h3 class="text-base font-semibold text-gray-900">{{ tr('Fundraisers registrados', 'Registered fundraisers') }}</h3>
                    </div>

                    <div v-if="loading" class="rounded-lg border border-dashed border-gray-300 p-5 text-sm text-gray-500">
                        {{ tr('Cargando...', 'Loading...') }}
                    </div>
                    <div v-else-if="events.length === 0" class="rounded-lg border border-dashed border-gray-300 p-5 text-sm text-gray-500">
                        {{ tr('Aun no hay fundraisers registrados.', 'No fundraisers registered yet.') }}
                    </div>
                    <div v-else class="grid gap-3 md:grid-cols-2">
                        <button
                            v-for="event in events"
                            :key="event.id"
                            type="button"
                            class="rounded-lg border p-4 text-left transition"
                            :class="Number(selectedEventId) === Number(event.id) ? 'border-red-200 bg-red-50' : 'border-gray-200 bg-white hover:bg-gray-50'"
                            @click="selectedEventId = event.id"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-gray-950">{{ event.name }}</p>
                                    <p class="mt-1 text-xs text-gray-500">{{ fundraiserTypeLabel(event.fundraiser_type) }} · {{ formatDate(event.event_date) }}</p>
                                </div>
                                <span class="rounded-full bg-white px-2 py-1 text-xs font-semibold text-gray-600">{{ event.status }}</span>
                            </div>
                            <div class="mt-4 grid grid-cols-3 gap-2 text-xs">
                                <div>
                                    <p class="text-gray-500">{{ tr('Ingresos', 'Revenue') }}</p>
                                    <p class="font-semibold text-gray-900">{{ formatMoney(event.totals?.revenue) }}</p>
                                </div>
                                <div>
                                    <p class="text-gray-500">{{ tr('Inversion', 'Investment') }}</p>
                                    <p class="font-semibold text-gray-900">{{ formatMoney(event.totals?.investment_total) }}</p>
                                </div>
                                <div>
                                    <p class="text-gray-500">{{ tr('Neta', 'Net') }}</p>
                                    <p class="font-semibold" :class="Number(event.totals?.net_gain || 0) < 0 ? 'text-rose-700' : 'text-emerald-700'">{{ formatMoney(event.totals?.net_gain) }}</p>
                                </div>
                            </div>
                        </button>
                    </div>
                </div>
            </section>

            <template v-if="selectedEvent">
                <section class="space-y-3">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">{{ selectedEvent.name }}</h3>
                            <p class="mt-1 text-sm text-gray-500">{{ selectedEvent.account_label }} · {{ fundraiserTypeLabel(selectedEvent.fundraiser_type) }}</p>
                        </div>
                        <button
                            v-if="!selectedEventIsClosed"
                            type="button"
                            class="inline-flex items-center justify-center gap-2 rounded-lg bg-red-600 px-3 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-60"
                            @click="openCloseModal"
                        >
                            <CheckCircleIcon class="h-4 w-4" />
                            {{ tr('Cerrar fundraiser', 'Close fundraiser') }}
                        </button>
                        <span v-else class="inline-flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-800">
                            <CheckCircleIcon class="h-4 w-4" />
                            {{ tr('Fundraiser cerrado', 'Fundraiser closed') }}
                        </span>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="rounded-lg border border-gray-200 bg-white p-4">
                            <div class="flex items-center gap-2 text-sm font-semibold text-gray-700">
                                <BanknotesIcon class="h-5 w-5 text-emerald-600" />
                                {{ tr('Ingresos', 'Revenue') }}
                            </div>
                            <p class="mt-3 text-2xl font-semibold text-gray-950">{{ formatMoney(selectedEvent.totals?.revenue) }}</p>
                        </div>
                        <div class="rounded-lg border border-gray-200 bg-white p-4">
                            <div class="flex items-center gap-2 text-sm font-semibold text-gray-700">
                                <ChartBarIcon class="h-5 w-5 text-blue-600" />
                                {{ tr('Ganancia neta', 'Net gain') }}
                            </div>
                            <p class="mt-3 text-2xl font-semibold" :class="Number(selectedEvent.totals?.net_gain || 0) < 0 ? 'text-rose-700' : 'text-emerald-700'">{{ formatMoney(selectedEvent.totals?.net_gain) }}</p>
                            <p class="mt-1 text-xs text-gray-500">{{ tr('Margen vendido', 'Sold margin') }} {{ formatMoney(selectedEvent.totals?.gross_gain) }}</p>
                        </div>
                        <div class="rounded-lg border border-gray-200 bg-white p-4">
                            <div class="flex items-center gap-2 text-sm font-semibold text-gray-700">
                                <ClipboardDocumentListIcon class="h-5 w-5 text-gray-600" />
                                {{ tr('Ventas / recibos', 'Sales / receipts') }}
                            </div>
                            <p class="mt-3 text-2xl font-semibold text-gray-950">{{ selectedEvent.totals?.sale_count || 0 }} / {{ selectedEvent.totals?.receipt_count || 0 }}</p>
                        </div>
                    </div>
                </section>

                <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                    <div class="mb-4 flex items-start gap-2">
                        <UserGroupIcon class="mt-0.5 h-5 w-5 shrink-0 text-blue-600" />
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">{{ tr('Clubes asociados', 'Partner clubs') }}</h3>
                            <p class="mt-1 text-sm text-gray-600">
                                {{ tr('Registra aportes de inversion y distribuciones del dinero recaudado entre clubes de la misma iglesia.', 'Record investment contributions and distributions of raised funds between clubs from the same church.') }}
                            </p>
                            <p class="mt-1 text-xs font-medium text-blue-700">
                                {{ tr('Asociar un club solo guarda el acuerdo. Usa Registrar aporte para crear el gasto del club asociado y el ingreso del club operativo; si el asociado no tiene fondos, se genera su reembolso pendiente.', 'Adding a partner club only saves the agreement. Use Record contribution to create the partner club expense and the operating club income; if the partner has no funds, its pending reimbursement is created.') }}
                            </p>
                        </div>
                    </div>

                    <form v-if="partnerClubOptions.length > 0 && !selectedEventIsClosed" class="grid gap-3 md:grid-cols-[minmax(0,1.3fr)_120px_120px_minmax(0,1fr)_auto]" @submit.prevent="submitPartner">
                        <div>
                            <label class="text-sm font-medium text-gray-700">{{ tr('Club asociado', 'Partner club') }}</label>
                            <select v-model="partnerForm.partner_club_id" class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500">
                                <option value="">{{ tr('Seleccionar', 'Select') }}</option>
                                <option v-for="club in partnerClubOptions" :key="club.id" :value="club.id">{{ club.club_name }}</option>
                            </select>
                            <p v-if="firstError(partnerErrors, 'partner_club_id')" class="mt-1 text-xs text-rose-600">{{ firstError(partnerErrors, 'partner_club_id') }}</p>
                        </div>
                        <div>
                            <label class="text-sm font-medium text-gray-700">{{ tr('% inversion', '% investment') }}</label>
                            <input v-model="partnerForm.investment_share_percent" type="number" min="0" max="100" step="0.01" class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500">
                            <p v-if="firstError(partnerErrors, 'investment_share_percent')" class="mt-1 text-xs text-rose-600">{{ firstError(partnerErrors, 'investment_share_percent') }}</p>
                        </div>
                        <div>
                            <label class="text-sm font-medium text-gray-700">{{ tr('% recaudado', '% raised') }}</label>
                            <input v-model="partnerForm.earnings_share_percent" type="number" min="0" max="100" step="0.01" class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500">
                            <p v-if="firstError(partnerErrors, 'earnings_share_percent')" class="mt-1 text-xs text-rose-600">{{ firstError(partnerErrors, 'earnings_share_percent') }}</p>
                        </div>
                        <div>
                            <label class="text-sm font-medium text-gray-700">{{ tr('Notas', 'Notes') }}</label>
                            <input v-model="partnerForm.notes" type="text" class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500">
                        </div>
                        <div class="flex items-end">
                            <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-red-600 px-3 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-60" :disabled="savingPartner">
                                <PlusIcon class="h-4 w-4" />
                                {{ savingPartner ? tr('Guardando...', 'Saving...') : tr('Asociar', 'Add') }}
                            </button>
                        </div>
                    </form>
                    <div v-else-if="selectedEventPartners.length === 0" class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-500">
                        {{ tr('No hay otros clubes de esta iglesia disponibles para asociar.', 'No other clubs from this church are available to partner.') }}
                    </div>

                    <div v-if="selectedEventPartners.length > 0" class="mt-4 overflow-hidden rounded-lg border border-gray-200">
                        <table class="w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th class="px-3 py-2">{{ tr('Club', 'Club') }}</th>
                                    <th class="px-3 py-2">{{ tr('Aporte inversion', 'Investment contribution') }}</th>
                                    <th class="px-3 py-2">{{ tr('Distribucion recaudada', 'Raised funds distribution') }}</th>
                                    <th class="px-3 py-2">{{ tr('Movimientos', 'Movements') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                <tr v-for="partner in selectedEventPartners" :key="partner.id">
                                    <td class="px-3 py-3 align-top">
                                        <p class="font-semibold text-gray-950">{{ partner.partner_club_name }}</p>
                                        <p class="mt-1 text-xs text-gray-500">{{ partner.notes || tr('Sin notas', 'No notes') }}</p>
                                    </td>
                                    <td class="px-3 py-3 align-top">
                                        <p class="font-semibold text-gray-900">{{ partner.investment_share_percent }}% · {{ formatMoney(partner.investment_due) }}</p>
                                        <p class="mt-1 text-xs text-gray-500">{{ tr('Registrado', 'Recorded') }}: {{ formatMoney(partner.contribution_recorded) }}</p>
                                    </td>
                                    <td class="px-3 py-3 align-top">
                                        <p class="font-semibold text-gray-900">{{ partner.earnings_share_percent }}% · {{ formatMoney(partner.earnings_due) }}</p>
                                        <p class="mt-1 text-xs text-gray-500">{{ tr('Transferido', 'Transferred') }}: {{ formatMoney(partner.earnings_distributed) }}</p>
                                    </td>
                                    <td class="px-3 py-3 align-top">
                                        <div class="grid gap-3 xl:grid-cols-2">
                                            <div>
                                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tr('Aporte', 'Contribution') }}</p>
                                                <a v-if="partner.contribution_transfer?.receipt?.url" :href="partner.contribution_transfer.receipt.url" class="mt-1 inline-flex font-semibold text-red-700 hover:text-red-800">
                                                    {{ partner.contribution_transfer.receipt.number }}
                                                </a>
                                                <div v-else-if="Number(partner.investment_due || 0) > 0" class="mt-1 flex flex-wrap items-center gap-2">
                                                    <select v-model="partnerTransferForm(partner, 'contribution').funds_location" class="w-28 rounded-md border-gray-300 text-xs shadow-sm focus:border-red-500 focus:ring-red-500">
                                                        <option value="cash">{{ tr('Efectivo', 'Cash') }}</option>
                                                        <option value="bank">{{ tr('Banco', 'Bank') }}</option>
                                                    </select>
                                                    <input v-model="partnerTransferForm(partner, 'contribution').transfer_date" type="date" class="w-36 rounded-md border-gray-300 text-xs shadow-sm focus:border-red-500 focus:ring-red-500">
                                                    <button type="button" class="rounded-md bg-red-600 px-2.5 py-2 text-xs font-semibold text-white hover:bg-red-700 disabled:opacity-60" :disabled="savingPartnerTransfer === partnerTransferKey(partner, 'contribution')" @click="recordPartnerTransfer(partner, 'contribution')">
                                                        {{ tr('Registrar', 'Record') }}
                                                    </button>
                                                </div>
                                                <span v-else class="mt-1 inline-flex text-xs text-gray-400">—</span>
                                                <p v-if="partnerTransferError(partner, 'contribution')" class="mt-1 text-xs text-rose-600">{{ partnerTransferError(partner, 'contribution') }}</p>
                                            </div>
                                            <div>
                                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tr('Distribucion', 'Distribution') }}</p>
                                                <a v-if="partner.distribution_transfer?.receipt?.url" :href="partner.distribution_transfer.receipt.url" class="mt-1 inline-flex font-semibold text-red-700 hover:text-red-800">
                                                    {{ partner.distribution_transfer.receipt.number }}
                                                </a>
                                                <div v-else-if="Number(partner.earnings_due || 0) > 0" class="mt-1 flex flex-wrap items-center gap-2">
                                                    <select v-model="partnerTransferForm(partner, 'distribution').funds_location" class="w-28 rounded-md border-gray-300 text-xs shadow-sm focus:border-red-500 focus:ring-red-500">
                                                        <option value="cash">{{ tr('Efectivo', 'Cash') }}</option>
                                                        <option value="bank">{{ tr('Banco', 'Bank') }}</option>
                                                    </select>
                                                    <input v-model="partnerTransferForm(partner, 'distribution').transfer_date" type="date" class="w-36 rounded-md border-gray-300 text-xs shadow-sm focus:border-red-500 focus:ring-red-500">
                                                    <button type="button" class="rounded-md bg-red-600 px-2.5 py-2 text-xs font-semibold text-white hover:bg-red-700 disabled:opacity-60" :disabled="savingPartnerTransfer === partnerTransferKey(partner, 'distribution')" @click="recordPartnerTransfer(partner, 'distribution')">
                                                        {{ tr('Transferir', 'Transfer') }}
                                                    </button>
                                                </div>
                                                <span v-else class="mt-1 inline-flex text-xs text-gray-400">—</span>
                                                <p v-if="partnerTransferError(partner, 'distribution')" class="mt-1 text-xs text-rose-600">{{ partnerTransferError(partner, 'distribution') }}</p>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="space-y-4">
                    <div ref="cajaSection" class="scroll-mt-5 rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                        <div class="mb-4 flex items-start gap-2">
                            <ShoppingBagIcon class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
                            <div>
                                <h3 class="text-base font-semibold text-gray-900">{{ tr('Productos a vender', 'Sellable products') }}</h3>
                                <p class="mt-1 text-sm text-gray-600">
                                    {{ tr('Define solo el producto, su precio de venta y cuantas unidades hay o se planea vender. La inversion del fundraiser se registra arriba.', 'Define only the product, sale price, and available or planned units. Fundraiser investment is recorded above.') }}
                                </p>
                            </div>
                        </div>

                        <div class="overflow-hidden rounded-lg border border-gray-200">
                            <table class="w-full table-fixed divide-y divide-gray-200 text-sm">
                                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                    <tr>
                                        <th class="w-[44%] px-3 py-2">{{ tr('Producto', 'Product') }}</th>
                                        <th class="w-[20%] px-3 py-2">{{ tr('Precio', 'Price') }}</th>
                                        <th class="w-[22%] px-3 py-2">{{ tr('Cantidad planeada', 'Planned quantity') }}</th>
                                        <th class="w-[14%] px-3 py-2 text-right">{{ tr('Acciones', 'Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 bg-white">
                                    <tr v-for="product in selectedEventProducts" :key="product.id" :class="Number(editingProductId) === Number(product.id) ? 'bg-amber-50/40' : ''">
                                        <template v-if="Number(editingProductId) === Number(product.id) && productEditForm">
                                            <td class="px-3 py-2 align-top">
                                                <input v-model="productEditForm.name" type="text" class="w-full min-w-0 rounded-md border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500" :placeholder="tr('Producto / plato', 'Product / plate')">
                                                <label class="mt-2 flex items-center gap-2 text-xs text-gray-700">
                                                    <input v-model="productEditForm.is_active" type="checkbox" class="rounded border-gray-300 text-red-600 focus:ring-red-500">
                                                    {{ tr('Activo', 'Active') }}
                                                </label>
                                                <p v-if="firstError(productEditErrors, 'name')" class="mt-1 text-xs text-rose-600">{{ firstError(productEditErrors, 'name') }}</p>
                                            </td>
                                            <td class="px-3 py-2 align-top">
                                                <input v-model="productEditForm.sale_price" type="number" min="0.01" step="0.01" class="w-full min-w-0 rounded-md border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500" placeholder="0.00">
                                                <p v-if="firstError(productEditErrors, 'sale_price')" class="mt-1 text-xs text-rose-600">{{ firstError(productEditErrors, 'sale_price') }}</p>
                                            </td>
                                            <td class="px-3 py-2 align-top">
                                                <input v-model="productEditForm.quantity_available" type="number" min="0" step="1" class="w-full min-w-0 rounded-md border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500" :placeholder="tr('Cantidad', 'Qty')">
                                                <p v-if="firstError(productEditErrors, 'quantity_available')" class="mt-1 text-xs text-rose-600">{{ firstError(productEditErrors, 'quantity_available') }}</p>
                                            </td>
                                            <td class="px-3 py-2 align-top">
                                                <div class="flex flex-wrap justify-end gap-2">
                                                    <button type="button" class="rounded-lg bg-red-600 px-2.5 py-2 text-xs font-semibold text-white hover:bg-red-700 disabled:opacity-60" :disabled="savingProductEdit || selectedEventIsClosed" @click="submitProductEdit(product)">
                                                        <span class="hidden sm:inline">{{ savingProductEdit ? tr('Guardando...', 'Saving...') : tr('Guardar', 'Save') }}</span>
                                                        <span class="sm:hidden">{{ savingProductEdit ? '...' : tr('Guardar', 'Save') }}</span>
                                                    </button>
                                                    <button type="button" class="rounded-lg border border-gray-200 px-2.5 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50" :disabled="savingProductEdit" @click="cancelProductEdit">
                                                        {{ tr('Cancelar', 'Cancel') }}
                                                    </button>
                                                </div>
                                            </td>
                                        </template>
                                        <template v-else>
                                            <td class="px-3 py-2 align-top">
                                                <p class="font-medium text-gray-900">{{ product.name }}</p>
                                                <span class="mt-1 inline-flex rounded-full px-2 py-1 text-xs font-semibold" :class="product.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-600'">
                                                    {{ product.is_active ? tr('Activo', 'Active') : tr('Pausado', 'Paused') }}
                                                </span>
                                            </td>
                                            <td class="px-3 py-2 align-top font-semibold text-gray-900">{{ formatMoney(product.sale_price) }}</td>
                                            <td class="px-3 py-2 align-top text-gray-700">{{ productQuantityLabel(product) }}</td>
                                            <td class="px-3 py-2 align-top text-right">
                                                <button type="button" class="rounded-lg border border-gray-200 px-2.5 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-60" :disabled="selectedEventIsClosed" @click="startProductEdit(product)">
                                                    {{ tr('Editar', 'Edit') }}
                                                </button>
                                            </td>
                                        </template>
                                    </tr>
                                    <tr v-if="selectedEventProducts.length === 0">
                                        <td colspan="4" class="px-3 py-5 text-center text-gray-500">{{ tr('Sin productos guardados. Usa la ultima fila para agregar el primero.', 'No saved products. Use the last row to add the first one.') }}</td>
                                    </tr>
                                    <tr class="bg-gray-50">
                                        <td class="px-3 py-3 align-top">
                                            <input v-model="productForm.name" type="text" class="w-full min-w-0 rounded-md border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500" :placeholder="tr('Producto / plato', 'Product / plate')">
                                            <label class="mt-2 flex items-center gap-2 text-xs text-gray-700">
                                                <input v-model="productForm.is_active" type="checkbox" class="rounded border-gray-300 text-red-600 focus:ring-red-500">
                                                {{ tr('Activo', 'Active') }}
                                            </label>
                                            <p v-if="firstError(productErrors, 'name')" class="mt-1 text-xs text-rose-600">{{ firstError(productErrors, 'name') }}</p>
                                        </td>
                                        <td class="px-3 py-3 align-top">
                                            <input v-model="productForm.sale_price" type="number" min="0.01" step="0.01" class="w-full min-w-0 rounded-md border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500" placeholder="0.00">
                                            <p v-if="firstError(productErrors, 'sale_price')" class="mt-1 text-xs text-rose-600">{{ firstError(productErrors, 'sale_price') }}</p>
                                        </td>
                                        <td class="px-3 py-3 align-top">
                                            <input v-model="productForm.quantity_available" type="number" min="0" step="1" class="w-full min-w-0 rounded-md border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500" :placeholder="tr('Cantidad', 'Qty')">
                                            <p v-if="firstError(productErrors, 'quantity_available')" class="mt-1 text-xs text-rose-600">{{ firstError(productErrors, 'quantity_available') }}</p>
                                        </td>
                                        <td class="px-3 py-3 align-top text-right">
                                            <button type="button" class="inline-flex max-w-full items-center justify-center gap-1 rounded-lg bg-red-600 px-2.5 py-2 text-xs font-semibold text-white hover:bg-red-700 disabled:opacity-60" :disabled="savingProduct || selectedEventIsClosed" @click="submitProduct">
                                                <PlusIcon class="h-4 w-4 shrink-0" />
                                                <span class="hidden sm:inline">{{ savingProduct ? tr('Guardando...', 'Saving...') : tr('Agregar', 'Add') }}</span>
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                        <div class="mb-4 flex items-center gap-2">
                            <CurrencyDollarIcon class="h-5 w-5 text-emerald-600" />
                            <h3 class="text-base font-semibold text-gray-900">{{ tr('Registrar venta', 'Record sale') }}</h3>
                        </div>

                        <form class="space-y-3" @submit.prevent="submitSale">
                            <p v-if="selectedEventIsClosed" class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-800">
                                {{ tr('Este fundraiser esta cerrado. No se pueden registrar mas ventas.', 'This fundraiser is closed. No more sales can be recorded.') }}
                            </p>
                            <div class="grid gap-3 md:grid-cols-2 2xl:grid-cols-12">
                                <div class="2xl:col-span-4">
                                    <label class="text-sm font-medium text-gray-700">{{ tr('Cliente', 'Customer') }}</label>
                                    <input v-model="saleForm.customer_name" type="text" class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500">
                                </div>
                                <div class="2xl:col-span-3">
                                    <label class="text-sm font-medium text-gray-700">{{ tr('Fecha', 'Date') }}</label>
                                    <input v-model="saleForm.sale_date" type="date" class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500">
                                </div>
                                <div class="2xl:col-span-2">
                                    <label class="text-sm font-medium text-gray-700">{{ tr('Pago', 'Payment') }}</label>
                                    <select v-model="saleForm.payment_type" class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500">
                                        <option v-for="type in paymentTypes" :key="type" :value="type">{{ paymentTypeLabel(type) }}</option>
                                    </select>
                                    <p v-if="firstError(saleErrors, 'payment_type')" class="mt-1 text-xs text-rose-600">{{ firstError(saleErrors, 'payment_type') }}</p>
                                </div>
                                <div v-if="saleForm.payment_type === 'zelle'" class="2xl:col-span-3">
                                    <label class="text-sm font-medium text-gray-700">{{ tr('Telefono Zelle', 'Zelle phone') }}</label>
                                    <input v-model="saleForm.zelle_phone" type="text" class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500">
                                    <p v-if="firstError(saleErrors, 'zelle_phone')" class="mt-1 text-xs text-rose-600">{{ firstError(saleErrors, 'zelle_phone') }}</p>
                                </div>
                            </div>

                            <div class="space-y-2">
                                <div v-for="(item, index) in saleForm.items" :key="index" class="grid gap-2 rounded-lg border border-gray-200 p-3 sm:grid-cols-[minmax(0,1fr)_88px_136px_40px]">
                                    <div>
                                        <label class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tr('Producto', 'Product') }}</label>
                                        <select v-model="item.fundraiser_product_id" class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500">
                                            <option value="">{{ tr('Seleccionar', 'Select') }}</option>
                                            <option v-for="product in activeProducts" :key="product.id" :value="product.id">{{ product.name }}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tr('Cantidad', 'Qty') }}</label>
                                        <input v-model="item.quantity" type="number" min="1" step="1" class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500">
                                        <p v-if="foodQuantityExtensionMessage(item)" class="mt-1 text-xs font-medium text-amber-700">
                                            {{ foodQuantityExtensionMessage(item) }}
                                        </p>
                                    </div>
                                    <div>
                                        <label class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tr('Precio', 'Price') }}</label>
                                        <div class="mt-1 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2">
                                            <div class="font-semibold text-gray-950">{{ formatMoney(saleRows[index]?.lineTotal) }}</div>
                                            <div class="text-xs text-gray-500">
                                                <template v-if="saleRows[index]?.product">
                                                    {{ saleRows[index].quantity }} x {{ formatMoney(saleRows[index].unitPrice) }}
                                                </template>
                                                <template v-else>
                                                    {{ tr('Seleccione producto', 'Select product') }}
                                                </template>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="flex items-end">
                                        <button type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50" @click="removeSaleItem(index)">
                                            <TrashIcon class="h-4 w-4" />
                                        </button>
                                    </div>
                                    <p v-if="firstError(saleErrors, `items.${index}.quantity`)" class="text-xs text-rose-600 sm:col-span-4">{{ firstError(saleErrors, `items.${index}.quantity`) }}</p>
                                </div>
                            </div>

                            <button type="button" class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50" @click="addSaleItem">
                                <PlusIcon class="h-4 w-4" />
                                {{ tr('Agregar linea', 'Add line') }}
                            </button>

                            <div class="grid gap-3 rounded-lg bg-gray-50 p-3 text-sm sm:grid-cols-3">
                                <div>
                                    <p class="text-gray-500">{{ tr('Total venta', 'Sale total') }}</p>
                                    <p class="text-lg font-semibold text-gray-950">{{ formatMoney(saleTotals.total) }}</p>
                                </div>
                                <div>
                                    <p class="text-gray-500">{{ tr('Costo', 'Cost') }}</p>
                                    <p class="text-lg font-semibold text-gray-950">{{ formatMoney(saleTotals.cost) }}</p>
                                </div>
                                <div>
                                    <p class="text-gray-500">{{ tr('Ganancia', 'Gain') }}</p>
                                    <p class="text-lg font-semibold" :class="saleTotals.gain < 0 ? 'text-rose-700' : 'text-emerald-700'">{{ formatMoney(saleTotals.gain) }}</p>
                                </div>
                            </div>

                            <p v-if="firstError(saleErrors, 'items')" class="text-sm text-rose-600">{{ firstError(saleErrors, 'items') }}</p>

                            <div class="flex justify-end">
                                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-60" :disabled="savingSale || activeProducts.length === 0 || selectedEventIsClosed">
                                    <CurrencyDollarIcon class="h-4 w-4" />
                                    {{ savingSale ? tr('Registrando...', 'Recording...') : tr('Registrar venta', 'Record sale') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </section>

                <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                    <div class="mb-4 flex items-center gap-2">
                        <ClipboardDocumentListIcon class="h-5 w-5 text-gray-600" />
                        <h3 class="text-base font-semibold text-gray-900">{{ tr('Ventas recientes', 'Recent sales') }}</h3>
                    </div>

                    <div class="overflow-hidden rounded-lg border border-gray-200">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th class="px-3 py-2">{{ tr('Fecha', 'Date') }}</th>
                                    <th class="px-3 py-2">{{ tr('Cliente', 'Customer') }}</th>
                                    <th class="px-3 py-2">{{ tr('Articulos', 'Items') }}</th>
                                    <th class="px-3 py-2">{{ tr('Total', 'Total') }}</th>
                                    <th class="px-3 py-2">{{ tr('Ganancia', 'Gain') }}</th>
                                    <th class="px-3 py-2">{{ tr('Recibo / QR', 'Receipt / QR') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                <tr v-for="sale in selectedEvent.sales" :key="sale.id">
                                    <td class="px-3 py-2 text-gray-700">{{ formatDate(sale.sale_date) }}</td>
                                    <td class="px-3 py-2 text-gray-700">{{ sale.customer_name || tr('Venta general', 'General sale') }}</td>
                                    <td class="px-3 py-2 text-gray-700">
                                        {{ sale.items.map((item) => `${item.quantity}x ${item.item_name}`).join(', ') }}
                                    </td>
                                    <td class="px-3 py-2 font-semibold text-gray-900">{{ formatMoney(sale.total_amount) }}</td>
                                    <td class="px-3 py-2 font-semibold" :class="Number(sale.gain_amount || 0) < 0 ? 'text-rose-700' : 'text-emerald-700'">{{ formatMoney(sale.gain_amount) }}</td>
                                    <td class="px-3 py-2">
                                        <div v-if="sale.receipt?.url" class="flex items-center gap-2">
                                            <a :href="sale.receipt.url" class="font-semibold text-red-700 hover:text-red-800">
                                                {{ sale.receipt.number }}
                                            </a>
                                            <button
                                                v-if="sale.receipt.qr_url"
                                                type="button"
                                                class="inline-flex shrink-0 rounded-md border border-gray-200 bg-white p-1 shadow-sm hover:border-red-300"
                                                :title="tr('Ampliar QR', 'Expand QR')"
                                                @click="openReceiptPreview(sale)"
                                            >
                                                <img
                                                    :src="sale.receipt.qr_url"
                                                    class="h-14 w-14"
                                                    :alt="`${tr('QR de recibo', 'Receipt QR')} ${sale.receipt.number}`"
                                                >
                                            </button>
                                        </div>
                                        <span v-else class="text-gray-400">—</span>
                                    </td>
                                </tr>
                                <tr v-if="selectedEvent.sales.length === 0">
                                    <td colspan="6" class="px-3 py-6 text-center text-gray-500">{{ tr('Sin ventas.', 'No sales.') }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </template>
        </div>

        <div
            v-if="receiptPreviewSale"
            class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/60 p-4"
            @click.self="closeReceiptPreview"
        >
            <section class="max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-lg bg-white shadow-2xl">
                <header class="flex items-start justify-between gap-4 border-b border-gray-200 px-5 py-4">
                    <div>
                        <p class="text-sm font-semibold text-red-700">{{ receiptPreviewSale.receipt?.number }}</p>
                        <h2 class="text-xl font-semibold text-gray-950">
                            {{ receiptPreviewSale.customer_name || tr('Venta general', 'General sale') }}
                        </h2>
                        <p class="mt-1 text-sm text-gray-500">
                            {{ formatDate(receiptPreviewSale.sale_date) }} · {{ formatMoney(receiptPreviewSale.total_amount) }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50"
                        :aria-label="tr('Cerrar', 'Close')"
                        @click="closeReceiptPreview"
                    >
                        <XMarkIcon class="h-5 w-5" />
                    </button>
                </header>

                <div class="grid gap-5 p-5 md:grid-cols-[260px_minmax(0,1fr)]">
                    <div>
                        <div class="rounded-lg border border-gray-200 bg-white p-3">
                            <img
                                :src="receiptPreviewSale.receipt.qr_url"
                                class="mx-auto h-56 w-56 md:h-60 md:w-60"
                                :alt="`${tr('QR de recibo', 'Receipt QR')} ${receiptPreviewSale.receipt.number}`"
                            >
                        </div>
                        <a
                            :href="receiptPreviewSale.receipt.public_url || receiptPreviewSale.receipt.url"
                            target="_blank"
                            rel="noopener"
                            class="mt-3 inline-flex w-full items-center justify-center gap-2 rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700"
                        >
                            <ArrowDownTrayIcon class="h-4 w-4" />
                            {{ tr('Descargar recibo', 'Download receipt') }}
                        </a>
                    </div>

                    <div>
                        <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ tr('Pedido', 'Order') }}</h3>
                        <div class="mt-3 divide-y divide-gray-100 rounded-lg border border-gray-200">
                            <div
                                v-for="item in receiptPreviewSale.items"
                                :key="item.id"
                                class="flex items-start justify-between gap-3 px-3 py-2"
                            >
                                <div>
                                    <p class="font-medium text-gray-950">{{ item.item_name }}</p>
                                    <p class="text-sm text-gray-500">{{ item.quantity }} x {{ formatMoney(item.unit_price) }}</p>
                                </div>
                                <p class="font-semibold text-gray-950">{{ formatMoney(saleItemLineTotal(item)) }}</p>
                            </div>
                        </div>

                        <div class="mt-4 rounded-lg bg-gray-50 p-3">
                            <div class="flex items-center justify-between text-sm">
                                <span class="font-medium text-gray-600">{{ tr('Total', 'Total') }}</span>
                                <span class="text-lg font-semibold text-gray-950">{{ formatMoney(receiptPreviewSale.total_amount) }}</span>
                            </div>
                            <div class="mt-2 flex items-center justify-between text-sm text-gray-600">
                                <span>{{ tr('Pago', 'Payment') }}</span>
                                <span>{{ paymentTypeLabel(receiptPreviewSale.payment_type) }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <div
            v-if="showCloseModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/60 p-4"
            @click.self="closeCloseModal"
        >
            <section class="max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-lg bg-white shadow-2xl">
                <header class="flex items-start justify-between gap-4 border-b border-gray-200 px-5 py-4">
                    <div>
                        <p class="text-sm font-semibold text-red-700">{{ tr('Cerrar fundraiser', 'Close fundraiser') }}</p>
                        <h2 class="text-xl font-semibold text-gray-950">{{ selectedEvent?.name }}</h2>
                        <p class="mt-1 text-sm text-gray-500">
                            {{ tr('El cierre bloquea nuevas ventas y registra distribuciones pendientes.', 'Closing blocks new sales and records pending distributions.') }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50 disabled:opacity-60"
                        :aria-label="tr('Cerrar', 'Close')"
                        :disabled="closingEvent"
                        @click="closeCloseModal"
                    >
                        <XMarkIcon class="h-5 w-5" />
                    </button>
                </header>

                <form class="space-y-4 p-5" @submit.prevent="submitCloseEvent">
                    <div class="grid gap-3 rounded-lg bg-gray-50 p-3 text-sm sm:grid-cols-3">
                        <div>
                            <p class="text-gray-500">{{ tr('Recaudado', 'Raised') }}</p>
                            <p class="text-lg font-semibold text-gray-950">{{ formatMoney(selectedEvent?.totals?.revenue) }}</p>
                        </div>
                        <div>
                            <p class="text-gray-500">{{ tr('A distribuir', 'To distribute') }}</p>
                            <p class="text-lg font-semibold text-gray-950">{{ formatMoney(selectedEventPartnerDistributionTotal) }}</p>
                        </div>
                        <div>
                            <p class="text-gray-500">{{ tr('Clubes asociados', 'Partner clubs') }}</p>
                            <p class="text-lg font-semibold text-gray-950">{{ selectedEventPartners.length }}</p>
                        </div>
                    </div>

                    <div v-if="selectedEventPartners.length > 0" class="rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-sm text-blue-800">
                        {{ tr('La distribucion se calcula sobre el total recaudado, sin restar la inversion inicial, porque el aporte inicial del club asociado ya debe estar registrado.', 'Distribution is calculated from total raised funds, without subtracting the initial investment, because the partner club initial contribution should already be recorded.') }}
                    </div>

                    <div v-if="selectedEventHasPendingPartnerContributions" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800">
                        {{ tr('Hay aportes de inversion pendientes. Registralos antes de cerrar.', 'There are pending investment contributions. Record them before closing.') }}
                    </div>

                    <div class="grid gap-3 sm:grid-cols-3">
                        <div>
                            <label class="text-sm font-medium text-gray-700">{{ tr('Fecha cierre', 'Close date') }}</label>
                            <input v-model="closeForm.close_date" type="date" class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500">
                        </div>
                        <div>
                            <label class="text-sm font-medium text-gray-700">{{ tr('Origen de pago', 'Payment origin') }}</label>
                            <select v-model="closeForm.funds_location" class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500">
                                <option value="cash">{{ tr('Efectivo', 'Cash') }}</option>
                                <option value="bank">{{ tr('Banco', 'Bank') }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-sm font-medium text-gray-700">{{ tr('Tipo recibido', 'Received type') }}</label>
                            <select v-model="closeForm.payment_type" class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500">
                                <option value="cash">{{ tr('Efectivo', 'Cash') }}</option>
                                <option value="check">{{ tr('Cheque', 'Check') }}</option>
                                <option value="transfer">{{ tr('Transferencia', 'Transfer') }}</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="text-sm font-medium text-gray-700">{{ tr('Notas', 'Notes') }}</label>
                        <textarea v-model="closeForm.notes" rows="2" class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500" />
                    </div>

                    <p v-if="firstError(closeErrors, 'partners')" class="text-sm text-rose-600">{{ firstError(closeErrors, 'partners') }}</p>
                    <p v-if="firstError(closeErrors, 'amount')" class="text-sm text-rose-600">{{ firstError(closeErrors, 'amount') }}</p>
                    <p v-if="firstError(closeErrors, 'fundraiser_event_id')" class="text-sm text-rose-600">{{ firstError(closeErrors, 'fundraiser_event_id') }}</p>

                    <div class="flex justify-end gap-2">
                        <button type="button" class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-60" :disabled="closingEvent" @click="closeCloseModal">
                            {{ tr('Cancelar', 'Cancel') }}
                        </button>
                        <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-60" :disabled="closingEvent || selectedEventHasPendingPartnerContributions">
                            <CheckCircleIcon class="h-4 w-4" />
                            {{ closingEvent ? tr('Cerrando...', 'Closing...') : tr('Cerrar fundraiser', 'Close fundraiser') }}
                        </button>
                    </div>
                </form>
            </section>
        </div>

        <div
            v-if="showFundraiserGuide"
            class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/60 p-4"
            @click.self="closeFundraiserGuide"
        >
            <section class="max-h-[92vh] w-full max-w-3xl overflow-y-auto rounded-lg bg-white shadow-2xl">
                <header class="flex items-start justify-between gap-4 border-b border-gray-200 px-5 py-4">
                    <div>
                        <p class="text-sm font-semibold text-blue-700">{{ tr('Guia de fundraiser', 'Fundraiser guide') }}</p>
                        <h2 class="text-xl font-semibold text-gray-950">
                            {{ tr('Ventas, aportes y distribuciones entre clubes', 'Sales, contributions, and distributions between clubs') }}
                        </h2>
                    </div>
                    <button
                        type="button"
                        class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50"
                        :aria-label="tr('Cerrar', 'Close')"
                        @click="closeFundraiserGuide"
                    >
                        <XMarkIcon class="h-5 w-5" />
                    </button>
                </header>

                <div class="space-y-5 p-5 text-sm text-gray-700">
                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                        <h3 class="font-semibold text-gray-950">{{ tr('Flujo normal', 'Normal flow') }}</h3>
                        <ol class="mt-3 list-decimal space-y-2 pl-5">
                            <li>{{ tr('Crea el fundraiser y registra la inversion inicial desde la cuenta del club operativo.', 'Create the fundraiser and record the initial investment from the operative club account.') }}</li>
                            <li>{{ tr('Agrega los productos o platos con nombre, precio y cantidad planeada.', 'Add products or plates with name, price, and planned quantity.') }}</li>
                            <li>{{ tr('Cada venta registrada en caja entra como ingreso a la cuenta destino del fundraiser y genera recibo.', 'Every register sale is posted as income to the fundraiser destination account and generates a receipt.') }}</li>
                            <li>{{ tr('Si es comida, el enlace de cocina muestra los pedidos en orden para prepararlos y marcarlos terminados.', 'For food, the kitchen link shows orders in sequence so they can be prepared and marked finished.') }}</li>
                        </ol>
                    </div>

                    <div class="rounded-lg border border-blue-200 bg-blue-50 p-4">
                        <h3 class="font-semibold text-gray-950">{{ tr('Cuando hay clubes asociados', 'When there are partner clubs') }}</h3>
                        <ol class="mt-3 list-decimal space-y-2 pl-5">
                            <li>{{ tr('El club operativo controla la venta y agrega al club asociado con porcentaje de inversion y porcentaje de recaudacion.', 'The operative club controls the sale and adds the partner club with investment and raised-funds percentages.') }}</li>
                            <li>{{ tr('El club asociado entrega su aporte real fuera del sistema: efectivo, cheque o transferencia bancaria.', 'The partner club delivers its real contribution outside the system: cash, check, or bank transfer.') }}</li>
                            <li>{{ tr('En Clubes asociados, pulsa Registrar en Aporte. El sistema registra un gasto en el club asociado y un ingreso con recibo en el club operativo.', 'In Partner clubs, click Record under Contribution. The system records an expense in the partner club and an income with receipt in the operative club.') }}</li>
                            <li>{{ tr('Al cerrar el fundraiser, el sistema distribuye automaticamente el porcentaje del total recaudado, sin restar la inversion inicial.', 'When the fundraiser is closed, the system automatically distributes the percentage of total raised funds, without subtracting initial investment.') }}</li>
                            <li>{{ tr('Los aportes y distribuciones se registran por el monto completo calculado por porcentaje para evitar liquidaciones parciales.', 'Contributions and distributions are recorded for the full amount calculated by percentage to avoid partial settlements.') }}</li>
                        </ol>
                    </div>

                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-4">
                        <h3 class="font-semibold text-gray-950">{{ tr('Como se mueve el dinero entre clubes', 'How money moves between clubs') }}</h3>
                        <p class="mt-2">
                            {{ tr('Entre clubes no se usa una transferencia interna de efectivo a banco. Contablemente es un gasto para el club que entrega el dinero y un ingreso para el club que lo recibe. Ambos movimientos quedan enlazados en el fundraiser.', 'Between clubs this is not a cash-to-bank internal transfer. In accounting terms it is an expense for the club giving the money and income for the club receiving it. Both movements remain linked to the fundraiser.') }}
                        </p>
                    </div>
                </div>
            </section>
        </div>
    </PathfinderLayout>
</template>
