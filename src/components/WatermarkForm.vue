<template>
	<div class="watermark-form">
		<h3 v-if="title" class="wm-title">
			{{ title }}
		</h3>

		<div class="wm-grid">
			<div class="wm-main">
				<!-- 1. Type -->
				<section class="wm-card">
					<h4 class="wm-card__title">
						{{ t('files_watermark', 'Watermark type') }}
					</h4>
					<p class="wm-card__desc">
						{{ t('files_watermark', 'What should be stamped onto the file?') }}
					</p>
					<div class="wm-type-options"
						role="radiogroup"
						:aria-label="t('files_watermark', 'Watermark type')">
						<label v-for="opt in TYPE_OPTIONS"
							:key="opt.value"
							class="wm-type-card"
							:class="{ 'is-active': form.type === opt.value }">
							<input v-model="form.type"
								class="wm-sr-only"
								type="radio"
								name="wm-type"
								:value="opt.value">
							<svg class="wm-type-card__icon" viewBox="0 0 24 24" aria-hidden="true">
								<path :d="opt.icon" />
							</svg>
							<span class="wm-type-card__label">{{ opt.label }}</span>
							<span class="wm-type-card__desc">{{ opt.desc }}</span>
						</label>
					</div>
				</section>

				<!-- 2a. Text content -->
				<section v-if="form.type !== 'image'" class="wm-card">
					<h4 class="wm-card__title">
						{{ t('files_watermark', 'Text content') }}
					</h4>
					<p class="wm-card__desc">
						{{ t('files_watermark', 'Type the text to stamp. Insert a placeholder to fill in details automatically.') }}
					</p>
					<NcTextField v-model="form.textTemplate"
						:label="t('files_watermark', 'Watermark text')"
						:placeholder="t('files_watermark', '{username} — {date}')" />
					<div class="wm-chips">
						<span class="wm-chips__hint">{{ t('files_watermark', 'Insert:') }}</span>
						<button v-for="ph in PLACEHOLDERS"
							:key="ph.token"
							type="button"
							class="wm-chip"
							:title="t('files_watermark', 'Example: {ex}', { ex: ph.example })"
							@click="insertPlaceholder(ph.token)">
							{{ ph.token }}
						</button>
					</div>
				</section>

				<!-- 2b. Image content -->
				<section v-if="form.type !== 'text'" class="wm-card">
					<h4 class="wm-card__title">
						{{ t('files_watermark', 'Watermark image') }}
					</h4>
					<p class="wm-card__desc">
						{{ t('files_watermark', 'The logo is centered on each page at 30% of its width.') }}
					</p>
					<NcTextField v-model="form.imagePath"
						:label="t('files_watermark', 'Image path in Nextcloud')"
						:placeholder="t('files_watermark', '/path/to/logo.png')" />
					<p class="wm-help">
						{{ t('files_watermark', 'Point to a PNG, JPG, or SVG file stored in Nextcloud.') }}
					</p>
					<p v-if="imagePathError" class="wm-field-error">
						{{ imagePathError }}
					</p>
				</section>

				<!-- 3. Appearance -->
				<section class="wm-card">
					<h4 class="wm-card__title">
						{{ t('files_watermark', 'Appearance') }}
					</h4>
					<p class="wm-card__desc">
						{{ appearanceDesc }}
					</p>
					<div class="wm-fields">
						<div v-if="form.type !== 'image'" class="wm-field">
							<label for="wm-fontsize">{{ t('files_watermark', 'Font size') }}</label>
							<div class="wm-inline">
								<input id="wm-fontsize"
									v-model.number="form.fontSize"
									type="range"
									min="6"
									max="120"
									class="wm-range">
								<span class="wm-inline__val">{{ form.fontSize }} pt</span>
							</div>
						</div>
						<div v-if="form.type !== 'image'" class="wm-field">
							<label for="wm-color">{{ t('files_watermark', 'Text color') }}</label>
							<div class="wm-inline">
								<input id="wm-color"
									v-model="form.color"
									type="color"
									class="wm-color">
								<span class="wm-inline__val wm-inline__val--mono">{{ form.color }}</span>
							</div>
						</div>
						<div class="wm-field">
							<label for="wm-opacity">{{ t('files_watermark', 'Opacity') }}</label>
							<div class="wm-inline">
								<input id="wm-opacity"
									v-model.number="form.opacity"
									type="range"
									min="0"
									max="100"
									class="wm-range">
								<span class="wm-inline__val">{{ form.opacity }}%</span>
							</div>
						</div>
						<div v-if="form.type !== 'image'" class="wm-field">
							<label for="wm-rotation">{{ t('files_watermark', 'Rotation') }}</label>
							<div class="wm-inline">
								<input id="wm-rotation"
									v-model.number="form.rotation"
									type="range"
									min="-180"
									max="180"
									class="wm-range">
								<span class="wm-inline__val">{{ form.rotation }}°</span>
							</div>
						</div>
					</div>
				</section>

				<!-- 4. Trigger -->
				<section class="wm-card">
					<h4 class="wm-card__title">
						{{ t('files_watermark', 'When to apply') }}
					</h4>
					<p class="wm-card__desc">
						{{ t('files_watermark', 'Choose the moment the watermark is stamped.') }}
					</p>
					<div class="wm-option-list"
						role="radiogroup"
						:aria-label="t('files_watermark', 'When to apply')">
						<label v-for="opt in TRIGGER_OPTIONS"
							:key="opt.value"
							class="wm-option"
							:class="{ 'is-active': form.trigger === opt.value }">
							<input v-model="form.trigger"
								class="wm-option__radio"
								type="radio"
								name="wm-trigger"
								:value="opt.value">
							<span class="wm-option__body">
								<span class="wm-option__label">{{ opt.label }}</span>
								<span class="wm-option__desc">{{ opt.desc }}</span>
							</span>
						</label>
					</div>
				</section>

				<!-- 5. Scope (admin only) -->
				<section v-if="isAdmin" class="wm-card">
					<h4 class="wm-card__title">
						{{ t('files_watermark', 'Where to apply') }}
					</h4>
					<p class="wm-card__desc">
						{{ t('files_watermark', 'Leave both blank to watermark every supported file.') }}
					</p>
					<div class="wm-field wm-field--stacked">
						<NcTextField v-model="form.mimeTypes"
							:label="t('files_watermark', 'Limit to file types')"
							:placeholder="t('files_watermark', 'application/pdf, image/jpeg')" />
						<small class="wm-help">{{ t('files_watermark', 'Comma-separated MIME types. Blank means all supported files.') }}</small>
					</div>
					<div class="wm-field wm-field--stacked">
						<NcTextField v-model="form.folderTag"
							:label="t('files_watermark', 'Limit to a tagged folder')"
							:placeholder="t('files_watermark', 'Nextcloud system-tag ID')" />
						<small class="wm-help">{{ t('files_watermark', 'Only files carrying this system tag are watermarked.') }}</small>
					</div>
				</section>
			</div>

			<!-- Live preview -->
			<aside class="wm-preview" aria-hidden="true">
				<div class="wm-preview__sticky">
					<span class="wm-preview__label">{{ t('files_watermark', 'Live preview') }}</span>
					<div class="wm-preview__page">
						<svg class="wm-preview__svg" :viewBox="`0 0 ${PV_W} ${PV_H}`" xmlns="http://www.w3.org/2000/svg">
							<defs>
								<pattern id="wm-text-pattern"
									patternUnits="userSpaceOnUse"
									:width="tile.w"
									:height="tile.h"
									:patternTransform="`rotate(${-form.rotation})`">
									<text :x="tile.w / 2"
										:y="tile.h / 2"
										text-anchor="middle"
										dominant-baseline="middle"
										:font-size="previewFont"
										font-weight="700"
										font-family="Arial, Helvetica, sans-serif"
										:fill="form.color"
										:fill-opacity="form.opacity / 100">{{ displayText }}</text>
								</pattern>
							</defs>

							<!-- Paper -->
							<rect x="0"
								y="0"
								:width="PV_W"
								:height="PV_H"
								fill="#ffffff" />
							<!-- Faux document content -->
							<rect x="26"
								y="40"
								width="118"
								height="11"
								rx="3"
								fill="#e4e4e4" />
							<rect v-for="(line, i) in contentLines"
								:key="i"
								x="26"
								:y="line.y"
								:width="line.w"
								height="7"
								rx="3"
								fill="#efefef" />

							<!-- Tiled text watermark -->
							<rect v-if="form.type !== 'image'"
								x="0"
								y="0"
								:width="PV_W"
								:height="PV_H"
								fill="url(#wm-text-pattern)" />

							<!-- Centered logo watermark -->
							<g v-if="form.type !== 'text'" :opacity="form.opacity / 100">
								<rect :x="logo.x"
									:y="logo.y"
									:width="logo.w"
									:height="logo.h"
									rx="4"
									fill="none"
									stroke="#8a8a8a"
									stroke-width="2"
									stroke-dasharray="6 4" />
								<text :x="PV_W / 2"
									:y="PV_H / 2"
									text-anchor="middle"
									dominant-baseline="middle"
									font-size="14"
									font-weight="700"
									font-family="Arial, Helvetica, sans-serif"
									fill="#8a8a8a">{{ logoLabel }}</text>
							</g>

							<!-- Paper border -->
							<rect x="1"
								y="1"
								:width="PV_W - 2"
								:height="PV_H - 2"
								fill="none"
								stroke="#d0d0d0"
								stroke-width="1" />
						</svg>
					</div>
					<p class="wm-preview__note">
						{{ previewNote }}
					</p>
				</div>
			</aside>
		</div>

		<div class="wm-actions">
			<NcButton class="wm-save"
				type="primary"
				:disabled="saving || !!imagePathError"
				native-type="button"
				@click="$emit('save', { ...form })">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('files_watermark', 'Save changes') }}
			</NcButton>
			<NcButton class="wm-reset"
				type="tertiary"
				native-type="button"
				@click="resetDefaults">
				{{ t('files_watermark', 'Reset to defaults') }}
			</NcButton>

			<span v-if="saveError" class="wm-status wm-status--error" role="alert">
				<svg viewBox="0 0 24 24" aria-hidden="true">
					<path d="M13,13H11V7H13M13,17H11V15H13M12,2A10,10 0 0,0 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12A10,10 0 0,0 12,2Z" />
				</svg>
				{{ saveError }}
			</span>
			<span v-else-if="saved" class="wm-status wm-status--success" role="status">
				<svg viewBox="0 0 24 24" aria-hidden="true">
					<path d="M12,2A10,10 0 0,1 22,12A10,10 0 0,1 12,22A10,10 0 0,1 2,12A10,10 0 0,1 12,2M11,16.5L18,9.5L16.59,8.09L11,13.67L7.91,10.59L6.5,12L11,16.5Z" />
				</svg>
				{{ t('files_watermark', 'Saved') }}
			</span>
		</div>
	</div>
