<template>
	<div class="section">
		<h2>{{ t('files_watermark', 'Watermark Settings') }}</h2>

		<NcNoteCard v-if="loadError" type="error">
			{{ t('files_watermark', 'Failed to load configuration: {error}', { error: loadError }) }}
		</NcNoteCard>

		<NcNoteCard v-if="saved" type="success">
			{{ t('files_watermark', 'Settings saved.') }}
		</NcNoteCard>

		<NcNoteCard v-if="saveError" type="error">
			{{ saveError }}
		</NcNoteCard>

		<div v-if="loading" class="loading-wrapper">
			<NcLoadingIcon :size="32" />
		</div>

		<template v-if="!loading">
			<WatermarkForm v-model="config"
				:title="t('files_watermark', 'Global Watermark Policy')"
				:is-admin="true"
				:saving="saving"
				@save="save" />

			<NcSettingsSection :name="t('files_watermark', 'Watermark Activity Log')"
				:description="t('files_watermark', 'Every watermark event is recorded here')">
				<AuditLog />
			</NcSettingsSection>
		</template>
	</div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'
import NcSettingsSection from '@nextcloud/vue/dist/Components/NcSettingsSection.js'
import WatermarkForm from './WatermarkForm.vue'
import AuditLog from './AuditLog.vue'

const config = ref({})
const loading = ref(true)
const loadError = ref(null)
const saving = ref(false)
const saved = ref(false)
const saveError = ref(null)

onMounted(async () => {
	try {
		const res = await axios.get(generateUrl('/apps/files_watermark/api/v1/config'))
		if (Array.isArray(res.data) && res.data.length > 0) {
			config.value = res.data[0]
		}
	} catch (e) {
		// A 404 just means no config exists yet — show the form with defaults
		if (e?.response?.status !== 404) {
			loadError.value = e?.response?.data?.error ?? e.message
		}
	} finally {
		loading.value = false
	}
})

/**
 * Persist the watermark config emitted by the form.
 * @param {object} formData - Watermark settings collected from WatermarkForm
 */
async function save(formData) {
	saving.value = true
	saved.value = false
	saveError.value = null
	try {
		const res = await axios.post(generateUrl('/apps/files_watermark/api/v1/config'), {
			...formData,
			id: config.value?.id ?? null,
		})
		config.value = res.data
		saved.value = true
		setTimeout(() => { saved.value = false }, 3000)
	} catch (e) {
		saveError.value = e?.response?.data?.error ?? e.message
	} finally {
		saving.value = false
	}
}
</script>

<style scoped>
.loading-wrapper {
    display: flex;
    justify-content: center;
    padding: 32px;
}
</style>
