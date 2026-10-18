<template>
    <div class="coa-section">
        <div class="coa-section-header" :class="'coa-section-header-' + color">
            <span class="coa-section-title">{{ title }}</span>
            <span class="coa-section-total">{{ totalLabel }}: {{ fmt(total) }}</span>
        </div>
        <slot />
    </div>
</template>

<script setup>
defineProps({
    title:      String,
    color:      { type: String, default: 'blue' },
    total:      { type: Number, default: 0 },
    totalLabel: String,
})
const fmt = (v) => new Intl.NumberFormat('en-US', { minimumFractionDigits: 2 }).format(v || 0) + ' EGP'
</script>

<style scoped>
.coa-section { border: 1px solid var(--cv-border); border-radius: var(--cv-radius); overflow: hidden; }
.coa-section-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 0.625rem 1rem; font-size: 0.75rem; font-weight: 700;
    letter-spacing: 0.1em; text-transform: uppercase;
}
.coa-section-header-blue  { background: var(--cv-blue-light);  color: var(--cv-blue-text); }
.coa-section-header-red   { background: var(--cv-danger-bg);   color: var(--cv-danger-text); }
.coa-section-header-green { background: var(--cv-green-dim);   color: var(--cv-green-bright); }
.coa-section-header-amber { background: var(--cv-amber-light); color: var(--cv-amber-text); }
.coa-section-title { font-weight: 700; }
.coa-section-total { font-weight: 500; font-size: 0.8125rem; text-transform: none; letter-spacing: 0; }
</style>