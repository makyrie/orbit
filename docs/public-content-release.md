# Public content releases

Orbit owns the database-backed bodies for `/why/`, `/contact/`, `/privacy/`, and `/terms/`. The Perihelion theme owns the homepage, anonymous navigation, public templates, and search/share metadata. This split keeps presentation in the theme while allowing normal plugin upgrades to publish canonical WordPress pages without moving a database.

## Ownership and collision safety

`Orbit_Activator::create_pages()` marks each managed page with `_orbit_code_owned_page`. A release updates the title, body, and publication state only when that marker matches the expected page kind. The canonical policy pages also retain `_orbit_canonical_compliance` and their option-backed page IDs for consent records. Orbit records `orbit_content_version` only after every owned page and its required metadata verify successfully, independently of the database-schema version.

The production Why essay predates ownership metadata. Orbit recognizes that one legacy body by two distinctive phrases, adopts it once, and stamps the normal marker. Any other page occupying a managed slug is left untouched and logged for manual reconciliation.

## Release order

1. Deploy the Orbit plugin release. Its version-aware upgrade creates or updates owned pages and registers the legacy policy redirects.
2. Confirm `/sign-up/`, `/why/`, `/contact/`, `/privacy/`, and `/terms/` resolve while logged out.
3. Deploy the matching Perihelion theme release, then verify homepage CTAs, navigation, and page metadata.

Re-running the plugin upgrade is safe and does not duplicate pages. Do not export or import the WordPress database for a public-content release; users, subscriptions, activities, consent records, and unrelated settings are outside this mechanism.

## Policy changes

Privacy and Terms prose is duplicated deliberately between `docs/compliance/` and the Gutenberg block markup in `Orbit_Activator`. Update both copies, bump `ORBIT_VERSION`, and run `composer policy-diff` before opening a PR.
