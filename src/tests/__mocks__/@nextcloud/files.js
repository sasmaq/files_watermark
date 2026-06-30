// Minimal stub of @nextcloud/files for unit tests. registerFileAction is a
// no-op recorder; FileAction just stores the config it was given.
export const registerFileAction = jest.fn()

export class FileAction {

	constructor(config) {
		Object.assign(this, config)
	}

}
