<template>
  <div class="tag-input-root">
    <label v-if="label" class="fv-label">{{ label }}</label>
    <p v-if="hint" class="text-xs fv-text-muted mb-2">{{ hint }}</p>

    <div
      class="rounded-lg px-2 py-2 min-h-[2.75rem] flex flex-wrap gap-2 items-center"
      style="background:var(--fv-bg-input,#0D1E38); border:1px solid var(--fv-border,#1B3558);"
      @click="focusInput"
    >
      <span
        v-for="tag in modelValue"
        :key="tag.id"
        class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-medium"
        style="background:rgba(20,144,168,0.18); border:1px solid rgba(20,144,168,0.35); color:#48C4D8;"
      >
        {{ tag.name }}
        <button
          type="button"
          class="leading-none opacity-70 hover:opacity-100"
          style="color:inherit;"
          @click.stop="removeTag(tag.id)"
        >
          ×
        </button>
      </span>

      <input
        ref="inputRef"
        v-model="query"
        type="text"
        class="flex-1 min-w-[8rem] bg-transparent outline-none text-sm fv-text-primary py-0.5"
        style="border:none;"
        :placeholder="placeholder"
        autocomplete="off"
        @keydown="onKeydown"
        @focus="onFocus"
        @blur="onBlur"
      />
    </div>

    <div
      v-if="showDropdown && (suggestions.length || (query.trim() && !exactMatch))"
      class="relative z-30 mt-1 rounded-lg overflow-hidden shadow-xl"
      style="background:var(--fv-bg-modal,#0E1E34); border:1px solid var(--fv-border,#21518B);"
    >
      <button
        v-for="s in suggestions"
        :key="s.id"
        type="button"
        class="w-full text-left px-3 py-2 text-sm fv-text-primary hover:bg-[rgba(20,144,168,0.12)]"
        @mousedown.prevent="selectSuggestion(s)"
      >
        {{ s.name }}
      </button>
      <button
        v-if="query.trim() && !exactMatch && allowCreate"
        type="button"
        class="w-full text-left px-3 py-2 text-xs fv-text-muted hover:bg-[rgba(20,144,168,0.08)]"
        style="border-top:1px solid var(--fv-border,#1B3558);"
        @mousedown.prevent="createAndAdd"
      >
        Create “{{ query.trim() }}”
      </button>
      <p v-if="!suggestions.length && !query.trim()" class="px-3 py-2 text-xs fv-text-muted">Type to search tags…</p>
    </div>

    <p v-if="error" class="text-xs mt-1" style="color:#f87171;">{{ error }}</p>
  </div>
</template>

<script setup>
import { ref, watch, computed, onBeforeUnmount } from 'vue'

const props = defineProps({
  companyId: { type: [Number, String], required: true },
  label: { type: String, default: 'Description' },
  hint: { type: String, default: 'Add tags to describe this property. Search existing tags or create new ones.' },
  placeholder: { type: String, default: 'Search or type a new tag…' },
  modelValue: { type: Array, default: () => [] },
  debounceMs: { type: Number, default: 300 },
  allowCreate: { type: Boolean, default: true },
})

const emit = defineEmits(['update:modelValue'])

const query = ref('')
const suggestions = ref([])
const showDropdown = ref(false)
const loading = ref(false)
const error = ref('')
const inputRef = ref(null)

let debounceTimer = null

const selectedIds = computed(() => new Set(props.modelValue.map((t) => t.id)))

const exactMatch = computed(() => {
  const q = query.value.trim().toLowerCase()
  if (!q) return false
  return props.modelValue.some((t) => t.name.toLowerCase() === q)
    || suggestions.value.some((s) => s.name.toLowerCase() === q)
})

function csrfHeaders() {
  const headers = {}
  const meta = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
  if (meta) {
    headers['X-CSRF-TOKEN'] = meta
    return headers
  }
  const row = document.cookie.split('; ').find((r) => r.startsWith('XSRF-TOKEN='))
  if (row) {
    const raw = row.split('=').slice(1).join('=')
    headers['X-XSRF-TOKEN'] = decodeURIComponent(raw)
  }
  return headers
}

function focusInput() {
  inputRef.value?.focus()
}

function removeTag(id) {
  emit(
    'update:modelValue',
    props.modelValue.filter((t) => t.id !== id)
  )
}

function selectSuggestion(tag) {
  if (selectedIds.value.has(tag.id)) {
    query.value = ''
    suggestions.value = []
    return
  }
  emit('update:modelValue', [...props.modelValue, { id: tag.id, name: tag.name }])
  query.value = ''
  suggestions.value = []
  showDropdown.value = true
}

async function createAndAdd() {
  const name = query.value.trim()
  if (!name || exactMatch.value) return
  error.value = ''
  loading.value = true
  try {
    const res = await fetch(route('company.tags.store', props.companyId), {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...csrfHeaders(),
      },
      body: JSON.stringify({ name }),
    })
    const body = await res.json().catch(() => ({}))
    if (!res.ok) {
      error.value = body.message || 'Could not create tag.'
      return
    }
    const tag = body.tag
    if (tag && !selectedIds.value.has(tag.id)) {
      emit('update:modelValue', [...props.modelValue, { id: tag.id, name: tag.name }])
    }
    query.value = ''
    suggestions.value = []
  } catch {
    error.value = 'Network error.'
  } finally {
    loading.value = false
  }
}

async function runSearch() {
  const q = query.value.trim()
  error.value = ''
  loading.value = true
  try {
    const url = route('company.tags.search', props.companyId) + (q ? `?q=${encodeURIComponent(q)}` : '')
    const res = await fetch(url, {
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
    })
    if (!res.ok) {
      suggestions.value = []
      return
    }
    const body = await res.json()
    const rows = body.data || []
    suggestions.value = rows.filter((s) => !selectedIds.value.has(s.id))
  } catch {
    suggestions.value = []
  } finally {
    loading.value = false
  }
}

function scheduleSearch() {
  if (debounceTimer) clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => {
    debounceTimer = null
    runSearch()
  }, props.debounceMs)
}

watch(query, () => {
  scheduleSearch()
})

function onKeydown(e) {
  if (e.key === 'Enter') {
    e.preventDefault()
    if (suggestions.value.length === 1) {
      selectSuggestion(suggestions.value[0])
    } else if (props.allowCreate && query.value.trim() && !exactMatch.value) {
      createAndAdd()
    }
  } else if (e.key === 'Escape') {
    showDropdown.value = false
  } else if (e.key === 'Backspace' && !query.value && props.modelValue.length) {
    const copy = [...props.modelValue]
    copy.pop()
    emit('update:modelValue', copy)
  }
}

function onFocus() {
  showDropdown.value = true
  runSearch()
}

function onBlur() {
  setTimeout(() => {
    showDropdown.value = false
  }, 150)
}

onBeforeUnmount(() => {
  if (debounceTimer) clearTimeout(debounceTimer)
})
</script>

<style scoped>
.tag-input-root .fv-label {
  display: block;
  font-size: 0.75rem;
  color: var(--fv-text-muted, #6B96B8);
  margin-bottom: 0.375rem;
  font-weight: 500;
}
</style>
