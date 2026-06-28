<template>
	<div class="watermark-form">
		<h3 v-if="title">
			{{ title }}
		</h3>

		<NcSettingsSection :name="t('files_watermark', 'Watermark type')"
			:description="t('files_watermark', 'Choose what kind of watermark to apply')">
			<div class="form-row">
				<label for="wm-type">{{ t('files_watermark', 'Type') }}</label>
				<select id="wm-type" v-model="form.type" class="form-select">
					<option value="text">
						{{ t('files_watermark', 'Text') }}
					</option>
					<option value="image">
						{{ t('files_watermark', 'Image') }}
					</option>
					<option value="combined">
						{{ t('files_watermark', 'Text + Image') }}
					</option>
				</select>
			</div>
		</NcSettingsSection>

		<NcSettingsSection v-if="form.type !== 'image'"
			:name="t('files_watermark', 'Text template')"
			:description="t('files_watermark', 'Placeholders: {username}, {email}, {date}, {datetime}, {filename}')">
			<NcTextField v-model="form.textTemplate"
				:label="t('files_watermark', 'Text template')"
				:placeholder="t('files_watermark', '{username} — {date}')" />
			<p v-if="previewText" class="template-preview">
				<strong>{{ t('files_watermark', 'Preview:') }}</strong> {{ previewText }}
			</p>
		</NcSettingsSection>

		<NcSettingsSection v-if="form.type !== 'text'"
			:name="t('files_watermark', 'Watermark image')"
			:description="t('files_watermark', 'Nextcloud path to the PNG, JPG, or SVG file used as watermark')">
			<NcTextField v-model="form.imagePath"
				:label="t('files_watermark', 'Image path')"
				:placeholder="t('files_watermark', '/path/to/logo.png')" />
			<p v-if="imagePathError" class="field-error">
				{{ imagePathError }}
			</p>
		</NcSettingsSection>

		<NcSettingsSection :name="t('files_watermark', 'Style')"
			:description="t('files_watermark', 'Visual appearance of the watermark')">
			<div class="form-row">
				<label for="wm-fontsize">{{ t('files_watermark', 'Font size (pt)') }}</label>
				<input id="wm-fontsize"
					v-model.number="form.fontSize"
					type="number"
					min="6"
					max="120"
					class="form-input">
			</div>
			<div class="form-row">
				<label for="wm-color">{{ t('files_watermark', 'Color') }}</label>
				<input id="wm-color"
					v-model="form.color"
					type="color"
					class="form-color">
			</div>
			<div class="form-row">
				<label for="wm-opacity">{{ t('files_watermark', 'Opacity') }} — {{ form.opacity }}%</label>
				<input id="wm-opacity"
					v-model.number="form.opacity"
					type="range"
					min="0"
					max="100"
					class="form-range">
			</div>
			<div class="form-row">
				<label for="wm-rotation">{{ t('files_watermark', 'Rotation (°)') }}</label>
				<input id="wm-rotation"
					v-model.number="form.rotation"
					type="number"
					min="-180"
					max="180"
					class="form-input">
			</div>
		</NcSettingsSection>

		<NcSettingsSection :name="t('files_watermark', 'Trigger')"
			:description="t('files_watermark', 'When to apply the watermark')">
			<div class="form-row">
				<label for="wm-trigger">{{ t('files_watermark', 'Trigger') }}</label>
				<select id="wm-trigger" v-model="form.trigger" class="form-select">
					<option value="on_demand">
						{{ t('files_watermark', 'On demand') }}
					</option>
					<option value="on_download">
						{{ t('files_watermark', 'On download') }}
					</option>
					<option value="on_share">
						{{ t('files_watermark', 'On share') }}
					</option>
					<option value="on_upload">
						{{ t('files_watermark', 'On upload') }}
					</option>
				</select>
			</div>
		</NcSettingsSection>

		<NcSettingsSection v-if="isAdmin"
			:name="t('files_watermark', 'Scope')"
			:description="t('files_watermark', 'Restrict which files are watermarked')">
			<div class="form-row">
				<label for="wm-mime">{{ t('files_watermark', 'MIME type whitelist') }}</label>
				<input id="wm-mime"
					v-model="form.mimeTypes"
					type="text"
					class="form-input"
					:placeholder="t('files_watermark', 'application/pdf,image/jpeg  (blank = all)')">
			</div>
			<div class="form-row">
				<label for="wm-tag">{{ t('files_watermark', 'Folder system-tag ID') }}</label>
				<input id="wm-tag"
					v-model="form.folderTag"
					type="text"
					class="form-input"
					:placeholder="t('files_watermark', 'Leave blank to apply globally')">
			</div>
		</NcSettingsSection>

		<div class="form-actions">
			<NcButton type="primary"
				:disabled="saving || !!imagePathError"
				native-type="button"
				@click="$emit('save', { ...form })">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('files_watermark', 'Save') }}
			</NcButton>
		</div>
	</div>
</template>

<script setup>
import { reactive, watch, computed } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

const props = defineProps({
	title: { type: String, default: '' },
	modelValue: { type: Object, default: () => ({}) },
	isAdmin: { type: Boolean, default: false },
	saving: { type: Boolean, default: false },
})

const emit = defineEmits(['save', 'update:modelValue'])

const form = reactive({
	type: 'text',
	textTemplate: '{username} — {date}',
	imagePath: '',
	fontSize: 24,
	color: '#cccccc',
	opacity: 80,
	rotation: 45,
	trigger: 'on_demand',
	mimeTypes: '',
	folderTag: '',
	...props.modelValue,
})

watch(form, (val) => emit('update:modelValue', { ...val }))

const SAMPLE = {
	username: 'john.doe',
	email: 'john.doe@example.com',
	date: new Date().toISOString().slice(0, 10),
	datetime: new Date().toISOString().slice(0, 19).replace('T', ' '),
	filename: 'document.pdf',
}

const previewText = computed(() => {
	if (!form.textTemplate) return ''
	return form.textTemplate.replace(/\{(\w+)\}/g, (_, key) => SAMPLE[key] ?? `{${key}}`)
})

const ALLOWED_IMAGE_EXTS = ['png', 'jpg', 'jpeg', 'svg']

const imagePathError = computed(() => {
	if (!form.imagePath) return null
	const ext = form.imagePath.split('.').pop().toLowerCase()
	if (!ALLOWED_IMAGE_EXTS.includes(ext)) {
		return t('files_watermark', 'Image must be a PNG, JPG, or SVG file.')
	}
	return null
})
</script>

<style scoped>
.watermark-form {
    max-width: 640px;
}
.form-row {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
}
.form-row label {
    min-width: 180px;
    font-weight: 600;
}
.form-select,
.form-input {
    flex: 1;
    height: 34px;
    padding: 0 8px;
    border: 1px solid var(--color-border-dark, #ccc);
    border-radius: var(--border-radius, 3px);
    background: var(--color-main-background, #fff);
    color: var(--color-main-text, #000);
}
.form-color {
    width: 48px;
    height: 34px;
    padding: 2px;
    border-radius: var(--border-radius, 3px);
    cursor: pointer;
    border: 1px solid var(--color-border-dark, #ccc);
}
.form-range {
    flex: 1;
}
.form-actions {
    margin-top: 24px;
    padding: 0 0 0 2px;
}
.template-preview {
    margin-top: 8px;
    font-size: 0.9em;
    color: var(--color-text-lighter);
    word-break: break-word;
}
.field-error {
    margin-top: 6px;
    font-size: 0.9em;
    color: var(--color-error, #e9322d);
}
</style>
