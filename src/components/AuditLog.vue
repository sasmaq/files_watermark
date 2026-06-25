<template>
  <div class="audit-log">
    <div v-if="loading" class="loading-wrapper">
      <NcLoadingIcon :size="24" />
    </div>

    <NcNoteCard v-else-if="error" type="error">{{ error }}</NcNoteCard>

    <template v-else>
      <table class="log-table">
        <thead>
          <tr>
            <th>{{ t('files_watermark', 'Date') }}</th>
            <th>{{ t('files_watermark', 'User') }}</th>
            <th>{{ t('files_watermark', 'File') }}</th>
            <th>{{ t('files_watermark', 'Trigger') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="entry in entries" :key="entry.id">
            <td>{{ entry.createdAt }}</td>
            <td>{{ entry.userId }}</td>
            <td>{{ entry.filePath }}</td>
            <td>{{ entry.trigger }}</td>
          </tr>
          <tr v-if="entries.length === 0">
            <td colspan="4" class="empty">{{ t('files_watermark', 'No entries yet.') }}</td>
          </tr>
        </tbody>
      </table>

      <div class="pagination">
        <NcButton :disabled="offset === 0" @click="prev">
          {{ t('files_watermark', '← Previous') }}
        </NcButton>
        <NcButton :disabled="entries.length < limit" @click="next">
          {{ t('files_watermark', 'Next →') }}
        </NcButton>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'

const entries = ref([])
const loading = ref(false)
const error   = ref(null)
const limit   = 50
const offset  = ref(0)

async function fetchLog() {
  loading.value = true
  error.value   = null
  try {
    const res = await axios.get(generateUrl('/apps/files_watermark/api/v1/log'), {
      params: { limit, offset: offset.value },
    })
    entries.value = res.data
  } catch (e) {
    error.value = e?.response?.data?.error ?? e.message
  } finally {
    loading.value = false
  }
}

function prev() {
  offset.value = Math.max(0, offset.value - limit)
  fetchLog()
}
function next() {
  offset.value += limit
  fetchLog()
}

onMounted(fetchLog)
</script>

<style scoped>
.log-table {
  width: 100%;
  border-collapse: collapse;
  margin-bottom: 12px;
}
.log-table th,
.log-table td {
  border: 1px solid var(--color-border);
  padding: 8px 12px;
  text-align: left;
}
.log-table th {
  background: var(--color-background-dark);
  font-weight: 600;
}
.empty {
  text-align: center;
  color: var(--color-text-lighter);
  padding: 24px;
}
.pagination {
  display: flex;
  gap: 8px;
}
.loading-wrapper {
  display: flex;
  justify-content: center;
  padding: 24px;
}
</style>
