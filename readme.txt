=== Site Agent MCP Lite ===
Contributors: laurentrochetta
Donate link: https://www.patreon.com/c/yeswebuild
Tags: mcp, ai, agents, oauth, seo
Requires at least: 6.9
Tested up to: 7.1
Stable tag: 0.9.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect AI apps to WordPress over MCP and OAuth 2.1. You choose who connects and how far: read-only, content or full. Off until you switch it on.

== Description ==

Site Agent MCP Lite turns your site into a remote MCP (Model Context Protocol) server for the AI applications you use, such as Claude, ChatGPT, Codex, Gemini CLI or Antigravity. You open new connections in WordPress, add the site's address in your AI application, sign in and choose on a consent screen what the app may do. There is no password, application password or API key to copy.

There is no service in between: the plugin is its own OAuth server, on your site. Nothing goes through the author's servers, and no outside platform holds access to your site.

= You stay in control =

* **Off until you switch it on.** A fresh install accepts no connection. You switch AI agents on in Site Agent > Access, and you can switch them off at any time: every connection stops at once.
* **Who may connect, and how far.** For each role, and for specific accounts, you set the highest level: no access, read only, content (posts, pages, media, terms, comments and their SEO fields) or everything the account can do. By default, administrators can give full access, editors content access, and other roles nothing. Changes reach existing connections at once.
* **A link alone never connects an app.** New connections are closed until a person opens them for themselves in WordPress, for 15 minutes and one approval. Each new connection sends an email to the account and to the site administrator.
* **Read-only first.** The consent screen proposes read-only access; the person may choose more, never above what you allowed. It shows the app's full return address and the account it will act as.
* **Lines agents never cross.** Whatever the level, an agent never edits plugin or theme files, never installs an uploaded package, never creates accounts or changes roles, never changes an account's password, e-mail or login, never creates application passwords, never changes which plugins or theme run except through WordPress's own installer, never changes the WordPress schedule, and never changes the settings or data of Site Agent MCP. These rules are enforced inside WordPress (capabilities, option writes and the upgrader) for every request made by or for an agent, not by listing screens.
* **Plugins and themes only if you allow it.** Installing from wordpress.org, updating, switching and removing plugins and themes is off until an administrator allows it in Site Agent > Access.
* **Your permissions, checked on every call.** The agent acts as the user who connected it, within the level of the connection. Tokens are bound to this site's MCP endpoint, stored only as hashes, last one hour, and a connection ends after 180 days at most. Changing the account's password, removing the account or deactivating the plugin ends its connections.
* **Everything is logged.** Each tool call is listed in Site Agent > Activity, by account and application. Option, SEO field and site CSS changes are journaled and can be undone.

= What agents can do =

* **Security first.** When an administrator connects, agents are steered to a read-only security audit and report it before other work.
* **SEO and GEO with evidence, not invented scores.** Page audits, a site crawl, AI crawler access, structured data checks, Search Console data, PageSpeed Insights, Chrome UX Report and GTmetrix. Agents are told never to invent an E-E-A-T score, authors, reviews or figures, and never to promise rankings.
* **Content and settings, within the level.** The REST API of WordPress and of your plugins, WordPress abilities, plugin settings through options and admin screens, the site's Additional CSS, and the SEO fields of Yoast SEO, Rank Math, SEOPress and The SEO Framework.
* **Backups.** Full backups of every database table and every file, in short resumable steps that suit shared hosting. Agents can create and verify backups; you download or delete them in Site Agent > Backups.
* **12 languages.** The admin screens and the consent screen follow the WordPress language: English, French, Spanish, German, Italian, Brazilian Portuguese, Dutch, Arabic, Hebrew, Thai, Hindi and Simplified Chinese.

Agents work through typed tools, the REST API and abilities, and can read admin screens and fill admin forms within the level; this edition has no tool that sends arbitrary admin requests.

