<template>
	<NcDialog :name="t('files_watermark', 'Remove Watermark')"
		:open="true"
		@update:open="$emit('close')">
		<template #default>
			<p>
				{{ t('files_watermark', 'Remove the watermark from: {file}', { file: fileName }) }}
			</p>

			<!--
				Informational, not a warning, and that is the whole change. This dialog used
				to announce that "the watermarked version is discarded and cannot be
				recovered" - true when the watermark was burned into the file and this
				overwrote it with a preserved copy. Nothing is discarded now: the file has
				never been anything other than what was uploaded. A destructive-styled
				warning in front of a reversible action teaches people to click through
				warnings.
			-->
			<NcNoteCard v-if="!done" type="info">
				{{ t('files_watermark', 'The file itself does not change. Downloads and previews of it simply stop being watermarked, and you can apply the watermark again at any time.') }}
			</NcNoteCard>

			<NcNoteCard v-if="done" type="success">
				{{ t('files_watermark', 'Watermark removed.') }}
			</NcNoteCard>
			<NcNoteCard v-if="removeError" type="error">
				{{ removeError }}
			</NcNoteCard>
		</template>

		<template #actions>
			<NcButton v-if="!done"
				type="primary"
				:disabled="removing"
				@click="remove">
				<template #icon>
					<NcLoadingIcon v-if="removing" :size="20" />
				</template>
				{{ t('files_watermark', 'Remove watermark') }}
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
 * Take the mark off, so the file stops being watermarked on its way out.
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
		// The one failure worth expecting is 403: only the file's owner may remove a
		// watermark, so a share recipient lands here. The server's message names the
		// reason, and it is the only thing that can explain a refusal on a file the user
		// can otherwise edit.
		removeError.value = e?.response?.data?.error ?? e.message
	} finally {
		removing.value = false
	}
}
</script>