</template>

<script setup>
import { reactive, watch, computed } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

const props = defineProps({
	title: { type: String, default: '' },
	modelValue: { type: Object, default: () => ({}) },
	isAdmin: { type: Boolean, default: false },
	saving: { type: Boolean, default: false },
	saved: { type: Boolean, default: false },
	saveError: { type: String, default: null },
})

const emit = defineEmits(['save', 'update:modelValue'])

const DEFAULTS = {
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
}

const form = reactive({ ...DEFAULTS, ...props.modelValue })

watch(form, (val) => emit('update:modelValue', { ...val }))

// Material Design icon paths (24×24) for the type picker.
const TYPE_OPTIONS = [
	{
		value: 'text',
		label: t('files_watermark', 'Text'),
		desc: t('files_watermark', 'Stamp a name, date, or custom text'),
		icon: 'M5,4V7H10.5V19H13.5V7H19V4H5Z',
	},
	{
		value: 'image',
		label: t('files_watermark', 'Image'),
		desc: t('files_watermark', 'Overlay a logo or picture'),
		icon: 'M8.5,13.5L11,16.5L14.5,12L19,18H5M21,19V5C21,3.89 20.1,3 19,3H5A2,2 0 0,0 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19Z',
	},
	{
		value: 'combined',
		label: t('files_watermark', 'Text + Image'),
		desc: t('files_watermark', 'Both text and a logo'),
		icon: 'M12,16L19.36,10.27L21,9L12,2L3,9L4.63,10.27M12,18.54L4.62,12.81L3,14.07L12,21.07L21,14.07L19.37,12.8L12,18.54Z',
	},
]

