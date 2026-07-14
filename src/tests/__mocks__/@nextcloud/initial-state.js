// Mutable in-memory stand-in for Nextcloud's initial-state store. Tests set values
// with __setState and reset between cases with __resetState.
let state = {}

export const loadState = (app, key, fallback) => {
	const k = `${app}:${key}`
	if (k in state) {
		return state[k]
	}
	if (fallback !== undefined) {
		return fallback
	}
	throw new Error(`Could not find initial state ${key} of ${app}`)
}

export const __setState = (app, key, value) => {
	state[`${app}:${key}`] = value
}

export const __resetState = () => {
	state = {}
}
