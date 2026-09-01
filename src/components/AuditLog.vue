<template>
	<div class="audit-log">
		<div v-if="loading" class="loading-wrapper">
			<NcLoadingIcon :size="24" />
		</div>

		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<template v-else>
			<div class="log-card">
				<div v-if="rows.length" class="log-scroll">
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
							<tr v-for="row in rows" :key="row.id">
								<td class="col-date">
									<span class="date-main">{{ row.date }}</span>
									<span v-if="row.time" class="date-time">{{ row.time }}</span>
								</td>
								<td>
									<span class="user-name">{{ row.userId }}</span>
								</td>
								<td>
									<span class="file-cell" :title="row.filePath">
										<svg class="file-icon" viewBox="0 0 24 24" aria-hidden="true">
											<path d="M13,9V3.5L18.5,9M6,2C4.89,2 4,2.89 4,4V20A2,2 0 0,0 6,22H18A2,2 0 0,0 20,20V8L14,2H6Z" />
										</svg>
										<!--
											A path is structurally left-to-right whatever the
											interface language is. Left to inherit `dir`, the
											leading slash of `/Documents/تقرير.pdf` is a neutral
											at the start of an RTL paragraph and gets rendered at
											the far end - the path reads as though it were named
											backwards.
										-->
										<span class="file-path" dir="ltr">{{ row.filePath }}</span>
									</span>
								</td>
								<td>
									<!--
										Icon *and* colour, never colour alone: an audit log gets
										screenshotted, printed and read by people who cannot tell
										the hues apart, and "watermark removed" must not be one
										shade away from "downloaded" in any of those.
									-->
									<span class="trigger-badge" :class="'trigger-badge--' + row.trigger">
										<svg class="trigger-icon"
											viewBox="0 0 24 24"
											fill-rule="evenodd"
											aria-hidden="true">
											<path :d="row.icon" />
										</svg>
										{{ row.label }}
									</span>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<div v-else class="empty-state">
					<svg class="empty-icon" viewBox="0 0 24 24" aria-hidden="true">
						<path d="M13.5,8H12V13L16.28,15.54L17,14.33L13.5,12.25V8M13,3A9,9 0 0,0 4,12H1L4.96,16.03L9,12H6A7,7 0 0,1 13,5A7,7 0 0,1 20,12A7,7 0 0,1 13,19C11.07,19 9.32,18.21 8.06,16.94L6.64,18.36C8.27,20 10.5,21 13,21A9,9 0 0,0 22,12A9,9 0 0,0 13,3Z" />
					</svg>
					<p class="empty-title">
						{{ t('files_watermark', 'No entries yet.') }}
					</p>
					<p class="empty-sub">
						{{ t('files_watermark', 'Watermark activity will appear here as files are stamped.') }}
					</p>
				</div>
			</div>

			<!--
				Which clock the two date columns are on. The API converts every row into the
				instance's timezone, and an hour with no zone beside it cannot be reconciled
				with anything - a mail timestamp, a server log, or what the person who
				downloaded the file remembers.
			-->
			<p v-if="rows.length" class="log-timezone">
				{{ t('files_watermark', 'Times shown in {timezone}', { timezone: timeZone }) }}
			</p>

			<div v-if="showPagination" class="pagination-bar">
				<div class="page-size">
					<label for="audit-page-size">{{ t('files_watermark', 'Rows per page') }}</label>
					<select id="audit-page-size"
						v-model.number="limit"
						class="page-size-select"
						@change="onPageSizeChange">
						<option :value="25">
							25
						</option>
						<option :value="50">
							50
						</option>
						<option :value="100">
							100
						</option>
					</select>
				</div>
				<div class="page-nav">
					<span v-if="rows.length" class="page-range">
						{{ t('files_watermark', 'Showing {from}–{to}', { from: rangeStart, to: rangeEnd }) }}
					</span>
					<NcButton :disabled="offset === 0" @click="prev">
						{{ t('files_watermark', 'Previous') }}
					</NcButton>
					<NcButton :disabled="rows.length < limit" @click="next">
						{{ t('files_watermark', 'Next') }}
					</NcButton>
				</div>
			</div>
		</template>
	</div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'

