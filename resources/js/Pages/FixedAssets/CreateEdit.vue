<template>
  <AuthenticatedLayout>
  <div :dir="isRtl ? 'rtl' : 'ltr'">

    <!-- ── Hero ─────────────────────────────────────────────────── -->
    <div class="cv-page-hero">
      <div class="cv-page-hero-eyebrow">{{ isRtl ? 'الأصول الثابتة' : 'Fixed Assets' }}</div>
      <div class="cv-page-hero-title" style="display:flex; align-items:center; gap: 0.75rem">
        <div class="preview-avatar" :style="{ background: categoryGradient(form.category) }">
          <span v-html="categoryIcon(form.category)" style="display:flex;align-items:center;justify-content:center;width:22px;height:22px"></span>
        </div>
        <span>{{ previewName || (isRtl ? (mode === 'create' ? 'أصل جديد' : 'تعديل أصل') : (mode === 'create' ? 'New Asset' : 'Edit Asset')) }}</span>
      </div>
      <div class="cv-page-hero-sub">
        <span v-if="form.asset_code" style="color: var(--cv-amber-text)">{{ form.asset_code }}</span>
        <span v-if="form.asset_code && form.category"> · </span>
        <span v-if="form.category">{{ categoryLabel(form.category) }}</span>
      </div>
      <div class="cv-page-hero-actions">
        <Link :href="route('fixed-assets.index')" class="cv-btn cv-btn-ghost">
          {{ isRtl ? 'إلغاء' : 'Cancel' }}
        </Link>
        <button class="cv-btn cv-btn-amber" :class="{ 'cv-btn-loading': form.processing }" @click="submit">
          <svg v-if="!form.processing" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>
          </svg>
          {{ isRtl ? (mode === 'create' ? 'حفظ الأصل' : 'تحديث الأصل') : (mode === 'create' ? 'Save Asset' : 'Update Asset') }}
        </button>
      </div>
    </div>

    <div class="cv-page">

      <!-- ── Financial Lock Warning (edit mode) ─────────────────── -->
      <div v-if="mode === 'edit' && hasPostedLines" class="cv-alert-warning mb-5" style="background: var(--cv-warning-bg); border: 1px solid var(--cv-warning-border); border-radius: var(--cv-radius); padding: 0.75rem 1rem; font-size: 0.8125rem; color: var(--cv-warning-text); display:flex; align-items:center; gap: 0.5rem">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
          <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
        </svg>
        {{ isRtl ? 'تم ترحيل قسط إهلاك — الحقول المالية وحسابات الإهلاك مقفلة.' : 'A depreciation period has been posted — financial fields and depreciation accounts are locked.' }}
      </div>

      <!-- Global error -->
      <div v-if="form.errors?.general" class="cv-alert-danger mb-4" style="background: var(--cv-danger-bg); border: 1px solid var(--cv-danger-border); border-radius: var(--cv-radius); padding: 0.75rem 1rem; font-size: 0.8125rem; color: var(--cv-danger-text)">
        {{ form.errors.general }}
      </div>

      <div class="flex flex-col gap-5">

        <!-- ══ Section 1 — Identity ═══════════════════════════════ -->
        <div class="cv-card">
          <div class="section-header">
            <div class="section-num">{{ isRtl ? '١' : '1' }}</div>
            <div>
              <div class="section-title">{{ isRtl ? 'بيانات الهوية' : 'Identity' }}</div>
              <div class="section-sub">{{ isRtl ? 'الاسم والكود والفئة والمزرعة' : 'Name, code, category and farm' }}</div>
            </div>
          </div>

          <div class="form-grid">
            <!-- Bilingual Name -->
            <div class="cv-field" style="grid-column: 1 / -1">
              <label class="cv-label">
                {{ isRtl ? 'اسم الأصل' : 'Asset Name' }}
                <span style="color: var(--cv-danger-text)">*</span>
              </label>
              <!-- AR visible -->
              <input
                v-show="isRtl"
                v-model="form.name_ar"
                type="text"
                class="cv-input"
                :class="{ 'cv-input-error': form.errors?.name_ar || form.errors?.name_en }"
                :placeholder="isRtl ? 'اسم الأصل بالعربية' : ''"
                dir="rtl"
              />
              <!-- EN visible -->
              <input
                v-show="!isRtl"
                v-model="form.name_en"
                type="text"
                class="cv-input"
                :class="{ 'cv-input-error': form.errors?.name_en || form.errors?.name_ar }"
                :placeholder="isRtl ? '' : 'Asset name in English'"
                dir="ltr"
              />
              <!-- Counterpart hidden but always submitted -->
              <input v-show="false" v-model="form.name_en" type="text" />
              <input v-show="false" v-model="form.name_ar" type="text" />
              <div v-if="form.errors?.name_en || form.errors?.name_ar" class="cv-field-error">
                {{ form.errors?.name_en || form.errors?.name_ar }}
              </div>
            </div>

            <!-- Asset Code -->
            <div class="cv-field">
              <label class="cv-label">{{ isRtl ? 'كود الأصل' : 'Asset Code' }} <span style="color:var(--cv-danger-text)">*</span></label>
              <input v-model="form.asset_code" type="text" class="cv-input" :class="{ 'cv-input-error': form.errors?.asset_code }" :placeholder="isRtl ? 'مثال: FA-001' : 'e.g. FA-001'" />
              <div v-if="form.errors?.asset_code" class="cv-field-error">{{ form.errors.asset_code }}</div>
            </div>

            <!-- Category -->
            <div class="cv-field">
              <label class="cv-label">{{ isRtl ? 'الفئة' : 'Category' }} <span style="color:var(--cv-danger-text)">*</span></label>
              <select v-model="form.category" class="cv-select" :class="{ 'cv-input-error': form.errors?.category }">
                <option value="">{{ isRtl ? 'اختر الفئة' : 'Select category' }}</option>
                <option v-for="c in categories" :key="c.value" :value="c.value">{{ isRtl ? c.ar : c.en }}</option>
              </select>
              <div v-if="form.errors?.category" class="cv-field-error">{{ form.errors.category }}</div>
            </div>

            <!-- Farm -->
            <div class="cv-field">
              <label class="cv-label">{{ isRtl ? 'المزرعة' : 'Farm' }}</label>
              <select v-model="form.farm_id" class="cv-select">
                <option value="">{{ isRtl ? 'بدون مزرعة (أصل الشركة)' : 'No farm (company asset)' }}</option>
                <option v-for="f in farms" :key="f.id" :value="f.id">{{ n(f) }}</option>
              </select>
            </div>

            <!-- Status (edit only) -->
            <div v-if="mode === 'edit'" class="cv-field">
              <label class="cv-label">{{ isRtl ? 'الحالة' : 'Status' }}</label>
              <select v-model="form.status" class="cv-select" :disabled="asset?.status === 'disposed'">
                <option value="active">{{ isRtl ? 'نشط' : 'Active' }}</option>
                <option value="fully_depreciated">{{ isRtl ? 'مستهلك بالكامل' : 'Fully Depreciated' }}</option>
                <option value="impaired">{{ isRtl ? 'مُخفَّض القيمة' : 'Impaired' }}</option>
                <option value="disposed" disabled>{{ isRtl ? 'مُتصرف فيه' : 'Disposed' }}</option>
              </select>
            </div>
          </div>
        </div>

        <!-- ══ Section 2 — Dates ══════════════════════════════════ -->
        <div class="cv-card">
          <div class="section-header">
            <div class="section-num">{{ isRtl ? '٢' : '2' }}</div>
            <div>
              <div class="section-title">{{ isRtl ? 'التواريخ' : 'Dates' }}</div>
              <div class="section-sub">{{ isRtl ? 'تاريخ الشراء وبدء الاستخدام' : 'Purchase date and in-service date' }}</div>
            </div>
          </div>
          <div class="form-grid">
            <div class="cv-field">
              <label class="cv-label">{{ isRtl ? 'تاريخ الشراء' : 'Purchase Date' }} <span style="color:var(--cv-danger-text)">*</span></label>
              <input v-model="form.purchase_date" type="date" class="cv-input" :class="{ 'cv-input-error': form.errors?.purchase_date }" />
              <div v-if="form.errors?.purchase_date" class="cv-field-error">{{ form.errors.purchase_date }}</div>
            </div>
            <div class="cv-field">
              <label class="cv-label">{{ isRtl ? 'تاريخ بدء الاستخدام' : 'In-Service Date' }}</label>
              <input v-model="form.in_service_date" type="date" class="cv-input" />
              <div style="font-size:0.7rem; color: var(--cv-text-muted); margin-top: 3px">{{ isRtl ? 'اختياري — يجب أن يكون بعد أو يساوي تاريخ الشراء' : 'Optional — must be ≥ purchase date' }}</div>
            </div>
          </div>
        </div>

        <!-- ══ Section 3 — Financials ════════════════════════════ -->
        <div class="cv-card" :class="{ 'section-locked': hasPostedLines }">
          <div class="section-header">
            <div class="section-num">{{ isRtl ? '٣' : '3' }}</div>
            <div>
              <div class="section-title">{{ isRtl ? 'البيانات المالية' : 'Financials' }}</div>
              <div class="section-sub">{{ isRtl ? 'التكلفة وطريقة الإهلاك' : 'Cost and depreciation method' }}</div>
            </div>
            <div v-if="hasPostedLines" class="lock-badge">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              {{ isRtl ? 'مقفل' : 'Locked' }}
            </div>
          </div>

          <div class="form-grid">
            <!-- Purchase Cost -->
            <div class="cv-field">
              <label class="cv-label">{{ isRtl ? 'تكلفة الشراء (ج.م)' : 'Purchase Cost (EGP)' }} <span style="color:var(--cv-danger-text)">*</span></label>
              <div class="cv-input-group">
                <span class="cv-input-prefix">{{ isRtl ? 'ج.م' : 'EGP' }}</span>
                <input v-model="form.purchase_cost" type="number" step="0.01" min="0.01" class="cv-input" :class="{ 'cv-input-error': form.errors?.purchase_cost }" :disabled="hasPostedLines" />
              </div>
              <div v-if="form.errors?.purchase_cost" class="cv-field-error">{{ form.errors.purchase_cost }}</div>
            </div>

            <!-- Salvage Value -->
            <div class="cv-field">
              <label class="cv-label">{{ isRtl ? 'قيمة الخردة (ج.م)' : 'Salvage Value (EGP)' }}</label>
              <div class="cv-input-group">
                <span class="cv-input-prefix">{{ isRtl ? 'ج.م' : 'EGP' }}</span>
                <input v-model="form.salvage_value" type="number" step="0.01" min="0" class="cv-input" :disabled="hasPostedLines || isLand" />
              </div>
              <div style="font-size:0.7rem; color: var(--cv-text-muted); margin-top:3px">
                <span v-if="isLand" style="color: var(--cv-warning-text)">{{ isRtl ? 'الأراضي لا تُهلك — القيمة المتبقية دائماً صفر' : 'Land is not depreciated — salvage value is always 0' }}</span>
                <span v-else>{{ isRtl ? 'القيمة المتبقية بعد انتهاء العمر الإنتاجي' : 'Residual value at end of useful life' }}</span>
              </div>
            </div>

            <!-- Depreciation Method — full width -->
            <div class="cv-field" style="grid-column: 1 / -1">
              <label class="cv-label">{{ isRtl ? 'طريقة الإهلاك' : 'Depreciation Method' }} <span style="color:var(--cv-danger-text)">*</span></label>
              <!-- Land info banner -->
              <div v-if="isLand" style="display:flex; align-items:center; gap:0.5rem; background: var(--cv-green-dim); border: 1px solid var(--cv-green-border); border-radius: var(--cv-radius); padding: 0.6rem 0.875rem; font-size:0.8rem; color: var(--cv-success-text); margin-bottom: 0.5rem">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                {{ isRtl ? 'الأراضي أصول غير قابلة للإهلاك — طريقة الإهلاك مقفلة على "بدون إهلاك"' : 'Land is a non-depreciable asset — depreciation method is locked to No Depreciation' }}
              </div>
              <div class="method-toggle">
                <button
                  v-for="m in depMethods"
                  :key="m.value"
                  type="button"
                  class="method-btn"
                  :class="{ active: form.depreciation_method === m.value }"
                  :disabled="hasPostedLines || isLand"
                  @click="form.depreciation_method = m.value"
                >
                  <span v-html="m.icon" style="display:flex;align-items:center;justify-content:center;width:16px;height:16px"></span>
                  {{ isRtl ? m.ar : m.en }}
                </button>
              </div>
            </div>

            <!-- Useful Life (SL only) -->
            <div v-if="form.depreciation_method === 'straight_line'" class="cv-field">
              <label class="cv-label">{{ isRtl ? 'العمر الإنتاجي (شهور)' : 'Useful Life (months)' }} <span style="color:var(--cv-danger-text)">*</span></label>
              <input v-model="form.useful_life_months" type="number" min="1" max="600" class="cv-input" :class="{ 'cv-input-error': form.errors?.useful_life_months }" :disabled="hasPostedLines" :placeholder="isRtl ? 'مثال: 60 شهر = 5 سنوات' : 'e.g. 60 = 5 years'" />
              <div v-if="form.errors?.useful_life_months" class="cv-field-error">{{ form.errors.useful_life_months }}</div>
              <div v-if="form.purchase_cost && form.salvage_value !== null && form.useful_life_months" style="font-size:0.7rem; color: var(--cv-success-text); margin-top:3px">
                {{ isRtl ? 'القسط الشهري:' : 'Monthly charge:' }}
                {{ fmtMonthlySL }} {{ isRtl ? 'ج.م' : 'EGP' }}
              </div>
            </div>

            <!-- DB Rate (DB only) -->
            <div v-if="form.depreciation_method === 'declining_balance'" class="cv-field">
              <label class="cv-label">{{ isRtl ? 'معدل الإهلاك السنوي (0 - 1)' : 'Annual DB Rate (0 – 1)' }} <span style="color:var(--cv-danger-text)">*</span></label>
              <input v-model="form.db_rate" type="number" step="0.0001" min="0.0001" max="1" class="cv-input" :class="{ 'cv-input-error': form.errors?.db_rate }" :disabled="hasPostedLines" placeholder="e.g. 0.25" />
              <div v-if="form.errors?.db_rate" class="cv-field-error">{{ form.errors.db_rate }}</div>
              <div v-if="form.db_rate" style="font-size:0.7rem; color: var(--cv-text-muted); margin-top:3px">
                = {{ Math.round(Number(form.db_rate) * 100) }}% {{ isRtl ? 'سنوياً' : 'per year' }}
              </div>
            </div>

            <!-- Depreciable base preview -->
            <div v-if="form.purchase_cost && form.depreciation_method !== 'none'" style="grid-column: 1 / -1; background: var(--cv-bg-card-alt); border: 1px solid var(--cv-border); border-radius: var(--cv-radius); padding: 0.75rem 1rem; display: flex; gap: 1.5rem; flex-wrap: wrap">
              <div>
                <div style="font-size:0.6rem; color: var(--cv-text-muted); text-transform: uppercase; letter-spacing: 0.08em">{{ isRtl ? 'التكلفة' : 'Cost' }}</div>
                <div style="font-size:0.875rem; font-weight:700; color: var(--cv-blue-text)">{{ fmt(form.purchase_cost) }}</div>
              </div>
              <div style="color: var(--cv-text-muted); align-self:center">−</div>
              <div>
                <div style="font-size:0.6rem; color: var(--cv-text-muted); text-transform: uppercase; letter-spacing: 0.08em">{{ isRtl ? 'الخردة' : 'Salvage' }}</div>
                <div style="font-size:0.875rem; font-weight:700; color: var(--cv-text-secondary)">{{ fmt(form.salvage_value || 0) }}</div>
              </div>
              <div style="color: var(--cv-text-muted); align-self:center">=</div>
              <div>
                <div style="font-size:0.6rem; color: var(--cv-text-muted); text-transform: uppercase; letter-spacing: 0.08em">{{ isRtl ? 'وعاء الإهلاك' : 'Depreciable Base' }}</div>
                <div style="font-size:0.875rem; font-weight:700; color: var(--cv-success-text)">{{ fmt((Number(form.purchase_cost) || 0) - (Number(form.salvage_value) || 0)) }}</div>
              </div>
            </div>
          </div>
        </div>

        <!-- ══ Section 4 — Accounts ══════════════════════════════ -->
        <div class="cv-card" :class="{ 'section-locked': hasPostedLines }">
          <div class="section-header">
            <div class="section-num">{{ isRtl ? '٤' : '4' }}</div>
            <div>
              <div class="section-title">{{ isRtl ? 'الحسابات المحاسبية' : 'Accounting Accounts' }}</div>
              <div class="section-sub">{{ isRtl ? 'حسابات دفتر الأستاذ للأصل والإهلاك' : 'Ledger accounts for asset and depreciation' }}</div>
            </div>
            <div v-if="hasPostedLines" class="lock-badge">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              {{ isRtl ? 'مقفل' : 'Locked' }}
            </div>
          </div>
          <div class="form-grid">
            <!-- Asset Account -->
            <div class="cv-field">
              <label class="cv-label">{{ isRtl ? 'حساب الأصل' : 'Asset Account' }} <span style="color:var(--cv-danger-text)">*</span></label>
              <select v-model="form.asset_account_id" class="cv-select" :class="{ 'cv-input-error': form.errors?.asset_account_id }" :disabled="hasPostedLines">
                <option value="">{{ isRtl ? 'اختر حساب الأصل' : 'Select asset account' }}</option>
                <option v-for="a in assetAccounts" :key="a.id" :value="a.id">{{ a.code }} — {{ n(a) }}</option>
              </select>
              <div v-if="form.errors?.asset_account_id" class="cv-field-error">{{ form.errors.asset_account_id }}</div>
            </div>

            <!-- Accum Dep Account — hidden for land, auto-filled by watch -->
            <div v-show="!isLand" class="cv-field">
              <label class="cv-label">{{ isRtl ? 'حساب الإهلاك المتراكم' : 'Accum. Dep. Account' }} <span style="color:var(--cv-danger-text)">*</span></label>
              <select v-model="form.acc_dep_account_id" class="cv-select" :class="{ 'cv-input-error': form.errors?.acc_dep_account_id }" :disabled="hasPostedLines">
                <option value="">{{ isRtl ? 'اختر الحساب' : 'Select account' }}</option>
                <option v-for="a in contraAccounts" :key="a.id" :value="a.id">{{ a.code }} — {{ n(a) }}</option>
              </select>
              <div v-if="form.errors?.acc_dep_account_id" class="cv-field-error">{{ form.errors.acc_dep_account_id }}</div>
            </div>

            <!-- Dep Expense Account — hidden for land, auto-filled by watch -->
            <div v-show="!isLand" class="cv-field">
              <label class="cv-label">{{ isRtl ? 'حساب مصروف الإهلاك' : 'Dep. Expense Account' }} <span style="color:var(--cv-danger-text)">*</span></label>
              <select v-model="form.dep_expense_account_id" class="cv-select" :class="{ 'cv-input-error': form.errors?.dep_expense_account_id }" :disabled="hasPostedLines">
                <option value="">{{ isRtl ? 'اختر الحساب' : 'Select account' }}</option>
                <option v-for="a in expenseAccounts" :key="a.id" :value="a.id">{{ a.code }} — {{ n(a) }}</option>
              </select>
              <div v-if="form.errors?.dep_expense_account_id" class="cv-field-error">{{ form.errors.dep_expense_account_id }}</div>
            </div>

            <!-- Payment Account — always visible, locked after first JE posted -->
            <div class="cv-field" style="grid-column: 1 / -1">
              <label class="cv-label">
                {{ isRtl ? 'حساب الدفع (المقابل في قيد الشراء)' : 'Payment Account (credit side of purchase entry)' }}
                <span style="color:var(--cv-danger-text)">*</span>
              </label>
              <select
                v-model="form.payment_account_id"
                class="cv-select"
                :class="{ 'cv-input-error': form.errors?.payment_account_id }"
              >
                <option value="">{{ isRtl ? 'اختر طريقة الدفع' : 'Select payment method' }}</option>
                <optgroup :label="isRtl ? 'نقد' : 'Cash'">
                  <option v-for="a in paymentAccounts.filter(a => a.code?.toString().startsWith('14'))" :key="a.id" :value="a.id">
                    {{ a.code }} — {{ n(a) }}
                  </option>
                </optgroup>
                <optgroup :label="isRtl ? 'بنك' : 'Bank'">
                  <option v-for="a in paymentAccounts.filter(a => a.code?.toString().startsWith('15'))" :key="a.id" :value="a.id">
                    {{ a.code }} — {{ n(a) }}
                  </option>
                </optgroup>
                <optgroup :label="isRtl ? 'ذمم دائنة / مورد' : 'Payables / Supplier'">
                  <option v-for="a in paymentAccounts.filter(a => !a.code?.toString().startsWith('14') && !a.code?.toString().startsWith('15'))" :key="a.id" :value="a.id">
                    {{ a.code }} — {{ n(a) }}
                  </option>
                </optgroup>
              </select>
              <div v-if="form.errors?.payment_account_id" class="cv-field-error">{{ form.errors.payment_account_id }}</div>
              <div style="font-size:0.7rem; color: var(--cv-text-muted); margin-top:3px">
                <span v-if="mode === 'edit' && asset?.journal_entry_id" style="color: var(--cv-amber-text)">
                  {{ isRtl
                    ? 'تغيير الحساب سيعكس القيد القديم تلقائياً ويرحّل قيداً جديداً بالحساب المختار'
                    : 'Changing the account will automatically reverse the old entry and post a new one'
                  }}
                </span>
                <span v-else>
                  {{ isRtl ? 'نقد = دُفع فوراً · بنك = حوالة بنكية · ذمم دائنة = شُراء بالأجل' : 'Cash = paid immediately · Bank = wire transfer · Payables = purchased on credit' }}
                </span>
              </div>
            </div>
          </div>
        </div>

        <!-- ══ Section 5 — Notes ══════════════════════════════════ -->
        <div class="cv-card">
          <div class="section-header">
            <div class="section-num">{{ isRtl ? '٥' : '5' }}</div>
            <div>
              <div class="section-title">{{ isRtl ? 'ملاحظات' : 'Notes' }}</div>
              <div class="section-sub">{{ isRtl ? 'معلومات إضافية اختيارية' : 'Optional additional information' }}</div>
            </div>
          </div>
          <textarea v-model="form.notes" class="cv-textarea" rows="3" :placeholder="isRtl ? 'ملاحظات إضافية...' : 'Additional notes...'" style="resize: vertical"></textarea>
        </div>

        <!-- Submit row -->
        <div class="flex justify-end gap-3">
          <Link :href="route('fixed-assets.index')" class="cv-btn cv-btn-ghost">{{ isRtl ? 'إلغاء' : 'Cancel' }}</Link>
          <button class="cv-btn cv-btn-amber" :class="{ 'cv-btn-loading': form.processing }" @click="submit">
            {{ isRtl ? (mode === 'create' ? 'حفظ الأصل' : 'تحديث الأصل') : (mode === 'create' ? 'Save Asset' : 'Update Asset') }}
          </button>
        </div>

      </div>
    </div><!-- /cv-page -->
  </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { computed, watch } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'

