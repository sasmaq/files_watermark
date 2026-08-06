<template>
	<div class="section">
		<h2>{{ t('files_watermark', 'Watermark') }}</h2>
		<p class="section-intro">
			{{ t('files_watermark', 'Define the watermark applied to files across this server. Changes are previewed live and take effect as soon as you save.') }}
		</p>

		<NcNoteCard v-if="loadError" type="error">
			{{ t('files_watermark', 'Failed to load configuration: {error}', { error: loadError }) }}
		</NcNoteCard>

		<div v-if="loading" class="loading-wrapper">
			<NcLoadingIcon :size="32" />
		</div>

		<template v-if="!loading">
			<WatermarkForm v-model="config"
				:title="t('files_watermark', 'Global Watermark Policy')"
				:is-admin="true"
				:saving="saving"
				:saved="saved"
				:save-error="saveError"
				@save="save" />

			<section class="watermark-log">
				<h3 class="watermark-log__title">
					{{ t('files_watermark', 'Activity log') }}
				</h3>
				<p class="watermark-log__desc">
					{{ t('files_watermark', 'Every watermark applied to a file is recorded here.') }}
				</p>
				<!--
					Collapsed on arrival, and the log is not rendered at all until it is
					opened - `v-if`, never `v-show`. The page an admin comes here for is the
					policy form; the log is a hundred rows of history underneath it, and
					mounting it is what fires its own request. Hiding it with CSS would keep
					that request on every load and save nothing.
				-->
				<NcButton class="watermark-log__toggle"
					type="tertiary"
					:aria-expanded="logOpen ? 'true' : 'false'"
					aria-controls="watermark-log-panel"
					native-type="button"
					@click="logOpen = !logOpen">
					{{ logOpen
						? t('files_watermark', 'Hide activity log')
						: t('files_watermark', 'Show activity log') }}
				</NcButton>
				<div v-if="logOpen" id="watermark-log-panel">
					<AuditLog />
				</div>
			</section>
		</template>
	</div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import WatermarkForm from './WatermarkForm.vue'
import AuditLog from './AuditLog.vue'

const config = ref({})
// Closed on every load rather than remembered: this is history, not a working view, and an
// admin who left it open once should not pay for the fetch on every visit afterwards.
const logOpen = ref(false)
const loading = ref(true)
const loadError = ref(null)
const saving = ref(false)
const saved = ref(false)
const saveError = ref(null)

onMounted(async () => {
	try {
		const res = await axios.get(generateUrl('/apps/files_watermark/api/v1/config'))
		const configs = res.data?.configs ?? []
		if (configs.length > 0) {
			config.value = configs[0]
		}
	} catch (e) {
		// A 404 just means no config exists yet - show the form with defaults
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
.section-intro {
    margin: 0 0 20px;
    max-width: 720px;
    color: var(--color-text-maxcontrast);
}
.loading-wrapper {
    display: flex;
    justify-content: center;
    padding: 32px;
}
.watermark-log {
    max-width: 980px;
    margin-top: 32px;
    padding-top: 28px;
    border-top: 1px solid var(--color-border);
}
.watermark-log__title {
    margin: 0 0 4px;
    font-size: 20px;
    font-weight: 700;
}
.watermark-log__desc {
    margin: 0 0 12px;
    font-size: 14px;
    color: var(--color-text-maxcontrast);
}
.watermark-log__toggle {
    margin-bottom: 16px;
}
</style>
