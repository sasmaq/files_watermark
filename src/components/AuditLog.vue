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
									<span class="user-cell">
										<span class="avatar" aria-hidden="true">{{ row.initial }}</span>
										<span class="user-name">{{ row.userId }}</span>
									</span>
								</td>
								<td>
									<span class="file-cell" :title="row.filePath">
										<svg class="file-icon" viewBox="0 0 24 24" aria-hidden="true">
											<path d="M13,9V3.5L18.5,9M6,2C4.89,2 4,2.89 4,4V20A2,2 0 0,0 6,22H18A2,2 0 0,0 20,20V8L14,2H6Z" />
										</svg>
										<span class="file-path">{{ row.filePath }}</span>
									</span>
								</td>
								<td>
									<span class="trigger-badge" :class="'trigger-badge--' + row.trigger">
										<span class="trigger-dot" aria-hidden="true" />
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
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'

const entries = ref([])
const loading = ref(false)
const error = ref(null)
const limit = ref(50)
const offset = ref(0)

const TRIGGER_LABELS = {
	on_demand: t('files_watermark', 'On demand'),
	on_download: t('files_watermark', 'On download'),
	on_share: t('files_watermark', 'On share'),
	on_upload: t('files_watermark', 'On upload'),
}

// Precompute display-friendly fields so the template stays declarative.
const rows = computed(() => entries.value.map((e) => ({
	id: e.id,
	userId: e.userId,
	filePath: e.filePath,
	trigger: e.trigger,
	initial: (e.userId || '?').charAt(0).toUpperCase(),
	date: (e.createdAt || '').split(' ')[0] || (e.createdAt || ''),
	time: (e.createdAt || '').split(' ')[1] || '',
	label: TRIGGER_LABELS[e.trigger] ?? e.trigger,
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
	text-align: left;
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

.user-cell {
	display: inline-flex;
	align-items: center;
	gap: 8px;
}
.avatar {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	flex: none;
	width: 26px;
	height: 26px;
	border-radius: 50%;
	background: color-mix(in srgb, var(--color-primary-element) 15%, transparent);
	color: var(--color-primary-element);
	font-size: 12px;
	font-weight: 700;
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

.trigger-badge {
	display: inline-flex;
	align-items: center;
	gap: 7px;
	padding: 3px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill, 16px);
	background: var(--color-main-background);
	font-size: 12px;
	font-weight: 500;
	white-space: nowrap;
}
.trigger-dot {
	flex: none;
	width: 7px;
	height: 7px;
	border-radius: 50%;
	background: var(--color-text-maxcontrast);
}
.trigger-badge--on_download .trigger-dot { background: #4a90d9; }
.trigger-badge--on_share .trigger-dot { background: #a05fd6; }
.trigger-badge--on_upload .trigger-dot { background: #45ad66; }

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
