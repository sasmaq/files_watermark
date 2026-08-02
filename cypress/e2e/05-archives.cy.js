/**
 * Folder and multi-file (ZIP) downloads.
 *
 * Core streams archive members straight from `$node->fopen('rb')`, so before
 * `ZipInterceptorPlugin` existed a folder download was a way to fetch every file on
 * the server unwatermarked, in every mode. Nothing about the archive looks wrong when
 * that happens — it is a valid zip of valid files — so every assertion here unpacks
 * the archive and probes the *members*.
 *
 * The single-file-share case is the one that matters most and is the least obvious: a
 * received single-file share is mounted inside the recipient's own home, so the
 * containing folder is not shared storage. Gating on the container reported "owner
 * access" and shipped the clean original, while the very same file downloaded
 * directly came back watermarked. Folder shares hide it; only "download selected" on
 * a received single file exposes it.
 */

const folder = 'e2e-archives'
const recipientUid = 'e2e-zip-recipient'
const zipHeaders = { Accept: 'application/zip' }

/** The archive's members, keyed by name, each probed as a PDF. */
const probeArchive = (base64) =>
	cy.task('probe:zip', { base64 }).then((members) => {
		const named = {}
		return Cypress.Promise.each(members, (member) =>
			cy.task('probe:pdf', { base64: member.base64 }).then((pdf) => {
				named[member.name] = { ...pdf, size: member.size }
			}),
		).then(() => named)
	})

