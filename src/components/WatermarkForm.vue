<template>
  <div class="watermark-form">
    <h3 v-if="title">{{ title }}</h3>

    <!-- Type -->
    <NcSettingsSection :name="t('files_watermark', 'Watermark type')" :description="t('files_watermark', 'Choose what kind of watermark to apply')">
      <div class="form-row">
        <NcSelect
          v-model="form.type"
          :options="typeOptions"
          :placeholder="t('files_watermark', 'Select type')"
          label="label"
          track-by="value"
          :reduce="o => o.value"
        />
      </div>
    </NcSettingsSection>

    <!-- Text template -->
    <NcSettingsSection
      v-if="form.type !== 'image'"
      :name="t('files_watermark', 'Text template')"
      :description="t('files_watermark', 'Placeholders: {username}, {email}, {date}, {datetime}, {filename}')"
    >
      <NcTextField
        v-model="form.textTemplate"
        :label="t('files_watermark', 'Text template')"
        :placeholder="t('files_watermark', '{username} — {date}')"
      />
    </NcSettingsSection>

    <!-- Image path -->
    <NcSettingsSection
      v-if="form.type !== 'text'"
      :name="t('files_watermark', 'Watermark image')"
      :description="t('files_watermark', 'Nextcloud path to the image used as watermark')"
    >
      <NcTextField
        v-model="form.imagePath"
        :label="t('files_watermark', 'Image path')"
        :placeholder="t('files_watermark', '/path/to/logo.png')"
      />
    </NcSettingsSection>

    <!-- Style -->
    <NcSettingsSection :name="t('files_watermark', 'Style')" :description="t('files_watermark', 'Visual appearance of the watermark')">
      <div class="form-row">
        <label>{{ t('files_watermark', 'Font size (pt)') }}</label>
        <input v-model.number="form.fontSize" type="number" min="6" max="120" class="form-control" />
      </div>
      <div class="form-row">
        <label>{{ t('files_watermark', 'Color') }}</label>
        <input v-model="form.color" type="color" class="form-control form-control--color" />
      </div>
      <div class="form-row">
        <label>{{ t('files_watermark', 'Opacity: {n}%', { n: form.opacity }) }}</label>
        <input v-model.number="form.opacity" type="range" min="0" max="100" class="form-control" />
      </div>
      <div class="form-row">
        <label>{{ t('files_watermark', 'Rotation (°)') }}</label>
        <input v-model.number="form.rotation" type="number" min="-180" max="180" class="form-control" />
      </div>
    </NcSettingsSection>

    <!-- Trigger -->
    <NcSettingsSection :name="t('files_watermark', 'Trigger')" :description="t('files_watermark', 'When to apply the watermark')">
      <NcSelect
        v-model="form.trigger"
        :options="triggerOptions"
        :placeholder="t('files_watermark', 'Select trigger')"
        label="label"
        track-by="value"
        :reduce="o => o.value"
      />
    </NcSettingsSection>

    <!-- Scope (admin-only fields) -->
    <NcSettingsSection v-if="isAdmin" :name="t('files_watermark', 'Scope')" :description="t('files_watermark', 'Restrict which files are watermarked')">
      <div class="form-row">
        <label>{{ t('files_watermark', 'MIME type whitelist') }}</label>
        <NcTextField
          v-model="form.mimeTypes"
          :label="t('files_watermark', 'MIME types')"
          :placeholder="t('files_watermark', 'application/pdf,image/jpeg  (blank = all)')"
        />
      </div>
      <div class="form-row">
        <label>{{ t('files_watermark', 'Folder system-tag ID') }}</label>
        <NcTextField
          v-model="form.folderTag"
          :label="t('files_watermark', 'Tag ID')"
          :placeholder="t('files_watermark', 'Leave blank to apply globally')"
        />
      </div>
    </NcSettingsSection>

    <div class="form-actions">
      <NcButton type="primary" :disabled="saving" @click="$emit('save', { ...form })">
        <template #icon>
          <NcLoadingIcon v-if="saving" :size="20" />
        </template>
        {{ t('files_watermark', 'Save') }}
      </NcButton>
    </div>
  </div>
</template>

<script setup>
import { reactive, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import NcSettingsSection from '@nextcloud/vue/dist/Components/NcSettingsSection.js'
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'

const props = defineProps({
  title:      { type: String,  default: '' },
  modelValue: { type: Object,  default: () => ({}) },
  isAdmin:    { type: Boolean, default: false },
  saving:     { type: Boolean, default: false },
})

const emit = defineEmits(['save', 'update:modelValue'])

const typeOptions = [
  { value: 'text',     label: t('files_watermark', 'Text') },
  { value: 'image',    label: t('files_watermark', 'Image') },
  { value: 'combined', label: t('files_watermark', 'Text + Image') },
]

const triggerOptions = [
  { value: 'on_demand',   label: t('files_watermark', 'On demand') },
  { value: 'on_download', label: t('files_watermark', 'On download') },
  { value: 'on_share',    label: t('files_watermark', 'On share') },
  { value: 'on_upload',   label: t('files_watermark', 'On upload') },
]

const form = reactive({
  type:         'text',
  textTemplate: '{username} — {date}',
  imagePath:    '',
  fontSize:     24,
  color:        '#cccccc',
  opacity:      80,
  rotation:     45,
  trigger:      'on_demand',
  mimeTypes:    '',
  folderTag:    '',
  ...props.modelValue,
})

watch(form, (val) => emit('update:modelValue', { ...val }))
</script>

<style scoped>
.watermark-form {
  max-width: 640px;
}
.form-row {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 12px;
}
.form-row label {
  min-width: 180px;
  font-weight: 600;
}
.form-control {
  flex: 1;
}
.form-control--color {
  width: 48px;
  height: 36px;
  padding: 2px;
  border-radius: 4px;
  cursor: pointer;
}
.form-actions {
  margin-top: 24px;
}
</style>
