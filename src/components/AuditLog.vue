<template>
  <div class="audit-log">
    <h3>{{ t('files_watermark', 'Watermark Activity Log') }}</h3>

    <div v-if="loading" class="loading">{{ t('files_watermark', 'Loading…') }}</div>

    <div v-else-if="error" class="error">{{ error }}</div>

    <table v-else class="log-table">
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
          <td colspan="4">{{ t('files_watermark', 'No entries yet.') }}</td>
        </tr>
      </tbody>
    </table>

    <div class="pagination">
      <button :disabled="offset === 0" @click="prev">
        {{ t('files_watermark', '← Previous') }}
      </button>
      <button :disabled="entries.length < limit" @click="next">
        {{ t('files_watermark', 'Next →') }}
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'

const entries = ref([])
const loading = ref(false)
const error   = ref(null)
const limit   = 50
const offset  = ref(0)

async function fetchLog() {
  loading.value = true
  error.value   = null
  try {
    const url = generateUrl('/apps/files_watermark/api/v1/log')
    const res = await axios.get(url, { params: { limit, offset: offset.value } })
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
}
.log-table th,
.log-table td {
  border: 1px solid var(--color-border);
  padding: 8px 12px;
  text-align: left;
}
.log-table th {
  background: var(--color-background-dark);
}
.pagination {
  margin-top: 12px;
  display: flex;
  gap: 8px;
}
.error {
  color: var(--color-error);
}
</style>
