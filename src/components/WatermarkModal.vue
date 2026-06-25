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
import { ref } from 'vue'
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
})

const emit = defineEmits(['close'])

const applying   = ref(false)
const done       = ref(false)
const applyError = ref(null)

async function apply() {
  applying.value   = true
  applyError.value = null
  try {
    await axios.post(generateUrl('/apps/files_watermark/api/v1/apply'), {
      path: props.filePath,
    })
    done.value = true
  } catch (e) {
    applyError.value = e?.response?.data?.error ?? e.message
  } finally {
    applying.value = false
  }
}
</script>
