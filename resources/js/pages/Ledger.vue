<script setup lang="ts">

import { type BreadcrumbItem } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, reactive, ref } from 'vue';
import { Ledger } from '@/routes';
interface Entry {
    id: number;
    date: string;
    type: string;
    amount: number | string;
    mode: string | null;
    note: string | null;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Ledger', href: '/ledger' }];

const page = usePage();
const entries = computed<Entry[]>(() => (page.props.entries as Entry[]) ?? []);

const MONTHS = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December',
];
const TYPES = [
    { key: 'sale', label: 'Sale / Received' },
    { key: 'staff', label: 'Staff Payment' },
    { key: 'expense', label: 'Expense / Kharcha' },
    { key: 'udhaar', label: 'Udhaar (Credit)' },
    { key: 'shortage', label: 'Shortage' },
];
const TYPE_SHORT: Record<string, string> = { sale: 'Sale', staff: 'Staff', expense: 'Kharcha', udhaar: 'Udhaar', shortage: 'Shortage' };
const MODES = ['Cash', 'UPI'];

function typeShort(t: string) { return TYPE_SHORT[t] || t; }
function modeClass(m: string | null) { return m === 'Cash' ? 'cash' : m === 'UPI' ? 'upi' : 'other'; }

/* ---------- form state ---------- */
const form = reactive({ date: '', type: 'sale', amount: null as number | null, mode: 'Cash', note: '' });
const msg = ref('');
const monthFilter = ref('all');
const saving = ref(false);

const showMode = computed(() => ['sale', 'staff', 'expense'].includes(form.type));
const notePlaceholder = computed(() => {
    if (form.type === 'staff') return 'Staff name';
    if (form.type === 'expense') return 'e.g. Rent, transport, supplies';
    if (form.type === 'udhaar') return 'Customer name';
    if (form.type === 'shortage') return 'Reason (optional)';
    return 'e.g. Ramesh — leather wallet';
});
const noteLabel = computed(() => {
    if (form.type === 'staff') return 'Staff Name';
    if (form.type === 'expense') return 'Kharcha Details';
    if (form.type === 'udhaar') return 'Customer Name';
    return 'Note (optional)';
});

/* ---------- helpers ---------- */
function todayStr() {
    const d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}
function amt(e: Entry) { return Number(e.amount) || 0; }
function fmt(n: number) { return '\u20B9' + (Number(n) || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 }); }
function fmtDate(s: string) { const p = s.split('-'); return Number(p[2]) + ' ' + MONTHS[Number(p[1]) - 1] + ' ' + p[0]; }
function fmtDay(s: string) { const p = s.split('-'); return Number(p[2]); }
function fmtMon(s: string) { const p = s.split('-'); return MONTHS[Number(p[1]) - 1].slice(0, 3); }
function monthKey(s: string) { return s.slice(0, 7); }
function monthLabel(k: string) { const p = k.split('-'); return MONTHS[Number(p[1]) - 1] + ' ' + p[0]; }
function sumType(list: Entry[], t: string) { return list.filter(e => e.type === t).reduce((s, e) => s + amt(e), 0); }
function sumSalesMode(list: Entry[], m: string) { return list.filter(e => e.type === 'sale' && e.mode === m).reduce((s, e) => s + amt(e), 0); }

const today = computed(() => todayStr());

/* ---------- today / month cards ---------- */
const todayList = computed(() => entries.value.filter(e => e.date === today.value));
const todayCash = computed(() => sumSalesMode(todayList.value, 'Cash'));
const todayOnline = computed(() => sumSalesMode(todayList.value, 'UPI'));
const todayReceived = computed(() => todayCash.value + todayOnline.value);
const monthList = computed(() => entries.value.filter(e => monthKey(e.date) === monthKey(today.value)));
const monthReceived = computed(() => sumType(monthList.value, 'sale'));

/* ---------- filter + grouping ---------- */
const monthOptions = computed(() => {
    const keys = [...new Set(entries.value.map(e => monthKey(e.date)))].sort().reverse();
    return keys.map(k => ({ key: k, label: monthLabel(k) }));
});
const filtered = computed(() => {
    if (monthFilter.value === 'all') return entries.value;
    return entries.value.filter(e => monthKey(e.date) === monthFilter.value);
});
const grouped = computed(() => {
    const map: Record<string, Entry[]> = {};
    filtered.value.forEach(e => { (map[e.date] = map[e.date] || []).push(e); });
    return Object.keys(map).sort().reverse().map(date => {
        const rows = map[date];
        return {
            date, rows,
            daySale: sumType(rows, 'sale'),
            dayStaff: sumType(rows, 'staff'),
            dayExpense: sumType(rows, 'expense'),
            dayUdhaar: sumType(rows, 'udhaar'),
            dayShortage: sumType(rows, 'shortage'),
        };
    });
});

