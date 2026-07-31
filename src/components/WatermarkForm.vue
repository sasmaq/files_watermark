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
					<p class="wm-card__desc">
						{{ identityHelp }}
					</p>
					<!--
						The placeholder is the shipped default, not a translatable string:
						the tokens are identifiers the server matches literally, so a
						translated copy of them would offer an admin a template that comes
						straight back as a 400. Reading it from DEFAULTS also keeps the hint
						honest when the default changes.
					-->
					<NcTextField v-model="form.textTemplate"
						:label="t('files_watermark', 'Watermark text')"
						:placeholder="DEFAULTS.textTemplate" />
					<div class="wm-chips">
						<span class="wm-chips__hint">{{ t('files_watermark', 'Insert:') }}</span>
						<button v-for="ph in PLACEHOLDERS"
							:key="ph.token"
							type="button"
							class="wm-chip"
							:title="t('files_watermark', '{label} — example: {ex}', { label: ph.label, ex: ph.example })"
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
					<input ref="fileInput"
						type="file"
						class="wm-file-input"
						accept="image/png,image/jpeg"
						@change="onImageSelected">
					<div class="wm-upload">
						<NcButton type="secondary" :disabled="uploading" @click="pickImage">
							<template #icon>
								<NcLoadingIcon v-if="uploading" :size="20" />
							</template>
							{{ form.imagePath ? t('files_watermark', 'Replace image') : t('files_watermark', 'Upload image') }}
						</NcButton>
						<NcButton v-if="form.imagePath"
							type="tertiary"
							:disabled="uploading"
							@click="clearImage">
							{{ t('files_watermark', 'Remove image') }}
						</NcButton>
						<img v-if="imagePreviewUrl"
							:src="imagePreviewUrl"
							class="wm-upload__thumb"
							:alt="t('files_watermark', 'Watermark image preview')">
						<span v-if="form.imagePath && !imagePreviewUrl" class="wm-upload__name">
							{{ t('files_watermark', 'Image uploaded') }}
						</span>
					</div>
					<p class="wm-help">
						{{ t('files_watermark', 'Upload a PNG or JPEG image, up to {max} MB.', { max: MAX_IMAGE_MB }) }}
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
						{{ t('files_watermark', 'Narrow the policy, or leave everything untouched to cover every supported file.') }}
					</p>
					<div class="wm-field wm-field--stacked">
						<label class="wm-field__label">{{ t('files_watermark', 'Limit to file types') }}</label>
						<!--
							A fixed list rather than free text: a typed MIME type that the app
							cannot render (or a plain typo) is a filter nothing can match, which
							silently turns the whole policy into a no-op.
						-->
						<div class="wm-checks">
							<NcCheckboxRadioSwitch v-for="opt in MIME_OPTIONS"
								:key="opt.value"
								:model-value="selectedMimeTypes.includes(opt.value)"
								@update:model-value="toggleMimeType(opt.value, $event)">
								{{ opt.label }}
							</NcCheckboxRadioSwitch>
						</div>
						<small class="wm-help">{{ t('files_watermark', 'Select none to watermark every supported file type.') }}</small>
					</div>
					<div class="wm-field wm-field--stacked">
						<label class="wm-field__label">{{ t('files_watermark', 'Limit to a tagged folder') }}</label>
						<!--
							Picked from the server's real tags, so the stored value is always an
							id that exists. Hand-typing it here used to be possible, and a tag
							*name* — the obvious thing to type — made every watermark fail.
						-->
						<NcSelectTags v-model="selectedFolderTag"
							:multiple="false"
							:placeholder="t('files_watermark', 'Any folder')" />
						<small class="wm-help">{{ t('files_watermark', 'Only files whose containing folder carries this tag are watermarked. The tag goes on the folder, not on the files.') }}</small>
					</div>
				</section>
			</div>

			<!-- Live preview -->
			<aside class="wm-preview" aria-hidden="true">
				<div class="wm-preview__sticky">
					<span class="wm-preview__label">{{ t('files_watermark', 'Live preview') }}</span>
					<div class="wm-preview__page">
						<!--
							`dir="ltr"` pins the page mock-up itself: it is a picture of a
							document, not a block of interface text, so its faux content lines
							and its logo box must not flip when the settings page is rendered
							RTL. The watermark text inside sets its own direction from the
							template — see `previewDirection`.
						-->
						<svg class="wm-preview__svg"
							dir="ltr"
							:viewBox="`0 0 ${PV_W} ${PV_H}`"
							xmlns="http://www.w3.org/2000/svg">
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
										:direction="previewDirection"
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
import { reactive, ref, watch, computed } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSelectTags from '@nextcloud/vue/components/NcSelectTags'
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
	// Matches WatermarkService::defaultConfig(): the display name, since a watermark is
	// read by a person. {username} is available for when exactness matters more.
	textTemplate: '{displayname} - {date}',
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