const TRIGGER_OPTIONS = [
	{ value: 'on_demand', label: t('files_watermark', 'On demand'), desc: t('files_watermark', 'Only when someone picks “Apply watermark” on a file.') },
	{ value: 'on_download', label: t('files_watermark', 'On download'), desc: t('files_watermark', 'Each time the file is downloaded.') },
	{ value: 'on_share', label: t('files_watermark', 'On share'), desc: t('files_watermark', 'When a share recipient downloads the file. Your original stays untouched.') },
	{ value: 'on_upload', label: t('files_watermark', 'On upload'), desc: t('files_watermark', 'Automatically when a matching file is uploaded.') },
]

const SAMPLE = {
	username: 'john.doe',
	email: 'john.doe@example.com',
	date: new Date().toISOString().slice(0, 10),
	datetime: new Date().toISOString().slice(0, 19).replace('T', ' '),
	filename: 'document.pdf',
}

const PLACEHOLDERS = [
	{ token: '{username}', example: SAMPLE.username },
	{ token: '{email}', example: SAMPLE.email },
	{ token: '{date}', example: SAMPLE.date },
	{ token: '{datetime}', example: SAMPLE.datetime },
	{ token: '{filename}', example: SAMPLE.filename },
]

const previewText = computed(() => {
	if (!form.textTemplate) return ''
	return form.textTemplate.replace(/\{(\w+)\}/g, (_, key) => SAMPLE[key] ?? `{${key}}`)
})