// Provided by AdminSettings::getForm(). The fallback is what the server falls back to when
// `default_timezone` is unset, so a page that somehow loads without state says UTC rather
// than an empty parenthesis - and never claims a zone the rows are not in.
const timeZone = loadState('files_watermark', 'time-zone', 'UTC')

const entries = ref([])
const loading = ref(false)
const error = ref(null)
const limit = ref(50)
const offset = ref(0)

// Two of these are policies - which trigger marked the file - and two are events the log
// records on its own: a mark taken off, and one watermarked copy handed to one reader.
// `removed` and `replaced` are gone with the burn that produced them.
const TRIGGER_LABELS = {
	on_demand: t('files_watermark', 'On demand'),
	on_upload: t('files_watermark', 'On upload'),
	unmarked: t('files_watermark', 'Watermark removed'),
	delivered: t('files_watermark', 'Downloaded'),
}

/**
 * One 24x24 glyph per kind of row, drawn with `fill-rule="evenodd"` so the cut-outs knock
 * through whichever way their subpaths wind.
 *
 * The two shields are deliberately the same silhouette as the badge the Files list puts on
 * a marked file, so "this file was protected" and "that protection was taken off" read as
 * the same object in two states rather than as two unrelated symbols. The arrows point the
 * way the file was moving: in on upload, out on delivery.
 */
const SHIELD = 'M12 2 4 5v6c0 5 3.4 8.5 8 11 4.6-2.5 8-6 8-11V5l-8-3Z'
const TRIGGER_ICONS = {
	// Shield + check: someone deliberately marked this file.
	on_demand: SHIELD + 'M10.8 15.2 7.5 11.9l1.4-1.4 1.9 1.9 4.5-4.5 1.4 1.4-5.9 5.9Z',
	// Arrow into a tray: marked by policy as the file arrived.
	on_upload: 'M12 3 6 9h4v6h4V9h4L12 3ZM5 18h14v2H5Z',
	// Arrow out of a tray: one watermarked copy handed to one reader.
	delivered: 'M12 16 6 10h4V4h4v6h4l-6 6ZM5 18h14v2H5Z',
	// The same shield with the check struck out: protection removed.
	unmarked: SHIELD + 'M8 11h8v2H8v-2Z',
}

// A row whose trigger this version does not know - written by an older or newer release -
// still has to render. A plain dot says "an event" and claims nothing about which.
const UNKNOWN_TRIGGER_ICON = 'M12 7a5 5 0 1 0 0 10 5 5 0 0 0 0-10Z'

// Precompute display-friendly fields so the template stays declarative.
const rows = computed(() => entries.value.map((e) => ({
	id: e.id,
	userId: e.userId,
	filePath: e.filePath,
	trigger: e.trigger,
	date: (e.createdAt || '').split(' ')[0] || (e.createdAt || ''),
	time: (e.createdAt || '').split(' ')[1] || '',
	label: TRIGGER_LABELS[e.trigger] ?? e.trigger,
	icon: TRIGGER_ICONS[e.trigger] ?? UNKNOWN_TRIGGER_ICON,
})))

const showPagination = computed(() => entries.value.length > 0 || offset.value > 0)
const rangeStart = computed(() => (entries.value.length ? offset.value + 1 : 0))
const rangeEnd = computed(() => offset.value + entries.value.length)

/**
 * Load one page of the watermark activity log from the API.
 */
async function fetchLog() {
	loading.value = true
	error.value = null
	try {
		const res = await axios.get(generateUrl('/apps/files_watermark/api/v1/log'), {
			params: { limit: limit.value, offset: offset.value },
		})
		entries.value = res.data
	} catch (e) {
		error.value = e?.response?.data?.error ?? e.message
	} finally {
		loading.value = false
	}
}

/**
 * Reset to the first page and reload after the page size changes.
 */
function onPageSizeChange() {
	offset.value = 0
	fetchLog()
}

/**
 * Move back one page.
 */
function prev() {
	offset.value = Math.max(0, offset.value - limit.value)
	fetchLog()
}
/**
 * Move forward one page.
 */
function next() {
	offset.value += limit.value
	fetchLog()
}

onMounted(fetchLog)
</script>

<style scoped>
.loading-wrapper {
	display: flex;
	justify-content: center;
	padding: 32px;
}

.log-card {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	overflow: hidden;
	background: var(--color-main-background);
}
.log-scroll {
	overflow-x: auto;
}

