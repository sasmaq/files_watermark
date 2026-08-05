<template>
	<NcDialog :name="t('files_watermark', 'Apply Watermark')"
		:open="true"
		@update:open="$emit('close')">
		<template #default>
			<p>
				{{ t('files_watermark', 'Apply watermark to: {file}', { file: fileName }) }}
			</p>

			<p class="wm-hint">
				{{ t('files_watermark', 'The file itself is not changed. From now on, every download and every preview of it carries a watermark naming whoever fetched it.') }}
			</p>

			<NcNoteCard v-if="done" type="success">
				{{ t('files_watermark', 'Watermark applied successfully.') }}
			</NcNoteCard>
			<NcNoteCard v-if="alreadyWatermarked" type="info">
				{{ t('files_watermark', 'This file is already watermarked.') }}
			</NcNoteCard>
			<NcNoteCard v-if="applyError" type="error">
				{{ applyError }}
			</NcNoteCard>
		</template>

		<template #actions>
			<NcButton v-if="!done && !alreadyWatermarked"
				type="primary"
				:disabled="applying"
				@click="apply">
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
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'

const props = defineProps({
	filePath: { type: String, required: true },
	fileName: { type: String, required: true },
})

const emit = defineEmits(['close', 'watermarked'])

const applying = ref(false)
const done = ref(false)
const alreadyWatermarked = ref(false)
const applyError = ref(null)

/**
 *
 */
async function apply() {
	applying.value = true
	applyError.value = null
	try {
		const res = await axios.post(generateUrl('/apps/files_watermark/api/v1/apply'), {
			path: props.filePath,
		})
		// The backend reports an already-watermarked file as a benign no-op - treat
		// it as informational, and still emit `watermarked` so the row badge / action
		// state catch up if the client cache was stale.
		if (res?.data?.status === 'already_watermarked') {
			alreadyWatermarked.value = true
		} else {
			done.value = true
		}
		emit('watermarked')
		// Auto-close after showing the message briefly.
		setTimeout(() => emit('close'), 1500)
	} catch (e) {
		// A throttled request is answered by Nextcloud's rate-limiting middleware, not by
		// this app, so it carries no `error` field to show - without this the user reads
		// "Request failed with status code 429" in English on an otherwise translated
		// dialog. Every other failure does carry one, including the 413 for an oversized
		// file, which names both the size and the limit.
		applyError.value = e?.response?.status === 429
			? t('files_watermark', 'Too many watermark requests at once. Wait a moment and try again.')
			: e?.response?.data?.error ?? e.message
	} finally {
		applying.value = false
	}
}
</script>

<style scoped>
.wm-hint {
  margin-top: 8px;
  font-size: 0.9em;
  color: var(--color-text-lighter);
}
</style>