describe('Archive (ZIP) downloads', () => {
	let recipient
	let link

	before(() => {
		cy.ncLogin()
		cy.wmFolder(folder)
		cy.wmUser(recipientUid).then((credentials) => {
			recipient = credentials
		})

		cy.task('fixture:pdf', { pages: 1, text: 'member one' })
			.then((base64) => cy.wmUpload(`${folder}/one.pdf`, base64))
		cy.task('fixture:pdf', { pages: 2, text: 'member two' })
			.then((base64) => cy.wmUpload(`${folder}/two.pdf`, base64))
		// Not a watermarkable type: it must travel through the rebuilt archive
		// untouched rather than being dropped or denied.
		cy.wmUpload(`${folder}/notes.md`, Cypress.Buffer.from('# notes\n').toString('base64'), {
			contentType: 'text/markdown',
		})

		// A single file shared on its own — the container-gate regression.
		cy.task('fixture:pdf', { pages: 1, text: 'single share' })
			.then((base64) => cy.wmUpload(`${folder}/single.pdf`, base64))

		cy.wmUnshareAll(`/${folder}`)
		cy.wmUnshareAll(`/${folder}/single.pdf`)
		cy.wmShare({ path: `/${folder}`, shareWith: recipientUid })
		cy.wmShare({ path: `/${folder}/single.pdf`, shareWith: recipientUid })
		cy.wmShare({ path: `/${folder}`, shareType: 3, permissions: 1 }).then((share) => {
			link = share
		})
	})

	after(() => {
		cy.wmSetPolicy({ trigger: 'on_demand' })
		cy.wmUnshareAll(`/${folder}`)
		cy.wmUnshareAll(`/${folder}/single.pdf`)
		cy.task('nc:delete', { user: Cypress.env('ncUser'), password: Cypress.env('ncPassword'), path: folder })
	})

	describe('under on_download', () => {
		before(() => {
			cy.wmSetPolicy({ trigger: 'on_download' })
		})

		it("watermarks every supported member of the owner's own folder download", () => {
			cy.task('nc:get', {
				url: `/remote.php/dav/files/${Cypress.env('ncUser')}/${folder}?accept=zip`,
				user: Cypress.env('ncUser'),
				password: Cypress.env('ncPassword'),
				headers: zipHeaders,
			}).then((response) => {
				expect(response.status, 'the archive was not served').to.eq(200)

				probeArchive(response.base64).then((members) => {
					const names = Object.keys(members)
					expect(names, 'a member went missing from the archive').to.have.length(4)

					names.filter((name) => name.endsWith('.pdf')).forEach((name) => {
						expect(members[name].watermarked, `${name} is the clean original`).to.be.true
						// tar needs the member size up front and it must be the
						// *watermarked* length; zip records it too, so it is assertable
						// here either way.
						expect(members[name].size, `${name} declares the wrong size`)
							.to.eq(members[name].bytes)
					})
				})
			})
		})

		it('passes an unwatermarkable member through untouched', () => {
			cy.task('nc:get', {
				url: `/remote.php/dav/files/${Cypress.env('ncUser')}/${folder}?accept=zip`,
				user: Cypress.env('ncUser'),
				password: Cypress.env('ncPassword'),
				headers: zipHeaders,
			}).then((response) => {
				cy.task('probe:zip', { base64: response.base64 }).then((members) => {
					const notes = members.find((member) => member.name.endsWith('notes.md'))
					expect(notes, 'the unsupported member was dropped').to.not.be.undefined
					expect(Cypress.Buffer.from(notes.base64, 'base64').toString()).to.eq('# notes\n')
				})
			})
		})

		it('watermarks a multi-file selection, which core streams the same way', () => {
			cy.task('nc:get', {
				url: `/remote.php/dav/files/${Cypress.env('ncUser')}/${folder}`
					+ `?accept=zip&files=${encodeURIComponent('["one.pdf","two.pdf"]')}`,
				user: Cypress.env('ncUser'),
				password: Cypress.env('ncPassword'),
				headers: zipHeaders,
			}).then((response) => {
				probeArchive(response.base64).then((members) => {
					expect(Object.keys(members), 'the selection is not what was asked for')
						.to.have.length(2)
					Object.entries(members).forEach(([name, pdf]) => {
						expect(pdf.watermarked, `${name} is the clean original`).to.be.true
					})
				})
			})
		})
	})

	describe('under on_share', () => {
		before(() => {
			cy.wmSetPolicy({ trigger: 'on_share' })
		})

		it("watermarks the members of a recipient's folder download", () => {
			cy.task('nc:get', {
				url: `/remote.php/dav/files/${recipientUid}/${folder}?accept=zip`,
				user: recipient.user,
				password: recipient.password,
				headers: zipHeaders,
			}).then((response) => {
				expect(response.status).to.eq(200)
				probeArchive(response.base64).then((members) => {
					Object.entries(members)
						.filter(([name]) => name.endsWith('.pdf'))
						.forEach(([name, pdf]) => {
							expect(pdf.watermarked, `${name} reached the recipient clean`).to.be.true
						})
				})
			})
		})

		it("leaves the owner's own folder download untouched", () => {
			cy.task('nc:get', {
				url: `/remote.php/dav/files/${Cypress.env('ncUser')}/${folder}?accept=zip`,
				user: Cypress.env('ncUser'),
				password: Cypress.env('ncPassword'),
				headers: zipHeaders,
			}).then((response) => {
				probeArchive(response.base64).then((members) => {
					Object.entries(members)
						.filter(([name]) => name.endsWith('.pdf'))
						.forEach(([name, pdf]) => {
							expect(pdf.watermarked, `the owner's own archive stamped ${name}`).to.be.false
						})
				})
			})
		})

		it('watermarks a selection made on a received single-file share', () => {
			// The regression. The file is mounted at the recipient's root, so the
			// container here is the recipient's own home — not shared storage.
			cy.task('nc:get', {
				url: `/remote.php/dav/files/${recipientUid}`
					+ `?accept=zip&files=${encodeURIComponent('["single.pdf"]')}`,
				user: recipient.user,
				password: recipient.password,
				headers: zipHeaders,
			}).then((response) => {
				expect(response.status).to.eq(200)
				probeArchive(response.base64).then((members) => {
					const names = Object.keys(members)
					expect(names, 'the selection did not contain the shared file').to.have.length(1)
					expect(members[names[0]].watermarked,
						'"download selected" on a received single-file share served the clean original')
						.to.be.true
				})
			})
		})

		/**
		 * The archive's audit granularity, decided and pinned: **one `watermark_log` row
		 * per watermarked member**, written per fetch.
		 *
		 * A row per archive was the alternative and is strictly less useful — it could say
		 * that someone downloaded an archive but not which documents were in it, which is
		 * the question the audit trail exists to answer. Rows are keyed by file id, which
		 * is also what the Files-list indicator and the double-burn guard read.
		 *
		 * The cost is real and deliberate: delivery triggers render per fetch, so a second
		 * download of the same folder writes the same rows again. That is asserted rather
		 * than glossed over — it is the same behaviour a single-file `on_download` has, so
		 * the archive path is not a special case, and the volume is bounded by the archive
		 * caps (200 members by default, `archive_max_members`).
		 */
		it('records nothing for a delivery unless the policy asks for it', () => {
			// The shipped default. Delivery triggers render per fetch, so recording them
			// is what grows the log without bound — an archive of 200 members downloaded
			// twice a day is 400 rows a day, forever.
			cy.wmSetPolicy({ trigger: 'on_share', logDelivery: false })

			cy.wmApi('GET', '/api/v1/log?limit=500').then((before) => {
				const seen = new Set(before.body.map((entry) => entry.id))

				cy.task('nc:get', {
					url: `/remote.php/dav/files/${recipientUid}/${folder}?accept=zip`,
					user: recipient.user,
					password: recipient.password,
					headers: zipHeaders,
				}).then((response) => {
					// The watermark still happens — the switch governs the record, never
					// the file.
					expect(response.status).to.eq(200)
					probeArchive(response.base64).then((members) => {
						Object.entries(members)
							.filter(([name]) => name.endsWith('.pdf'))
							.forEach(([name, pdf]) => {
								expect(pdf.watermarked, `${name} was not watermarked`).to.be.true
							})
					})
				})

				cy.wmApi('GET', '/api/v1/log?limit=500').then((after) => {
					const added = after.body.filter((entry) => !seen.has(entry.id))
					expect(added, 'a delivery was recorded with logging off').to.be.empty
				})
			})
		})

		it('writes one audit row per watermarked member, per fetch', () => {
			cy.wmSetPolicy({ trigger: 'on_share', logDelivery: true })

			// Rows are identified by **id**, not counted. The log endpoint returns the
			// newest N, which on an instance the suite has been run against a few times
			// is a sliding window: three new rows push three old ones out of view, so a
			// difference in length measures nothing.
			const rowsForThisFolder = () =>
				cy.wmApi('GET', '/api/v1/log?limit=500').then((response) =>
					response.body.filter((entry) => String(entry.filePath).includes(`/${folder}/`)))

			const fetchRecipientArchive = () =>
				cy.task('nc:get', {
					url: `/remote.php/dav/files/${recipientUid}/${folder}?accept=zip`,
					user: recipient.user,
					password: recipient.password,
					headers: zipHeaders,
				}).its('status').should('eq', 200)

			const addedSince = (seen) => (rows) =>
				rows
					.filter((entry) => !seen.has(entry.id))
					.map((entry) => `${entry.trigger} ${entry.filePath.split('/').pop()}`)
					.sort()

			// Three PDFs in the folder; notes.md is not a watermarkable type and must
			// leave no row behind, or the log would claim a watermark that is not in the
			// file.
			const expected = ['on_share one.pdf', 'on_share single.pdf', 'on_share two.pdf']

			rowsForThisFolder().then((before) => {
				const seen = new Set(before.map((entry) => entry.id))

				fetchRecipientArchive()
				rowsForThisFolder().then(addedSince(seen)).should('deep.eq', expected)

				// And again: rendering per fetch means logging per fetch.
				rowsForThisFolder().then((afterFirst) => {
					const seenNow = new Set(afterFirst.map((entry) => entry.id))

					fetchRecipientArchive()
					rowsForThisFolder().then(addedSince(seenNow)).should('deep.eq', expected)
				})
			})
		})

		it('watermarks a public-link folder download', () => {
			// The trailing slash is required: the public DAV server's base URI *is*
			// `/public.php/dav/files/<token>/`, and a request without it is rejected
			// as out of base before any plugin sees it.
			cy.task('nc:get', {
				url: `/public.php/dav/files/${link.token}/?accept=zip`,
				headers: zipHeaders,
			}).then((response) => {
				expect(response.status).to.eq(200)
				probeArchive(response.base64).then((members) => {
					Object.entries(members)
						.filter(([name]) => name.endsWith('.pdf'))
						.forEach(([name, pdf]) => {
							expect(pdf.watermarked, `${name} reached the link visitor clean`).to.be.true
						})
				})
			})
		})
	})
})