const displayText = computed(() => previewText.value || `${SAMPLE.username} — ${SAMPLE.date}`)

const ALLOWED_IMAGE_EXTS = ['png', 'jpg', 'jpeg', 'svg']

const imagePathError = computed(() => {
	if (!form.imagePath) return null
	const ext = form.imagePath.split('.').pop().toLowerCase()
	if (!ALLOWED_IMAGE_EXTS.includes(ext)) {
		return t('files_watermark', 'Image must be a PNG, JPG, or SVG file.')
	}
	return null
})

const appearanceDesc = computed(() => form.type === 'image'
	? t('files_watermark', 'Adjust how strongly the logo shows through.')
	: t('files_watermark', 'Adjust the size, color, opacity, and angle of the text.'))

const previewNote = computed(() => {
	switch (form.type) {
	case 'image':
		return t('files_watermark', 'Your logo, centered on every page.')
	case 'combined':
		return t('files_watermark', 'Tiled text with your logo centered on top.')
	default:
		return t('files_watermark', 'Text tiled diagonally across every page.')
	}
})

/**
 * Insert a placeholder token at the end of the current template.
 * @param {string} token - The placeholder to append, e.g. '{username}'
 */
function insertPlaceholder(token) {
	const current = form.textTemplate ?? ''
	form.textTemplate = current && !current.endsWith(' ') ? `${current} ${token}` : `${current}${token}`
}