An agent can still make mistakes with the rights you give it. Keep backups and use a staging copy for large changes. Content read from the site can contain instructions aimed at an AI (prompt injection): results are marked as untrusted, but this cannot fully prevent an agent from following them. Read-only and content levels limit what such an instruction could do.

== External services ==

This plugin contacts outside services only in the cases below. Nothing is sent in the background, and nothing is sent to the plugin's author.

**AI application metadata** (connection). When a person who is signed in starts a connection from an AI application that identifies itself with an address (Client ID Metadata Document), the plugin reads that small JSON document from the address the application gave, to learn its name and return addresses. Nothing about your site is sent.

**Google PageSpeed Insights** (tool performance_test). Sent: the page address to test, the strategy (mobile or desktop) and, if you saved one, your Google API key. Sent to pagespeedonline.googleapis.com when an agent runs a test. The address can be any public page.
Terms: https://developers.google.com/terms and https://policies.google.com/terms . Privacy: https://policies.google.com/privacy

**Chrome UX Report** (tool crux_query). Sent: a page address or origin, the device type and your Google API key. Sent to chromeuxreport.googleapis.com when an agent asks for real-user metrics.
Terms: https://developers.google.com/terms . Privacy: https://policies.google.com/privacy

**Google Search Console and Google sign-in** (tools gsc_performance, gsc_inspect_url, gsc_sitemaps, gsc_sitemaps_list, gsc_sites). Only after an administrator enters their own Google Cloud client and connects a Google account in Site Agent > Services. The connection asks Google for read and write access to Search Console, because gsc_sitemaps can submit a sitemap; tools only use the property of this site. Sent: the OAuth client ID and secret and authorization codes or tokens to accounts.google.com and oauth2.googleapis.com; the site address, date ranges, query filters, page addresses and sitemap addresses to www.googleapis.com and searchconsole.googleapis.com when an agent reads search data, inspects a URL or submits a sitemap. Disconnecting deletes the token on this site and asks Google to revoke it; the screen says so when Google does not confirm.
Terms: https://developers.google.com/terms and https://policies.google.com/terms . Privacy: https://policies.google.com/privacy

**GTmetrix** (tools gtmetrix_test and gtmetrix_result). Only with a GTmetrix API key saved by an administrator. Sent: the address of a page of this site and the API key, to gtmetrix.com, when an agent starts or reads a test. Each test spends credits of your GTmetrix account.
Terms: https://gtmetrix.com/terms-of-service.html . Privacy: https://gtmetrix.com/privacy-policy.html

**IndexNow** (tool indexnow_submit). Sent: the site's host name, the addresses of changed pages of this site, and an IndexNow key that the plugin creates and serves at the site root, to api.indexnow.org, which shares them with the participating search engines (Bing, Yandex, Naver, Seznam, Yep, Amazon).
Terms: https://www.indexnow.org/terms . Privacy of Microsoft Bing, which runs api.indexnow.org: https://privacy.microsoft.com/privacystatement

**GitHub** (new version notice, off by default). Only if an administrator ticks "Tell me when a new version of Site Agent MCP Lite is published" in Site Agent > Settings. Sent: one request for the latest release of the public repository of this plugin, to api.github.com, twice a day at most, with WordPress's usual User-Agent (WordPress version and site address). Nothing is downloaded or installed.
Terms: https://docs.github.com/site-policy/github-terms/github-terms-of-service . Privacy: https://docs.github.com/site-policy/privacy-policies/github-general-privacy-statement

**WordPress.org** (tools plugin_describe and extension_manage). Sent: a plugin or theme slug, to api.wordpress.org, when an agent reads a plugin's public documentation or, if you allowed it, installs or updates a plugin or theme; the package is then downloaded from downloads.wordpress.org, as WordPress itself does.
Privacy: https://wordpress.org/about/privacy/

Your AI application (Claude, ChatGPT, Codex, Gemini or another MCP client) receives what its agent reads from your site, under that application's own terms. The plugin sends it nothing on its own: the application asks, within the level you approved.

