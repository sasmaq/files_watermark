// Minimal stub of @nextcloud/auth. The uid is settable so a spec can put the current
// user on either side of a file's ownership without building a session.
let currentUser = { uid: 'alice', displayName: 'Alice' }

export const getCurrentUser = () => currentUser

/**
 * Test seam: set the acting user, or null to simulate a public page.
 * @param {object|null} user - the user object to report, or null
 */
export const __setCurrentUser = (user) => {
	currentUser = user
}