const { locale, isRtl, n } = useLocale()

const props = defineProps({
  asset:            { type: Object, default: null },
  farms:            { type: Array,  default: () => [] },
  accounts:         { type: Array,  default: () => [] },
  payment_accounts: { type: Array,  default: () => [] },
  mode:             { type: String, default: 'create' },
})

// ── Detect locked financials ──────────────────────────────────────
const hasPostedLines = computed(() => {
  if (props.mode !== 'edit' || !props.asset) return false
  // Controller passes this info; fall back to false if not set
  return props.asset?.has_posted_lines ?? false
})

// ── Form ──────────────────────────────────────────────────────────
const form = useForm({
  name_en:               props.asset?.name_en               ?? '',
  name_ar:               props.asset?.name_ar               ?? '',
  asset_code:            props.asset?.asset_code            ?? '',
  category:              props.asset?.category              ?? '',
  farm_id:               props.asset?.farm_id               ?? '',
  status:                props.asset?.status                ?? 'active',
  purchase_date:         props.asset?.purchase_date         ?? '',
  in_service_date:       props.asset?.in_service_date       ?? '',
  purchase_cost:         props.asset?.purchase_cost         ?? '',
  salvage_value:         props.asset?.salvage_value         ?? '',
  depreciation_method:   props.asset?.depreciation_method   ?? 'straight_line',
  useful_life_months:    props.asset?.useful_life_months    ?? '',
  db_rate:               props.asset?.db_rate               ?? '',
  asset_account_id:       props.asset?.asset_account_id       ?? '',
  acc_dep_account_id:     props.asset?.acc_dep_account_id     ?? '',
  dep_expense_account_id: props.asset?.dep_expense_account_id ?? '',
  payment_account_id:     props.asset?.payment_account_id     ?? '',
  notes:                  props.asset?.notes                  ?? '',
})