**Your own site's .htaccess** (only if you click it). When the site's server does not pass the Authorization header to WordPress (Apache with PHP over FastCGI, on some shared hosting), Site Agent > Overview shows the problem and offers a button that adds four lines at the top of .htaccess. A copy of the file is kept first, the change is checked at once and undone if it does not help, and it can be removed from Site Agent > Settings or by uninstalling. Nothing is sent anywhere: the check is a request from the site to itself.

== Installation ==

1. Install and activate the plugin from Plugins > Add New. Your site needs HTTPS and pretty permalinks.
2. Open Site Agent > Access. Switch AI agents on, and check which roles and accounts may connect, and how far.
3. Open Site Agent > Connect, click "Allow a new connection", and copy the MCP address or the ready-made command for your AI application.
4. Add it in the application within 15 minutes. It opens a WordPress page: sign in, check the app name, its return address and the account, choose the level, and choose Allow access.
5. Optional: in Site Agent > Services, add a Google API key (PageSpeed Insights and Chrome UX Report), a Search Console connection or a GTmetrix key. Each step links to the right page and has a test button.

Other accounts allowed in Site Agent > Access connect from Profile > AI connections. Administrators see and revoke every connection in Site Agent > Connect.

== Frequently Asked Questions ==

= Does an outside service get access to my site? =

No. The plugin is its own OAuth server on your site, and there is no service of the author in between. Each AI application you connect gets its own token, after a person approves it on the consent screen, within the level you allow. You can see and revoke every connection, or switch all of them off at once.

= Can the agent do more than my account? =

No. Every call is checked against your WordPress capabilities, within the level of the connection, and some actions are refused whatever the account can do: editing code files, installing uploaded packages, creating accounts or changing roles, creating application passwords, and changing the settings of Site Agent MCP. A token only works on the MCP endpoint.

= Can someone connect an app by sending me a link? =

No. New connections are closed until a person opens them in WordPress, for 15 minutes, and each new connection is announced by email.

= Which AI applications work? =

Any MCP client that supports OAuth: Claude (web, desktop and Claude Code), ChatGPT, Codex, Gemini CLI and Antigravity are known. For another client, add its exact return address in Site Agent > Settings > Advanced.

= Does it give agents scores for SEO or E-E-A-T? =

No. Tools return evidence, such as tags, headings, structured data and Search Console data, and say whether each rule comes from Google or is a convention. Agents are told not to invent scores.

= How do I restore a backup? =

Download its three parts (database, files and manifest) in Site Agent > Backups and restore them with your host's tools.

= Is there another edition? =

Site Agent MCP Full, distributed from https://mcp.wordpress-custom.com/ , adds tools that work at server level for administrators who choose them. Install one edition only: they share their settings and cannot run together.

= Does the plugin work on multisite? =

Multisite networks are not supported yet. Each site of a network keeps its own connections, settings and data. Deactivating the plugin for the whole network ends the connections of every site, and deleting it removes its data from every site of the network. On a network, only super admins can change options directly or create backups through the plugin.

== Screenshots ==

1. The consent screen: the app, its full return address, the account it will act as, and the level to give.
2. Site Agent > Access: switch agents on or off, and set the highest level per role and per account.
3. Site Agent > Connect: open new connections for 15 minutes, the address and the command for each AI application, and the active connections with their level.
4. Site Agent > Overview, with the connections and the latest security audit.
5. Site Agent > Backups: create, verify, download and delete backups.
6. Site Agent > Activity: every tool call, by user and application.

== Changelog ==

= 0.9.1 =
* On a multisite network, deleting the plugin now removes its tables, settings and scheduled tasks from every site, not only the main one. Multisite is still not supported otherwise.
* Every tool checks the arguments an AI app sends before doing anything: a missing value, a wrong type or a value outside the allowed list is refused with a message that says what to send instead.
* The Search Console and PageSpeed connection through Site Kit by Google is now tested on this edition too.
* Tested with the keyboard only: switching agents on, approving a connection and disconnecting it, with the focus order, the confirmation dialog and the names screen readers announce.

