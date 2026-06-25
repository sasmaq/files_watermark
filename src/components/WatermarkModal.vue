<template>
  <div class="modal-mask" @click.self="$emit('close')">
    <div class="modal-container">
      <h3>{{ t('files_watermark', 'Apply Watermark') }}</h3>
      <p>{{ t('files_watermark', 'Apply watermark to: {file}', { file: fileName }) }}</p>

      <div v-if="applying" class="loading">{{ t('files_watermark', 'Applying watermark…') }}</div>
      <div v-if="done" class="success">{{ t('files_watermark', 'Watermark applied successfully.') }}</div>
      <div v-if="applyError" class="error">{{ applyError }}</div>

      <div v-if="!applying && !done" class="modal-actions">
        <button class="button primary" @click="apply">
          {{ t('files_watermark', 'Apply') }}
        </button>
        <button class="button" @click="$emit('close')">
          {{ t('files_watermark', 'Cancel') }}
        </button>
      </div>

      <div v-if="done" class="modal-actions">
        <button class="button" @click="$emit('close')">
          {{ t('files_watermark', 'Close') }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'

const props = defineProps({
  filePath: { type: String, required: true },
  fileName: { type: String, required: true },
})

const emit = defineEmits(['close'])

const applying   = ref(false)
const done       = ref(false)
const applyError = ref(null)

async function apply() {
  applying.value   = true
  applyError.value = null
  try {
    await axios.post(generateUrl('/apps/files_watermark/api/v1/apply'), { path: props.filePath })
    done.value = true
  } catch (e) {
    applyError.value = e?.response?.data?.error ?? e.message
  } finally {
    applying.value = false
  }
}
</script>

<style scoped>
.modal-mask {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 9999;
}
.modal-container {
  background: var(--color-main-background);
  border-radius: var(--border-radius-large);
  padding: 32px;
  min-width: 360px;
  max-width: 480px;
}
.modal-actions {
  display: flex;
  gap: 12px;
  margin-top: 24px;
}
.success { color: var(--color-success); }
.error   { color: var(--color-error); }
</style>