/** Restore every field to its shipped default. */
function resetDefaults() {
	Object.assign(form, DEFAULTS)
}

// --- Live preview geometry (portrait page, ~A4 ratio) ---
const PV_W = 300
const PV_H = 400

const previewFont = computed(() => Math.min(80, Math.max(7, Math.round((form.fontSize || 24) * 0.55))))

const tile = computed(() => {
	const font = previewFont.value
	const charW = font * 0.56
	const textW = Math.max(displayText.value.length * charW, font)
	return {
		w: Math.round(textW + font * 2.2),
		h: Math.round(font * 2.6),
	}
})

const logo = computed(() => {
	const w = PV_W * 0.3
	const h = w * 0.5
	return { w, h, x: (PV_W - w) / 2, y: (PV_H - h) / 2 }
})

const logoLabel = computed(() => {
	if (!form.imagePath) return 'LOGO'
	const base = form.imagePath.split('/').pop() || 'LOGO'
	return base.length > 16 ? base.slice(0, 15) + '…' : base
})

const contentLines = [
	{ y: 74, w: 232 }, { y: 106, w: 210 }, { y: 138, w: 244 },
	{ y: 170, w: 186 }, { y: 202, w: 228 }, { y: 234, w: 200 },
	{ y: 266, w: 240 }, { y: 298, w: 172 }, { y: 330, w: 214 },
]
</script>

<style scoped>
.watermark-form {
    max-width: 980px;
}
.wm-title {
    margin: 0 0 16px;
    font-size: 20px;
    font-weight: 700;
}

/* Two-column layout: settings + sticky preview */
.wm-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 20px;
}
@media (min-width: 880px) {
    .wm-grid {
        grid-template-columns: minmax(0, 1fr) 300px;
        align-items: start;
    }
}
.wm-main {
    display: flex;
    flex-direction: column;
    gap: 16px;
    min-width: 0;
}

/* Grouped setting cards */
.wm-card {
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large, 12px);
    padding: 16px 18px;
    background: var(--color-main-background);
}
.wm-card__title {
    margin: 0 0 2px;
    font-size: 15px;
    font-weight: 700;
}
.wm-card__desc {
    margin: 0 0 14px;
    font-size: 13px;
    color: var(--color-text-maxcontrast);
}

/* Type picker cards */
.wm-type-options {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 10px;
}
.wm-type-card {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: 6px;
    padding: 14px 10px;
    border: 2px solid var(--color-border);
    border-radius: var(--border-radius-large, 12px);
    cursor: pointer;
    transition: border-color 0.1s ease, background-color 0.1s ease;
}
.wm-type-card:hover {
    background: var(--color-background-hover);
}
.wm-type-card.is-active {
    border-color: var(--color-primary-element);
    background: color-mix(in srgb, var(--color-primary-element) 8%, transparent);
}
.wm-type-card:focus-within {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}
.wm-type-card__icon {
    width: 28px;
    height: 28px;
    fill: var(--color-text-maxcontrast);
}
.wm-type-card.is-active .wm-type-card__icon {
    fill: var(--color-primary-element);
}
.wm-type-card__label {
    font-size: 13px;
    font-weight: 700;
}
.wm-type-card__desc {
    font-size: 11px;
    line-height: 1.3;
    color: var(--color-text-maxcontrast);
}

/* Placeholder chips */
.wm-chips {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
    margin-top: 12px;
}
.wm-chips__hint {
    font-size: 12px;
    color: var(--color-text-maxcontrast);
}
.wm-chip {
    padding: 3px 10px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-pill, 16px);
    background: var(--color-background-hover);
    color: var(--color-main-text);
    font-family: var(--font-face-monospace, monospace);
    font-size: 12px;
    cursor: pointer;
    transition: border-color 0.1s ease, background-color 0.1s ease;
}
.wm-chip:hover {
    border-color: var(--color-primary-element);
    background: color-mix(in srgb, var(--color-primary-element) 10%, transparent);
}

