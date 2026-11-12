<div align="center">

<img src="docs/icon.svg" width="88" alt="">

# [nt] Server Online

A XenForo 2.3 widget with the player count of your game servers: Counter-Strike, Minecraft, SA-MP, MTA, FiveM and any project with a JSON API.

[![XenForo](https://img.shields.io/badge/XenForo-2.3%2B-2577b1?style=flat-square)](https://xenforo.com)
[![Release](https://img.shields.io/github/v/release/mityakuss-pixel/nt-server-online?style=flat-square&label=Release&color=2577b1)](https://github.com/mityakuss-pixel/nt-server-online/releases/latest)
[![Downloads](https://img.shields.io/github/downloads/mityakuss-pixel/nt-server-online/total?style=flat-square&label=Downloads&color=2577b1)](https://github.com/mityakuss-pixel/nt-server-online/releases)
[![License](https://img.shields.io/github/license/mityakuss-pixel/nt-server-online?style=flat-square&label=License&color=2577b1)](LICENSE)

[Русский](README.md) · **English**

<picture>
	<source media="(prefers-color-scheme: dark)" srcset="docs/wide-dark.png">
	<img src="docs/wide-light.png" width="840" alt="The widget with four servers of different games above the forum list">
</picture>

</div>

## Features

- **Queries servers directly.** Choose the game and enter the address; the add-on asks the server itself for the player count, the limit and the name.
- **Reads a JSON API** when the project has its own status address. This is how RAGE MP and any other servers are connected.
- **Several servers in one widget**, up to 20, of different games if needed, with an optional total under the list.
- **Status indicator**, player limit and a fill bar.
- **Server address with a copy button.**
- **A link button** under the list, for example to a "How to start playing" page.
- **Any widget position.** Servers are stacked in the sidebar and placed in a row in wide positions.
- **No load on pages.** Servers are queried in the background once a minute, and the widget reads ready data from the cache.

### Supported games

| Type in the options | What it covers | Default port |
|---|---|---|
| Counter-Strike, Source | CS 1.6, CS2 and other Source and GoldSrc games | 27015 |
| Minecraft Java | Java Edition servers, SRV records included | 25565 |
| Minecraft Bedrock | Bedrock Edition servers | 19132 |
| SA-MP, CR-MP, open.mp | Servers that speak the SA-MP query protocol | 7777 |
| Multi Theft Auto | MTA: San Andreas servers | 22003 |
| FiveM, RedM | Servers on the Cfx.re platform | 30120 |
| JSON API | Any address that returns the server status as JSON | |

RAGE Multiplayer has no open query protocol, so such servers are connected through the project's JSON API.

<div align="center">
<picture>
	<source media="(prefers-color-scheme: dark)" srcset="docs/sidebar-dark.png">
	<img src="docs/sidebar-light.png" width="266" alt="The widget in the sidebar">
</picture>
</div>

## Requirements

- XenForo 2.3.0 or later.
- The forum server must be able to reach the game server: game protocols need outgoing UDP and TCP connections, a JSON API needs outgoing HTTP.

## Installation

1. Download `NT-ServerOnline-1.0.1.zip` from the [releases page](https://github.com/mityakuss-pixel/nt-server-online/releases/latest).
2. In the admin control panel open **Add-ons → Install/upgrade from archive** and choose the archive.

A Russian translation is available as `language-Russian-(RU)-NT-ServerOnline.zip`. Unpack it and import the file via **Appearance → Languages → Import language** over your Russian language.

## Configuration

1. **Appearance → Widgets → Add widget**, definition **Game server online**.
2. Set the widget key, the title and the positions.
3. In **Servers** choose the game and enter the server address as `host:port`, for example `play.example.com:27015`. The default port can be left out.
4. The name is optional: when it is empty, the name reported by the server is used.
5. Save. The add-on queries the servers right away.

The form is not saved when an address is malformed. If a server simply did not respond, the widget is saved and shows it as offline.

The refresh interval and the request timeout are in **Setup → Options → [nt] Server Online**, the indicator and fill bar colors are in **Appearance → Style properties → [nt] Server Online**.

### Servers with a JSON API

Choose the **JSON API** type and paste the address that returns the server status. The default field names match a response like this:

```json
{
	"displayName": "Downtown",
	"address": "downtown.example.com:22005",
	"state": "online",
	"players": 742,
	"maxPlayers": 1000
}
```

If your API names the fields differently, change them in the widget options. These fields do not affect servers of other types.

| Option | Default | What it holds |
|---|---|---|
| Player count field | `players` | A number, or a list of players whose length is used. Required |
| Player limit field | `maxPlayers` | Without it there is no limit and no fill bar |
| Server name field | `displayName` | Used when the name is not entered by hand |
| Server address field | `address` | The string players connect to |
| Status field | `state` | May be left empty |
| Status value that means online | `online` | Several values can be separated with commas |

Nested fields are written with dots: `stats.players`. If one address returns several servers at once, add a row for each of them and enter its path in the response, for example `data.servers.0` and `data.servers.1`.

<details>
<summary>Widget options form</summary>
<br>
<img src="docs/options.png" width="760" alt="Widget options in the admin control panel">
</details>

## License

[MIT](LICENSE)