// Exactly the types the renderers handle — WatermarkService::SUPPORTED_ALL.
// saveConfig rejects anything else, so offering anything else would be a lie.
const MIME_OPTIONS = [
	{ value: 'application/pdf', label: t('files_watermark', 'PDF') },
	{ value: 'image/jpeg', label: t('files_watermark', 'JPEG image') },
	{ value: 'image/png', label: t('files_watermark', 'PNG image') },
	{ value: 'image/webp', label: t('files_watermark', 'WEBP image') },
]

/** The stored comma-separated string, as a list the checkboxes can read. */
const selectedMimeTypes = computed(() =>
	(form.mimeTypes ?? '').split(',').map((m) => m.trim()).filter(Boolean),
)

/**
 * Add or remove one type, keeping the stored value in the canonical order rather
 * than in click order.
 * @param {string} value - The MIME type toggled
 * @param {boolean} checked - Whether it is now selected
 */
function toggleMimeType(value, checked) {
	const next = new Set(selectedMimeTypes.value)
	if (checked) {
		next.add(value)
	} else {
		next.delete(value)
	}
	form.mimeTypes = MIME_OPTIONS.filter((o) => next.has(o.value)).map((o) => o.value).join(',')
}

/**
 * NcSelectTags works in numeric tag ids; the config stores one id as a string,
 * with blank meaning "any folder".
 */
const selectedFolderTag = computed({
	get: () => (form.folderTag ? Number(form.folderTag) : null),
	set: (tag) => {
		// The picker hands back an id, or a tag object depending on how it resolves.
		const id = tag === null || tag === undefined ? null : (tag.id ?? tag)
		form.folderTag = id === null ? '' : String(id)
	},
})

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

// The two identity samples are deliberately unalike, because they are what tells an admin
// which token they want: the account name is the login/uid, the display name is the
// human-readable one that a person reading the watermark will recognise.
//
// They are translated, not fixed: the preview exists to show an admin what their own
// watermarks will look like, and "John Doe" in a Latin face tells an Arabic deployment
// nothing about how its own display names will render. A translated sample puts real
// Arabic through the preview's shaping and direction handling on first load.
const SAMPLE = {
	username: t('files_watermark', 'john.doe'),
	displayname: t('files_watermark', 'John Doe'),
	email: t('files_watermark', 'john.doe@example.com'),
	date: new Date().toISOString().slice(0, 10),
	datetime: new Date().toISOString().slice(0, 19).replace('T', ' '),
	filename: t('files_watermark', 'document.pdf'),
}

// The help text names the two samples, so it takes them as parameters rather than
// repeating them. Spelled out in the copy they would be a second place to translate and
// a second place to drift, and an admin comparing the sentence against the chip tooltips
// would be shown two different example names for the same token.
const identityHelp = t(
	'files_watermark',
	'{displayname} is the name shown in Nextcloud ({sampleDisplayname}); {username} is the account name used to sign in ({sampleUsername}). Display names can change and are not unique — use the account name when the watermark has to identify exactly one account.',
	{ sampleDisplayname: SAMPLE.displayname, sampleUsername: SAMPLE.username },
)