.log-table {
	width: 100%;
	min-width: 560px;
	border-collapse: collapse;
	font-size: 14px;
}
.log-table thead th {
	/* `start`, not `left`: the header has to follow the reading direction, otherwise an
	   RTL table has every heading pinned to the wrong end of its own column. */
	text-align: start;
	padding: 11px 16px;
	font-size: 11px;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.05em;
	color: var(--color-text-maxcontrast);
	background: var(--color-background-hover);
	border-bottom: 1px solid var(--color-border);
	white-space: nowrap;
}
/* Arabic is a joined script: letter-spacing breaks the joins apart, and there is no case
   for text-transform to change. Both are undone rather than left to apply harmlessly. */
[dir="rtl"] .log-table thead th {
	text-transform: none;
	letter-spacing: normal;
}
.log-table tbody td {
	padding: 12px 16px;
	border-bottom: 1px solid var(--color-border);
	vertical-align: middle;
}
.log-table tbody tr:last-child td {
	border-bottom: none;
}
.log-table tbody tr:hover td {
	background: var(--color-background-hover);
}

.col-date {
	white-space: nowrap;
}
.date-main {
	font-variant-numeric: tabular-nums;
}
.date-time {
	margin-inline-start: 6px;
	font-size: 13px;
	font-variant-numeric: tabular-nums;
	color: var(--color-text-maxcontrast);
}
.log-timezone {
	margin: 8px 2px 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.user-name {
	font-weight: 500;
}

.file-cell {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	max-width: 340px;
}
.file-icon {
	flex: none;
	width: 18px;
	height: 18px;
	fill: var(--color-text-maxcontrast);
}
.file-path {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-family: var(--font-face-monospace, monospace);
	font-size: 13px;
}

/*
   One hue per kind, carried by the whole badge rather than by a 7px dot, so a page of
   rows groups at a glance instead of having to be read line by line. The hue lives in a
   custom property and every other rule reads it from there - a new trigger needs one
   modifier and nothing else.

   The tint is `color-mix` against `transparent`, not a second hard-coded colour, so the
   same four declarations sit correctly on the light and the dark background.
*/
.trigger-badge {
	--trigger-color: var(--color-text-maxcontrast);
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 3px 10px 3px 8px;
	border: 1px solid color-mix(in srgb, var(--trigger-color) 30%, transparent);
	border-radius: var(--border-radius-pill, 16px);
	background: color-mix(in srgb, var(--trigger-color) 13%, transparent);
	color: var(--trigger-color);
	font-size: 12px;
	font-weight: 600;
	line-height: 18px;
	white-space: nowrap;
}
.trigger-icon {
	flex: none;
	width: 14px;
	height: 14px;
	fill: currentColor;
}

/* Marked on purpose by a person. */
.trigger-badge--on_demand { --trigger-color: #6d5bd0; }
/* Marked by the policy, without anybody deciding. */
.trigger-badge--on_upload { --trigger-color: #2f9e5a; }
/* One copy handed to one reader - the routine row, and by far the most common. */
.trigger-badge--delivered { --trigger-color: #3d7fd6; }
/*
   Protection taken off. Amber rather than the violet it used to share with everything
   else: this is the row an auditor is scanning for, and it is the only one here that
   makes a file *less* protected than it was.
*/
.trigger-badge--unmarked { --trigger-color: #b8770f; }

.empty-state {
	display: flex;
	flex-direction: column;
	align-items: center;
	text-align: center;
	gap: 6px;
	padding: 48px 24px;
}
.empty-icon {
	width: 40px;
	height: 40px;
	margin-bottom: 2px;
	fill: var(--color-text-maxcontrast);
	opacity: 0.6;
}
.empty-title {
	margin: 0;
	font-weight: 600;
}
.empty-sub {
	margin: 0;
	max-width: 320px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.pagination-bar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	flex-wrap: wrap;
	margin-top: 14px;
}
.page-size {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}
.page-size-select {
	height: 36px;
	padding: 0 8px;
	border: 1px solid var(--color-border-maxcontrast, #949494);
	border-radius: var(--border-radius, 6px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 13px;
	cursor: pointer;
}
.page-nav {
	display: flex;
	align-items: center;
	gap: 10px;
}
.page-range {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
}
</style>
