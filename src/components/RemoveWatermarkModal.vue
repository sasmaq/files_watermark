<template>
	<NcDialog :name="t('files_watermark', 'Remove Watermark')"
		:open="true"
		@update:open="$emit('close')">
		<template #default>
			<p>
				{{ t('files_watermark', 'Remove the watermark from: {file}', { file: fileName }) }}
			</p>

			<NcNoteCard v-if="!done" type="warning">
				{{ t('files_watermark', 'This restores the original file as it was before the watermark was applied. The watermarked version is discarded and cannot be recovered.') }}
			</NcNoteCard>

			<NcNoteCard v-if="done" type="success">
				{{ t('files_watermark', 'Original restored successfully.') }}
			</NcNoteCard>
			<NcNoteCard v-if="removeError" type="error">
				{{ removeError }}
			</NcNoteCard>
		</template>

		<template #actions>
			<NcButton v-if="!done"
				type="error"
				:disabled="removing"
				@click="remove">
				<template #icon>
					<NcLoadingIcon v-if="removing" :size="20" />
				</template>
				{{ t('files_watermark', 'Restore original') }}
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

const emit = defineEmits(['close', 'removed'])

const removing = ref(false)
const done = ref(false)
const removeError = ref(null)

/**
 * Restore the pre-watermark original. The confirmation above is deliberate: the
 * watermarked content is overwritten and, unlike the watermark itself, is not backed up.
 */
async function remove() {
	removing.value = true
	removeError.value = null
	try {
		await axios.post(generateUrl('/apps/files_watermark/api/v1/remove'), {
			path: props.filePath,
		})
		done.value = true
		emit('removed')
		setTimeout(() => emit('close'), 1500)
	} catch (e) {
		// 422 here is the expected "no preserved original" case (a file watermarked
		// before backups existed), so the server's message is the useful one to show.
		removeError.value = e?.response?.data?.error ?? e.message
	} finally {
		removing.value = false
	}
}
</script>
