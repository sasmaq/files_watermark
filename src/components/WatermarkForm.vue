<template>
  <div class="watermark-form">
    <h3>{{ title }}</h3>

    <div class="form-row">
      <label>{{ t('files_watermark', 'Type') }}</label>
      <select v-model="form.type">
        <option value="text">{{ t('files_watermark', 'Text') }}</option>
        <option value="image">{{ t('files_watermark', 'Image') }}</option>
        <option value="combined">{{ t('files_watermark', 'Text + Image') }}</option>
      </select>
    </div>

    <template v-if="form.type !== 'image'">
      <div class="form-row">
        <label>{{ t('files_watermark', 'Text template') }}</label>
        <input
          v-model="form.textTemplate"
          type="text"
          :placeholder="t('files_watermark', '{username} — {date}')"
        />
        <span class="hint">{{ t('files_watermark', 'Placeholders: {username}, {email}, {date}, {datetime}, {filename}') }}</span>
      </div>
    </template>

    <template v-if="form.type !== 'text'">
      <div class="form-row">
        <label>{{ t('files_watermark', 'Image path') }}</label>
        <input v-model="form.imagePath" type="text" :placeholder="t('files_watermark', 'Nextcloud path to watermark image')" />
      </div>
    </template>

    <div class="form-row">
      <label>{{ t('files_watermark', 'Font size (pt)') }}</label>
      <input v-model.number="form.fontSize" type="number" min="6" max="120" />
    </div>

    <div class="form-row">
      <label>{{ t('files_watermark', 'Color') }}</label>
      <input v-model="form.color" type="color" />
    </div>

    <div class="form-row">
      <label>{{ t('files_watermark', 'Opacity (%)') }}</label>
      <input v-model.number="form.opacity" type="range" min="0" max="100" />
      <span>{{ form.opacity }}%</span>
    </div>

    <div class="form-row">
      <label>{{ t('files_watermark', 'Rotation (°)') }}</label>
      <input v-model.number="form.rotation" type="number" min="-180" max="180" />
    </div>

    <div class="form-row">
      <label>{{ t('files_watermark', 'Trigger') }}</label>
      <select v-model="form.trigger">
        <option value="on_demand">{{ t('files_watermark', 'On demand') }}</option>
        <option value="on_download">{{ t('files_watermark', 'On download') }}</option>
        <option value="on_share">{{ t('files_watermark', 'On share') }}</option>
        <option value="on_upload">{{ t('files_watermark', 'On upload') }}</option>
      </select>
    </div>

    <div class="form-actions">
      <button class="button primary" @click="$emit('save', form)">
        {{ t('files_watermark', 'Save') }}
      </button>
    </div>
  </div>
</template>

<script setup>
import { reactive, watch } from 'vue'
import { t } from '@nextcloud/l10n'

const props = defineProps({
  title: { type: String, default: '' },
  modelValue: { type: Object, default: () => ({}) },
})

const emit = defineEmits(['save', 'update:modelValue'])

const form = reactive({
  type: 'text',
  textTemplate: '{username} — {date}',
  imagePath: '',
  fontSize: 24,
  color: '#cccccc',
  opacity: 80,
  rotation: 45,
  trigger: 'on_demand',
  ...props.modelValue,
})

watch(form, (val) => emit('update:modelValue', { ...val }))
</script>

<style scoped>
.watermark-form {
  max-width: 600px;
}
.form-row {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 16px;
}
.form-row label {
  min-width: 160px;
  font-weight: 600;
}
.form-row input,
.form-row select {
  flex: 1;
}
.hint {
  font-size: 0.85em;
  color: var(--color-text-lighter);
}
.form-actions {
  margin-top: 24px;
}
</style>