/* Appearance fields */
.wm-fields {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: 16px;
}
.wm-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.wm-field > label {
    font-size: 13px;
    font-weight: 600;
}
.wm-inline {
    display: flex;
    align-items: center;
    gap: 10px;
}
.wm-inline__val {
    min-width: 46px;
    font-size: 13px;
    font-variant-numeric: tabular-nums;
    color: var(--color-text-maxcontrast);
}
.wm-inline__val--mono {
    font-family: var(--font-face-monospace, monospace);
    text-transform: uppercase;
}
.wm-range {
    flex: 1;
    min-width: 0;
    accent-color: var(--color-primary-element);
}
.wm-color {
    width: 44px;
    height: 34px;
    padding: 2px;
    border: 1px solid var(--color-border-dark, #ccc);
    border-radius: var(--border-radius, 6px);
    background: var(--color-main-background);
    cursor: pointer;
}
.wm-field--stacked {
    margin-bottom: 14px;
}
.wm-field--stacked:last-child {
    margin-bottom: 0;
}

/* Radio option list (trigger) */
.wm-option-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.wm-option {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 12px 14px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large, 12px);
    cursor: pointer;
    transition: border-color 0.1s ease, background-color 0.1s ease;
}
.wm-option:hover {
    background: var(--color-background-hover);
}
.wm-option.is-active {
    border-color: var(--color-primary-element);
    background: color-mix(in srgb, var(--color-primary-element) 8%, transparent);
}
.wm-option__radio {
    flex: none;
    width: 16px;
    height: 16px;
    min-width: 0;
    min-height: 0;
    margin: 1px 0 0;
    accent-color: var(--color-primary-element);
    /* Nextcloud's global input styles otherwise draw a dark border/box-shadow
       around the native radio on hover and focus — strip it here. */
    border: none !important;
    box-shadow: none !important;
    background-color: transparent !important;
}
.wm-option__radio:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 1px;
}
.wm-option__body {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.wm-option__label {
    font-size: 13px;
    font-weight: 600;
}
.wm-option__desc {
    font-size: 12px;
    color: var(--color-text-maxcontrast);
}

.wm-help {
    margin: 4px 0 0;
    font-size: 12px;
    color: var(--color-text-maxcontrast);
}
.wm-field-error {
    margin: 8px 0 0;
    font-size: 13px;
    color: var(--color-error, #e9322d);
}

/* Live preview */
.wm-preview__sticky {
    position: static;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
@media (min-width: 880px) {
    .wm-preview__sticky {
        position: sticky;
        top: 16px;
    }
}
.wm-preview__label {
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--color-text-maxcontrast);
}
.wm-preview__page {
    aspect-ratio: 3 / 4;
    width: 100%;
    max-width: 300px;
    border-radius: var(--border-radius, 6px);
    overflow: hidden;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.18);
}
.wm-preview__svg {
    display: block;
    width: 100%;
    height: 100%;
}
.wm-preview__note {
    margin: 0;
    max-width: 300px;
    font-size: 12px;
    color: var(--color-text-maxcontrast);
}

/* Actions */
.wm-actions {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px 12px;
    margin-top: 24px;
}
.wm-status {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    font-weight: 600;
    animation: wm-status-in 0.18s ease-out;
}
.wm-status svg {
    width: 18px;
    height: 18px;
    flex: none;
    fill: currentColor;
}
.wm-status--success {
    color: var(--color-success, #2d7b41);
}
.wm-status--error {
    color: var(--color-error, #c7361f);
}
@keyframes wm-status-in {
    from { opacity: 0; transform: translateX(-4px); }
    to { opacity: 1; transform: none; }
}
@media (prefers-reduced-motion: reduce) {
    .wm-status { animation: none; }
}

.wm-sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    margin: -1px;
    padding: 0;
    overflow: hidden;
    clip: rect(0 0 0 0);
    border: 0;
}
</style>
