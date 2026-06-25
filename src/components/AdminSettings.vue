<template>
  <div class="section">
    <h2>{{ t('files_watermark', 'Watermark Settings') }}</h2>

    <p v-if="saved" class="success">{{ t('files_watermark', 'Settings saved.') }}</p>
    <p v-if="saveError" class="error">{{ saveError }}</p>

    <WatermarkForm
      v-model="config"
      :title="t('files_watermark', 'Global Watermark Policy')"
      @save="save"
    />

    <hr />

    <AuditLog />
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'
import WatermarkForm from './WatermarkForm.vue'
import AuditLog from './AuditLog.vue'

const config    = ref({})
const saved     = ref(false)
const saveError = ref(null)

onMounted(async () => {
  try {
    const res = await axios.get(generateUrl('/apps/files_watermark/api/v1/config'))
    if (res.data.length > 0) {
      config.value = res.data[0]
    }
  } catch {
    // no global config yet, use defaults from the form
  }
})

async function save(formData) {
  saved.value     = false
  saveError.value = null
  try {
    await axios.post(generateUrl('/apps/files_watermark/api/v1/config'), {
      ...formData,
      id: config.value.id ?? null,
    })
    saved.value = true
    setTimeout(() => { saved.value = false }, 3000)
  } catch (e) {
    saveError.value = e?.response?.data?.error ?? e.message
  }
}
</script>

<style scoped>
.success { color: var(--color-success); }
.error   { color: var(--color-error); }
</style>