// ── Land category guard ───────────────────────────────────────────
const isLand = computed(() => form.category === 'land')

// When category switches to land: lock dep method, zero salvage,
// and mirror the asset account into both dep account fields so the
// NOT NULL DB constraint is satisfied (land never posts depreciation).
watch(() => form.category, (cat) => {
  if (cat === 'land') {
    form.depreciation_method  = 'none'
    form.salvage_value        = 0
    form.useful_life_months   = ''
    form.db_rate              = ''
    // Mirror asset account as placeholder for dep accounts
    if (form.asset_account_id) {
      form.acc_dep_account_id     = form.asset_account_id
      form.dep_expense_account_id = form.asset_account_id
    }
  }
})

// If user picks the asset account AFTER already selecting land,
// keep dep account fields in sync automatically.
watch(() => form.asset_account_id, (val) => {
  if (isLand.value && val) {
    form.acc_dep_account_id     = val
    form.dep_expense_account_id = val
  }
})

// ── Submit ────────────────────────────────────────────────────────
function submit() {
  if (props.mode === 'create') {
    form.post(route('fixed-assets.store'))
  } else {
    form.put(route('fixed-assets.update', props.asset.id))
  }
}

// ── Account filtering ─────────────────────────────────────────────
const assetAccounts   = computed(() => props.accounts.filter(a => a.type === 'asset'))
const contraAccounts  = computed(() => props.accounts.filter(a => a.type === 'asset'))   // Accum Dep is contra-asset
const expenseAccounts = computed(() => props.accounts.filter(a => a.type === 'expense'))
// Payment accounts come from a dedicated prop (payment_accounts) that bypasses
// the allow_direct_posting filter — cash/bank accounts (14xx, 15xx) are valid
// credit accounts for a purchase JE even when allow_direct_posting = false.
const paymentAccounts = computed(() => props.payment_accounts)

