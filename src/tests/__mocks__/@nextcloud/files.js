// Minimal stub of @nextcloud/files for unit tests. registerFileAction and
// registerDavProperty are no-op recorders; FileAction just stores the config
// it was given.
export const registerFileAction = jest.fn()

export const registerDavProperty = jest.fn()

export class FileAction {

	constructor(config) {
		Object.assign(this, config)
	}

}