const PLACEHOLDERS = [
	{ token: '{displayname}', label: t('files_watermark', 'Display name'), example: SAMPLE.displayname },
	{ token: '{username}', label: t('files_watermark', 'Account name'), example: SAMPLE.username },
	{ token: '{email}', label: t('files_watermark', 'Email address'), example: SAMPLE.email },
	{ token: '{date}', label: t('files_watermark', 'Date'), example: SAMPLE.date },
	{ token: '{datetime}', label: t('files_watermark', 'Date and time'), example: SAMPLE.datetime },
	{ token: '{filename}', label: t('files_watermark', 'File name'), example: SAMPLE.filename },
]

const previewText = computed(() => {
	if (!form.textTemplate) return ''
	return form.textTemplate.replace(/\{(\w+)\}/g, (_, key) => SAMPLE[key] ?? `{${key}}`)
})

const displayText = computed(() => previewText.value || `${SAMPLE.displayname} - ${SAMPLE.date}`)

// Scripts whose letters are strong right-to-left. Enough to decide a base direction; the
// list is the RTL scripts a watermark template is plausibly written in.
const RTL_SCRIPT = /[\p{Script=Arabic}\p{Script=Hebrew}\p{Script=Syriac}\p{Script=Thaana}\p{Script=Nko}]/u

/**
 * The base direction to draw the preview text in, decided by the *text itself* — its first
 * strong character — and never by the UI language.
 *
 * That rule is not a style choice: it is what the server-side shaper does. `Com\Tecnick\
 * Unicode\Bidi` resolves the paragraph direction from the first strong character of the
 * string it is handed, so an Arabic template is reordered right-to-left whatever locale the
 * admin who saved it was using. Letting the SVG inherit `dir` from the page would make the
 * preview say two different things about one stored template depending on who opened the
 * settings — and only one of them could match the file that comes out.
 * @param {string} text - the text about to be drawn
 * @return {string} 'rtl' or 'ltr'
 */
function baseDirection(text) {
	const firstLetter = (text ?? '').match(/\p{L}/u)
	return firstLetter && RTL_SCRIPT.test(firstLetter[0]) ? 'rtl' : 'ltr'
}

const previewDirection = computed(() => baseDirection(displayText.value))

// Kept in step with WatermarkImageStore::MAX_BYTES and its allowed types. Checking here
// too only saves the user a round-trip — the server re-validates from the file's actual
// content, which is the check that counts.
const MAX_IMAGE_MB = 2
const ALLOWED_IMAGE_TYPES = ['image/png', 'image/jpeg']

const fileInput = ref(null)
const uploading = ref(false)
const uploadError = ref(null)
// Object URL of the just-picked file, so the admin sees what they uploaded without the
// server having to serve the stored image back.
const imagePreviewUrl = ref(null)

const imagePathError = computed(() => uploadError.value)

/**
 * Validate a picked file before uploading it.
 * @param {File} file - the file the admin chose
 * @return {string|null} an error message, or null when acceptable
 */
function validateImageFile(file) {
	if (!file) return null
	if (!ALLOWED_IMAGE_TYPES.includes(file.type)) {
		return t('files_watermark', 'Image must be a PNG or JPEG file.')
	}
	if (file.size > MAX_IMAGE_MB * 1024 * 1024) {
		return t('files_watermark', 'Image must be smaller than {max} MB.', { max: MAX_IMAGE_MB })
	}
	return null
}

/**
 *
 */
function pickImage() {
	fileInput.value?.click()
}

/**
 * Upload the picked image and store the reference the server hands back. The config keeps
 * only that reference — the bytes live in the app's appdata.
 * @param {Event} event - the file input's change event
 */
