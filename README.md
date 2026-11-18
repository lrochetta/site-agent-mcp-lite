# Site Agent MCP Lite

Connect AI apps (Claude, ChatGPT, Codex, Gemini CLI, Antigravity and other MCP clients) to a WordPress site over MCP and OAuth 2.1. You choose who connects and how far: read-only, content or full. Off until you switch it on.

- Website, documentation and video course: https://mcp.wordpress-custom.com/
- Plugin description, FAQ and changelog: [readme.txt](readme.txt)

## What this repository is

The source of the Lite edition, exactly as shipped in each release ZIP. It is published from the main source tree at every release: one commit and one tag (`vX.Y.Z`) per version. The release page of each tag carries the ZIP and its SHA-256.

The plugin runs entirely on your site. It is its own OAuth server; nothing goes through the author's servers.

## Install

1. Download `site-agent-mcp-lite-X.Y.Z.zip` from the [latest release](../../releases/latest).
2. In WordPress, go to Plugins > Add New > Upload Plugin and upload the ZIP.
3. Activate it, then open Site Agent > Access to switch AI agents on.

Requires WordPress 6.9 or later and PHP 7.4 or later.

## Lite and Full

Site Agent MCP Full adds server-level tools (files, SQL, PHP, WP-CLI, offered per connection and off by default), backup restore from the admin screen, site import, and updates from mcp.wordpress-custom.com. It is available from https://mcp.wordpress-custom.com/.

## Support

The plugin is free and stays free. To support its development and get advanced courses, tips and early versions, join the Yes Web Build community on Patreon: https://www.patreon.com/c/yeswebuild

## Issues and security

Report bugs and suggestions in [Issues](../../issues). Do not post security problems publicly: use GitHub's private vulnerability reporting (Security > Report a vulnerability).

## License

Copyright (C) 2026 Laurent Rochetta.

This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later version. See [LICENSE](LICENSE).

"Site Agent MCP" is the name of the author's project. Forks are welcome under the license, under another name.