/* ---------- totals ---------- */
const totReceived = computed(() => sumType(filtered.value, 'sale'));
const totCash = computed(() => sumSalesMode(filtered.value, 'Cash'));
const totOnline = computed(() => sumSalesMode(filtered.value, 'UPI'));
const totStaff = computed(() => sumType(filtered.value, 'staff'));
const totExpense = computed(() => sumType(filtered.value, 'expense'));
const totSpent = computed(() => totStaff.value + totExpense.value);
const totUdhaar = computed(() => sumType(filtered.value, 'udhaar'));
const inHand = computed(() => totReceived.value - totSpent.value);

const udhaarByParty = computed(() => {
    const map: Record<string, number> = {};
    filtered.value.filter(e => e.type === 'udhaar').forEach(e => {
        const name = (e.note || '').trim() || 'No name';
        map[name] = (map[name] || 0) + amt(e);
    });
    return Object.keys(map).sort((a, b) => map[b] - map[a]).map(name => ({ name, total: map[name] }));
});

/* ---------- actions (talk to Laravel) ---------- */
function addEntry() {
    const amount = Number(form.amount) || 0;
    if (amount <= 0) { msg.value = 'Please enter an amount.'; return; }
    saving.value = true;
    router.post('/ledger', {
        date: form.date || today.value,
        type: form.type,
        amount,
        mode: showMode.value ? form.mode : null,
        note: form.note?.trim() || null,
    }, {
        preserveScroll: true,
        onSuccess: () => { form.amount = null; form.note = ''; msg.value = ''; },
        onFinish: () => { saving.value = false; },
    });
}
function deleteEntry(id: number) {
    if (!confirm('Delete this entry?')) return;
    router.delete(`/ledger/${id}`, { preserveScroll: true });
}

onMounted(() => { form.date = today.value; });
</script>


