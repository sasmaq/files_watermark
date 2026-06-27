<template>
  <NcDialog
    :name="t('files_watermark', 'Apply Watermark')"
    :open="true"
    @update:open="$emit('close')"
  >
    <template #default>
      <p>
        {{ t('files_watermark', 'Apply watermark to: {file}', { file: fileName }) }}
      </p>

      <p v-if="estimatedSeconds" class="time-hint">
        {{ t('files_watermark', 'Estimated processing time: ~{n} second(s) for large files.', { n: estimatedSeconds }) }}
      </p>

      <NcNoteCard v-if="done" type="success">
        {{ t('files_watermark', 'Watermark applied successfully.') }}
      </NcNoteCard>
      <NcNoteCard v-if="applyError" type="error">
        {{ applyError }}
      </NcNoteCard>
    </template>

    <template #actions>
      <NcButton v-if="!done" type="primary" :disabled="applying" @click="apply">
        <template #icon>
          <NcLoadingIcon v-if="applying" :size="20" />
        </template>
        {{ t('files_watermark', 'Apply') }}
      </NcButton>
      <NcButton @click="$emit('close')">
        {{ done ? t('files_watermark', 'Close') : t('files_watermark', 'Cancel') }}
      </NcButton>
    </template>
  </NcDialog>
</template>

<script setup>
import { ref, computed } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcDialog from '@nextcloud/vue/dist/Components/NcDialog.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'

const props = defineProps({
  filePath: { type: String, required: true },
  fileName: { type: String, required: true },
  fileSize: { type: Number, default: 0 },
})

const emit = defineEmits(['close', 'watermarked'])

const applying   = ref(false)
const done       = ref(false)
const applyError = ref(null)

// Rough estimate: ~1 second per MB, minimum 1s, only shown for files > 1 MB.
const estimatedSeconds = computed(() => {
  if (!props.fileSize || props.fileSize < 1024 * 1024) return null
  return Math.max(1, Math.round(props.fileSize / 1024 / 1024))
})

async function apply() {
  applying.value   = true
  applyError.value = null
  try {
    await axios.post(generateUrl('/apps/files_watermark/api/v1/apply'), {
      path: props.filePath,
    })
    done.value = true
    emit('watermarked')
    // Auto-close after showing the success message briefly.
    setTimeout(() => emit('close'), 1500)
  } catch (e) {
    applyError.value = e?.response?.data?.error ?? e.message
  } finally {
    applying.value = false
  }
}
</script>

<style scoped>
.time-hint {
  margin-top: 8px;
  font-size: 0.9em;
  color: var(--color-text-lighter);
}
</style>