// ── Preview ───────────────────────────────────────────────────────
const previewName = computed(() => {
  return isRtl.value ? (form.name_ar || form.name_en) : (form.name_en || form.name_ar)
})

// ── Monthly SL charge preview ──────────────────────────────────────
const fmtMonthlySL = computed(() => {
  const cost    = Number(form.purchase_cost) || 0
  const salvage = Number(form.salvage_value) || 0
  const life    = Number(form.useful_life_months) || 0
  if (!cost || !life) return '—'
  return ((cost - salvage) / life).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
})

// ── Helpers ───────────────────────────────────────────────────────
function fmt(v) { return Number(v ?? 0).toLocaleString(isRtl.value ? 'ar-EG' : 'en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) }

const categories = [
  { value: 'land',      en: 'Land',      ar: 'أرض' },
  { value: 'building',  en: 'Building',  ar: 'مبنى' },
  { value: 'vehicle',   en: 'Vehicle',   ar: 'مركبة' },
  { value: 'equipment', en: 'Equipment', ar: 'معدات' },
  { value: 'furniture', en: 'Furniture', ar: 'أثاث' },
  { value: 'other',     en: 'Other',     ar: 'أخرى' },
]

function categoryLabel(cat) {
  const c = categories.find(x => x.value === cat)
  return c ? (isRtl.value ? c.ar : c.en) : (isRtl.value ? 'أصل ثابت' : 'Fixed Asset')
}

function categoryGradient(cat) {
  const map = {
    land:      'linear-gradient(135deg, #1a3a20, #2d5a35)',
    building:  'linear-gradient(135deg, #1e2a40, #2a3f60)',
    vehicle:   'linear-gradient(135deg, #2a1e18, #4a3020)',
    equipment: 'linear-gradient(135deg, #2a2218, #4a3c20)',
    furniture: 'linear-gradient(135deg, #28182a, #452040)',
    other:     'linear-gradient(135deg, #1a2228, #263040)',
  }
  return map[cat] ?? 'linear-gradient(135deg, #1a2228, #263040)'
}

function categoryIcon(cat) {
  const icons = {
    land:      `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--cv-success-text)" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>`,
    building:  `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--cv-info-text)" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 3v18M15 3v18M3 9h18M3 15h18"/></svg>`,
    vehicle:   `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--cv-warning-text)" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13" rx="2"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>`,
    equipment: `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--cv-amber-text)" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93l-1.41 1.41M5.34 5.34 3.93 6.75M19.07 19.07l-1.41-1.41M5.34 18.66l-1.41 1.41M22 12h-2M4 12H2M12 22v-2M12 4V2"/></svg>`,
    furniture: `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#C070E0" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M20 9V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v3"/><path d="M2 11v5a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-5a2 2 0 0 0-4 0v2H6v-2a2 2 0 0 0-4 0z"/><path d="M4 18v2M20 18v2"/></svg>`,
    other:     `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--cv-text-secondary)" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>`,
  }
  return icons[cat] ?? icons.other
}

const depMethods = [
  {
    value: 'straight_line',
    en: 'Straight-Line',
    ar: 'القسط الثابت',
    icon: `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="12" x2="21" y2="12"/></svg>`,
  },
  {
    value: 'declining_balance',
    en: 'Declining Balance',
    ar: 'الرصيد المتناقص',
    icon: `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 7 10 12 16 18 19 21 20"/></svg>`,
  },
  {
    value: 'none',
    en: 'No Depreciation',
    ar: 'بدون إهلاك',
    icon: `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>`,
  },
]
</script>

<style scoped>
.section-header {
  display: flex;
  align-items: flex-start;
  gap: 0.875rem;
  margin-bottom: 1.25rem;
}
.section-num {
  width: 2rem;
  height: 2rem;
  border-radius: 50%;
  background: linear-gradient(135deg, var(--cv-green-deep), var(--cv-green));
  color: #fff;
  font-weight: 700;
  font-size: 0.8125rem;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  box-shadow: 0 2px 8px rgba(58, 170, 88, 0.3);
}
.section-title {
  font-weight: 600;
  font-size: 0.9375rem;
  color: var(--cv-text-primary);
}
.section-sub {
  font-size: 0.75rem;
  color: var(--cv-text-muted);
  margin-top: 2px;
}
.lock-badge {
  margin-inline-start: auto;
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  font-size: 0.7rem;
  font-weight: 600;
  color: var(--cv-warning-text);
  background: var(--cv-warning-bg);
  border: 1px solid var(--cv-warning-border);
  padding: 0.2rem 0.6rem;
  border-radius: 9999px;
}
.section-locked {
  opacity: 0.85;
  border-color: var(--cv-warning-border) !important;
}
.form-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 0 1.25rem;
}
.preview-avatar {
  width: 3rem;
  height: 3rem;
  border-radius: var(--cv-radius);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  border: 1px solid rgba(255,255,255,0.06);
}
/* Depreciation method toggle */
.method-toggle {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}
.method-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.5rem 1rem;
  border-radius: var(--cv-radius);
  border: 1.5px solid var(--cv-border-md);
  background: var(--cv-bg-card-alt);
  color: var(--cv-text-muted);
  font-size: 0.8125rem;
  font-weight: 500;
  cursor: pointer;
  transition: var(--cv-transition);
  font-family: inherit;
}
.method-btn:hover:not(:disabled) {
  border-color: var(--cv-green-border);
  color: var(--cv-green-bright);
  background: var(--cv-green-dim);
}
.method-btn.active {
  border-color: var(--cv-green-border);
  background: var(--cv-green-dim);
  color: var(--cv-green-bright);
  font-weight: 600;
}
.method-btn:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}
/* cv-textarea not in app.css — scope it here */
.cv-textarea {
  width: 100%;
  background: var(--cv-bg-card-alt);
  border: 1.5px solid var(--cv-border-input);
  border-radius: var(--cv-radius);
  padding: 0.625rem 0.75rem;
  font-size: 0.8125rem;
  color: var(--cv-text-primary);
  font-family: inherit;
  transition: var(--cv-transition);
}
.cv-textarea:focus {
  outline: none;
  border-color: var(--cv-border-focus);
  box-shadow: var(--cv-shadow-focus);
}
.cv-textarea::placeholder { color: var(--cv-text-placeholder); }
/* cv-input-error */
.cv-input-error {
  border-color: var(--cv-danger-border) !important;
}
</style>