<template>
     <Head title="Ledger" />

     <div
        class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4"
    >
    <div class="relative min-h-[100vh] flex-1 rounded-xl  md:min-h-min dark:border-sidebar-border">
             <div class="wrap">
            <!-- Header -->
            <header class="cover">
                <p class="kicker">Daily Ledger</p>
                <!-- <h1 class="brand">Leather House</h1> -->
                <p class="sub">Today &middot; {{ fmtDate(today) }}</p>
            </header>

            <!-- Summary cards -->
            <section class="cards">
                <div class="card cash"><div class="lbl">Today Cash</div><div class="val">{{ fmt(todayCash) }}</div></div>
                <div class="card online"><div class="lbl">Today Online (UPI)</div><div class="val">{{ fmt(todayOnline) }}</div></div>
                <div class="card in"><div class="lbl">Today Received</div><div class="val">{{ fmt(todayReceived) }}</div></div>
                <div class="card in"><div class="lbl">This Month Received</div><div class="val">{{ fmt(monthReceived) }}</div></div>
            </section>

            <!-- Add entry -->
            <section class="panel">
                <h2 class="panel-title">Add Entry</h2>
                <div class="form">
                    <div class="field">
                        <label>Type</label>
                        <select v-model="form.type">
                            <option v-for="t in TYPES" :key="t.key" :value="t.key">{{ t.label }}</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Date</label>
                        <input type="date" v-model="form.date" />
                    </div>
                    <div class="field">
                        <label>Amount (&#8377;)</label>
                        <input type="number" inputmode="decimal" min="0" step="any" placeholder="0"
                               v-model.number="form.amount" @keydown.enter="addEntry" />
                    </div>
                    <div class="field" v-if="showMode">
                        <label>Payment Mode</label>
                        <select v-model="form.mode">
                            <option v-for="m in MODES" :key="m" :value="m">{{ m }}</option>
                        </select>
                    </div>
                    <div class="field" :class="showMode ? 'note-after-mode' : 'note-full'">
                        <label>{{ noteLabel }}</label>
                        <input type="text" :placeholder="notePlaceholder" v-model="form.note" @keydown.enter="addEntry" />
                    </div>
                    <div class="actions">
                        <button class="btn" :disabled="saving" @click="addEntry">{{ saving ? 'Saving…' : '+ Add Entry' }}</button>
                        <span class="msg">{{ msg }}</span>
                    </div>
                </div>
            </section>

            <!-- Ledger -->
            <section class="panel">
                <div class="ledger-head">
                    <h2 class="panel-title">Account Book</h2>
                    <select v-model="monthFilter">
                        <option value="all">All months</option>
                        <option v-for="m in monthOptions" :key="m.key" :value="m.key">{{ m.label }}</option>
                    </select>
                </div>

                <div v-if="grouped.length === 0" class="empty-state">
                    No entries yet.<br />Add your first entry using the form above.
                </div>

                <template v-else>
                    <div class="col-head"><span>Date</span><span>Details</span><span>Amount</span><span></span></div>
                    <div class="daygroup" v-for="g in grouped" :key="g.date">
                        <div class="day-bar">
                            <div class="day-date">
                                <span class="day-num">{{ fmtDay(g.date) }}</span>
                                <span class="day-mon">{{ fmtMon(g.date) }}</span>
                            </div>
                            <span class="day-tot">
                                Received <b class="j">{{ fmt(g.daySale) }}</b>
                                <template v-if="g.dayStaff"> &middot; Staff <b class="k">{{ fmt(g.dayStaff) }}</b></template>
                                <template v-if="g.dayExpense"> &middot; Kharcha <b class="k">{{ fmt(g.dayExpense) }}</b></template>
                                <template v-if="g.dayUdhaar"> &middot; Udhaar <b class="u">{{ fmt(g.dayUdhaar) }}</b></template>
                                <template v-if="g.dayShortage"> &middot; Shortage <b class="k">{{ fmt(g.dayShortage) }}</b></template>
                            </span>
                        </div>
                        <div class="row" v-for="e in g.rows" :key="e.id">
                            <div class="row-date"></div>
                            <div class="note">
                                <span class="type-chip" :class="e.type">{{ typeShort(e.type) }}</span>
                                <span v-if="e.mode" class="mode-chip" :class="modeClass(e.mode)">{{ e.mode }}</span>
                                <span v-if="e.note" class="note-text">{{ e.note }}</span>
                            </div>
                            <div class="amt" :class="e.type">
                                <template v-if="e.type === 'staff' || e.type === 'expense' || e.type === 'shortage'">&minus; </template>{{ fmt(amt(e)) }}
                            </div>
                            <button class="del" title="Delete" @click="deleteEntry(e.id)">&#10005;</button>
                        </div>
                    </div>
                </template>

                <div class="party-strip" v-if="udhaarByParty.length">
                    <span class="ms-label">Udhaar (Bakaya) by name</span>
                    <span class="ms-item" v-for="p in udhaarByParty" :key="p.name">
                        <span class="party-name">{{ p.name }}</span>
                        <b>{{ fmt(p.total) }}</b>
                    </span>
                </div>

                <div class="grand" v-if="grouped.length">
                    <div class="g-item"><b>Total Received</b><span class="g-val">{{ fmt(totReceived) }}</span></div>
                    <div class="g-item"><b>Cash</b><span class="g-val">{{ fmt(totCash) }}</span></div>
                    <div class="g-item"><b>Online (UPI)</b><span class="g-val">{{ fmt(totOnline) }}</span></div>
                    <div class="g-item"><b>Staff Paid</b><span class="g-val">{{ fmt(totStaff) }}</span></div>
                    <div class="g-item"><b>Kharcha (Other)</b><span class="g-val">{{ fmt(totExpense) }}</span></div>
                    <div class="g-item"><b>Total Spent</b><span class="g-val">{{ fmt(totSpent) }}</span></div>
                    <div class="g-item"><b>Udhaar (Bakaya)</b><span class="g-val">{{ fmt(totUdhaar) }}</span></div>
                    <div class="g-item"><b>In Hand (Rec &minus; Spent)</b><span class="g-val">{{ fmt(inHand) }}</span></div>
                </div>
            </section>
        </div>
 </div>
 </div>

</template>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap');

/* colour tokens live on .wrap so they inherit to all children */
.wrap {
    --leather: #6b4226; --leather-dark: #4a2d18;
    --paper: #ffffff; --panel-alt: #faf9f7; --border: #e6e2da;
    --ink: #262019; --ink-soft: #7a7268;
    --green: #2f8f4e; --blue: #2563eb; --rust: #c2410c; --amber: #b45309;
    --shadow: 0 1px 2px rgba(20, 14, 6, 0.04), 0 10px 24px rgba(20, 14, 6, 0.07);
    margin: 0 auto; color: var(--ink);
    font-family: 'Inter', sans-serif;
}

/* ---------- cover ---------- */
.cover {
    background: linear-gradient(155deg, var(--leather) 0%, var(--leather-dark) 100%);
    color: #f7ede0; border-radius: 12px; padding: 26px 30px; box-shadow: var(--shadow);
}
.kicker { font-size: 11px; font-weight: 600; letter-spacing: 0.14em; text-transform: uppercase; color: #d9b98a; margin: 0 0 6px; }
.brand { font-family: 'Fraunces', Georgia, serif; font-weight: 700; font-size: clamp(26px, 5vw, 34px); margin: 0; color: #fbf3e5; letter-spacing: -0.01em; }
.sub { margin: 8px 0 0; font-size: 13.5px; color: #e4cba4; }

/* ---------- summary cards ---------- */
.cards { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin: 16px 0; }
.card { position: relative; background: var(--paper); border: 1px solid var(--border); border-radius: 10px; padding: 14px 16px; box-shadow: var(--shadow); }
.card::before { content: ""; position: absolute; left: 0; top: 10px; bottom: 10px; width: 3px; border-radius: 2px; background: var(--ink-soft); opacity: 0.3; }
.card.cash::before { background: var(--green); opacity: 0.9; }
.card.online::before { background: var(--blue); opacity: 0.9; }
.card.in::before { background: var(--leather); opacity: 0.9; }
.card .lbl { font-size: 11px; color: var(--ink-soft); text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 6px; font-weight: 600; }
.card .val { font-size: clamp(17px, 3.2vw, 21px); font-weight: 700; white-space: nowrap; font-variant-numeric: tabular-nums; }
.card.in .val { color: var(--leather); }
.card.cash .val { color: var(--green); }
.card.online .val { color: var(--blue); }

/* ---------- panels ---------- */
.panel { background: var(--paper); border: 1px solid var(--border); border-radius: 12px; box-shadow: var(--shadow); margin-bottom: 16px; }
.panel-title { font-size: 15.5px; font-weight: 700; margin: 0; padding: 16px 20px; border-bottom: 1px solid var(--border); color: var(--ink); }

.form { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; padding: 20px; }
.field { display: flex; flex-direction: column; gap: 6px; }
.field.note-after-mode { grid-column: 2 / -1; }
.field.note-full { grid-column: 1 / -1; }
.field label { font-size: 11.5px; color: var(--ink-soft); font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; }
.form input, .form select { font: inherit; font-size: 15px; color: var(--ink); background: var(--panel-alt); border: 1px solid var(--border); border-radius: 8px; padding: 10px 12px; width: 100%; }
.form input:focus, .form select:focus { outline: none; border-color: var(--leather); box-shadow: 0 0 0 3px rgba(107, 66, 38, 0.14); }
.actions { grid-column: 1 / -1; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
.btn { font: inherit; font-weight: 600; font-size: 15px; color: #fff; cursor: pointer; background: var(--leather); border: none; border-radius: 8px; padding: 11px 24px; }
.btn:disabled { opacity: 0.6; cursor: default; }
.btn:hover:not(:disabled) { background: var(--leather-dark); }
.btn:focus-visible { outline: 2px solid var(--leather); outline-offset: 2px; }
.msg { font-size: 13px; color: var(--rust); min-height: 16px; }

/* ---------- ledger head ---------- */
.ledger-head { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; padding: 16px 20px; border-bottom: 1px solid var(--border); }
.ledger-head .panel-title { padding: 0; border: none; }
.ledger-head select { width: auto; min-width: 160px; font: inherit; background: var(--panel-alt); border: 1px solid var(--border); border-radius: 8px; padding: 9px 12px; color: var(--ink); }

/* ---------- ledger rows ---------- */
.col-head {
    display: grid; grid-template-columns: 56px 1fr 150px 40px; gap: 8px; padding: 9px 20px;
    font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: var(--ink-soft); font-weight: 600;
    background: var(--panel-alt); border-bottom: 1px solid var(--border);
}
.col-head span:nth-child(3) { text-align: right; }
.daygroup { border-bottom: 1px solid var(--border); }
.daygroup:last-of-type { border-bottom: none; }
.day-bar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; background: var(--panel-alt); padding: 9px 20px; }
.day-date { display: flex; flex-direction: column; align-items: center; line-height: 1; width: 40px; flex-shrink: 0; }
.day-num { font-weight: 700; font-size: 15px; color: var(--ink); }
.day-mon { font-size: 9.5px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--ink-soft); margin-top: 1px; }
.day-tot { font-size: 12.5px; color: var(--ink-soft); font-variant-numeric: tabular-nums; }
.day-tot b.j { color: var(--green); } .day-tot b.k { color: var(--rust); } .day-tot b.u { color: var(--amber); }
.row { display: grid; grid-template-columns: 56px 1fr 150px 40px; gap: 8px; align-items: center; padding: 10px 20px; font-size: 14.5px; border-top: 1px solid var(--border); transition: background 0.12s; }
.row:first-of-type { border-top: none; }
.row:hover { background: var(--panel-alt); }
.row-date { width: 1px; }
.note { min-width: 0; overflow-wrap: anywhere; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.note-text { min-width: 0; color: var(--ink-soft); }
.type-chip, .mode-chip { display: inline-block; font-size: 10.5px; font-weight: 600; padding: 2px 8px; border-radius: 5px; white-space: nowrap; }
.type-chip.sale { background: #e5f4e9; color: #1f7a3d; }
.type-chip.staff { background: #f0e8dc; color: #7a4a1e; }
.type-chip.expense { background: #f7e5da; color: #9a4a1f; }
.type-chip.udhaar { background: #faf0d9; color: #96650f; }
.type-chip.shortage { background: #fbe1da; color: #a3401f; }
.mode-chip.cash { background: #e5f4e9; color: #1f7a3d; }
.mode-chip.upi { background: #e2ecfb; color: #1d4ed8; }
.mode-chip.other { background: #f1ede4; color: #6d5c4b; }
.amt { text-align: right; font-variant-numeric: tabular-nums; font-weight: 700; }
.amt.sale { color: var(--green); }
.amt.staff, .amt.shortage, .amt.expense { color: var(--rust); }
.amt.udhaar { color: var(--amber); }
.del { border: none; background: transparent; cursor: pointer; color: #c4bcae; font-size: 15px; line-height: 1; padding: 5px; border-radius: 6px; justify-self: end; }
.del:hover { color: var(--rust); background: rgba(194, 65, 12, 0.08); }
.del:focus-visible { outline: 2px solid var(--leather); outline-offset: 1px; }
.empty-state { padding: 44px 20px; text-align: center; color: var(--ink-soft); font-size: 14px; }

/* ---------- udhaar strip ---------- */
.party-strip { display: flex; align-items: center; flex-wrap: wrap; gap: 8px 12px; padding: 13px 20px; border-top: 1px solid var(--border); background: var(--panel-alt); }
.party-strip .ms-label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: var(--ink-soft); font-weight: 600; margin-right: 4px; }
.party-strip .ms-item { display: inline-flex; align-items: center; gap: 7px; font-size: 13.5px; background: #faf0d9; border: 1px solid #f0e2b8; border-radius: 999px; padding: 3px 12px; }
.party-strip .party-name { color: #7a5a12; font-weight: 600; }
.party-strip .ms-item b { color: var(--amber); font-weight: 700; }

/* ---------- grand totals ---------- */
.grand { display: flex; justify-content: space-between; flex-wrap: wrap; gap: 14px 20px; padding: 18px 20px; background: var(--leather-dark); color: #f5e9d8; border-radius: 0 0 11px 11px; }
.g-item b { color: #d9b98a; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; }
.g-val { font-size: 16px; font-weight: 700; display: block; margin-top: 3px; font-variant-numeric: tabular-nums; }

@media (max-width: 640px) {
    .cards { grid-template-columns: repeat(2, 1fr); }
    .form { grid-template-columns: 1fr 1fr; }
    .field:first-child, .field.note-after-mode, .field.note-full { grid-column: 1 / -1; }
    .col-head, .row { grid-template-columns: 36px 1fr 100px 30px; }
    .day-date { width: 28px; }
}
</style>