async function onImageSelected(event) {
	const file = event.target?.files?.[0]
	if (!file) return

	uploadError.value = validateImageFile(file)
	if (uploadError.value) {
		event.target.value = ''
		return
	}

	uploading.value = true
	try {
		const body = new FormData()
		body.append('image', file)
		const res = await axios.post(generateUrl('/apps/files_watermark/api/v1/image'), body)
		form.imagePath = res?.data?.imagePath ?? ''
		if (imagePreviewUrl.value) URL.revokeObjectURL(imagePreviewUrl.value)
		imagePreviewUrl.value = URL.createObjectURL(file)
	} catch (e) {
		uploadError.value = e?.response?.data?.error ?? e.message
	} finally {
		uploading.value = false
		// Let the same file be picked again after a failure.
		event.target.value = ''
	}
}

/**
 *
 */
function clearImage() {
	form.imagePath = ''
	uploadError.value = null
	if (imagePreviewUrl.value) {
		URL.revokeObjectURL(imagePreviewUrl.value)
		imagePreviewUrl.value = null
	}
}

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

// Tile spacing, mirroring TileLattice::GAP_FACTOR and ::LINE_HEIGHT_FACTOR on the server.
// The preview is the contract an admin approves against, so a preview that spaces its
// repetitions differently from the renderers is a preview that promises the wrong picture.
// It used to: `font * 2.2` across and a flat `font * 2.6` down, against the renderers'
// `fontSize * 2` and `lineHeight + fontSize * 2`. Keep these three numbers in step.
const GAP_FACTOR = 3.5
const LINE_HEIGHT_FACTOR = 1.2

const tile = computed(() => {
	const font = previewFont.value
	const charW = font * 0.56
	const textW = Math.max(displayText.value.length * charW, font)
	return {
		w: Math.round(textW + font * GAP_FACTOR),
		h: Math.round(font * LINE_HEIGHT_FACTOR + font * GAP_FACTOR),
	}
})

const logo = computed(() => {
	const w = PV_W * 0.3
	const h = w * 0.5
	return { w, h, x: (PV_W - w) / 2, y: (PV_H - h) / 2 }
})

// The stored value is an opaque reference now, not a filename, so there is nothing
// human-readable to show — the preview box just marks where the logo sits. One
// translated label either way: the two branches used to differ only in that one of them
// went through t(), so an Arabic admin saw the word change when they uploaded a file.
const logoLabel = t('files_watermark', 'LOGO')

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
/* Stands in for the <label for> the picker/checkbox group has no single input for. */
.wm-field__label {
    font-size: 13px;
    font-weight: 600;
}
.wm-checks {
    display: flex;
    flex-wrap: wrap;
    gap: 2px 18px;
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
/* The real input is driven by the buttons above it; hidden rather than removed so it
   stays focusable and keeps native file-picker behaviour. */
.wm-file-input {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}
.wm-upload {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.wm-upload__thumb {
    max-width: 64px;
    max-height: 40px;
    object-fit: contain;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    background: var(--color-background-dark);
    padding: 2px;
}
.wm-upload__name {
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
/* Arabic letters join, and letter-spacing pulls the joins apart — the word comes out as
   disconnected characters. Neither does uppercasing mean anything in a script with no
   case, so both are dropped rather than kept and ignored. */
[dir="rtl"] .wm-preview__label {
    text-transform: none;
    letter-spacing: normal;
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
/* The status slides in from the side the text starts on, so it reads as arriving rather
   than as being pushed backwards. That side is mirrored under RTL — a keyframe cannot
   take a logical property, so the direction is chosen by a second animation. */
@keyframes wm-status-in {
    from { opacity: 0; transform: translateX(-4px); }
    to { opacity: 1; transform: none; }
}
@keyframes wm-status-in-rtl {
    from { opacity: 0; transform: translateX(4px); }
    to { opacity: 1; transform: none; }
}
[dir="rtl"] .wm-status {
    animation-name: wm-status-in-rtl;
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