= 0.9.0 =
* About 80 fixes from an independent audit of 0.8.1, covered by new checks in the test bench.
* Consent screen: read only stays preselected; the other levels you allow remain one click away.
* Revoking a connection can no longer be outrun by a refresh made at the same moment.
* A read-only connection can no longer start a paid GTmetrix test: starting a test needs write access, and reading its result is a separate tool.
* Undo no longer overwrites a change a person made afterwards: such items are skipped and reported. Two undos at the same moment apply once.
* New version notice, off by default: tick it in Site Agent > Settings to be told when a new Lite version is published on GitHub. The check runs in the background, twice a day at most.
* Search Console and PageSpeed can use the Google connection of Site Kit by Google when it is set up.
* Tool calls are limited per connection, GPS data is removed from JPEG images agents upload, and backups refuse a folder that visitors can open or flag a copy that could not be read in full.
* Admin screens read through the site's own address now check its TLS certificate. A site whose own address uses a self-signed certificate can turn the check off with the samcp_loopback_sslverify filter: add_filter( 'samcp_loopback_sslverify', '__return_false' ).
* Multisite is not supported yet: this readme no longer says it is. Deactivating the plugin for a whole network now ends the connections of every site.
* A Patreon link, in the plugin credits and the readme, for those who want to support the project.

= 0.8.1 =
* Security fixes from an independent deep review of 0.8.0: a token now works on the MCP route only, whatever route a request names; the agent rules start as soon as the token is read; an agent can never change an account's password, e-mail or login; every option and cache of Site Agent MCP is out of agents' reach whatever the spelling of its name; the schedule (cron) is out of reach without server-level access.
* New connections open per person, for one approval. Accounts that lose access are refused, not disconnected, so giving the access back restores their connections. A connection now really ends 180 days after it was approved.
* Updating from an earlier version keeps full access for every account that already had a connection, and says so once in the admin.
* The Authorization header check gives no verdict when the site cannot call itself, is checked again every day and on activation, writes .htaccess in one step, keeps a single backup copy, and can be undone from Settings. It is offered on Apache and LiteSpeed only, and on multisite to super admins only.
* Pages read "as the user" stay on the public site; secrets are cut out of text instead of hiding the whole text.

= 0.8.0 =
* Security release. Every request made by or for an agent now follows one set of rules enforced inside WordPress: no file editing, no uploaded packages, no account or role changes, no application passwords, no change to which plugins or theme run except through the installer, and no change to the settings of Site Agent MCP.
* New Access tab: switch agents on or off, and set the highest level (none, read only, content, everything the account can do) per role and per account. A fresh install starts switched off.
* New connections open only for 15 minutes after a person allows them in WordPress, and each one sends an email.
* The consent screen proposes read-only first and shows the full return address.
* Installing, updating, switching and removing plugins and themes by agents is off until an administrator allows it.
* Secret values are hidden in REST and ability results; REST and ability changes to options are journaled; undo never overwrites a value changed again since.
* Search Console tools only use the property of this site. Connections end after 180 days at most and when the plugin is deactivated.
* On multisite, only super admins can create and download backups.

= 0.7.0 =
* Interface fully translated again in 11 languages.

= 0.6.0 =
* SEO fields (title, description, canonical, noindex) now also work with The SEO Framework.
* Hardened after a full WordPress Plugin Check review: escaped messages and prepared database queries throughout.
* Uninstalling one edition keeps the data the other edition still uses.

= 0.5.0 =
* First version of Site Agent MCP Lite.
* OAuth 2.1 connections, REST, abilities, options and admin form tools, SEO and GEO tools, Search Console, read-only security audit, backups and a change journal with undo.
