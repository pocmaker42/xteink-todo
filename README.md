# XTeInk Todo List

E-ink todo list for the **XTeInk X4**, managed from a simple web UI.

## Features

- **Web UI** to add / check / edit / delete tasks
- **Auto sync**: the device fetches the list on each wake (~10 min)
- **Manual fetch** with the CONFIRM button
- **Pagination** with LEFT/RIGHT when there are more than 10 tasks
- Optional API token (firmware + server)

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                         PHP server                               │
│  ┌──────────────┐     ┌──────────────────┐   ┌───────────────┐ │
│  │  index.php   │────▶│   todos.php      │──▶│ data/todos.json│ │
│  │  (web UI)    │     │   (CRUD API)     │   │  (storage)     │ │
│  └──────────────┘     └─────────┬────────┘   └───────────────┘ │
│                                 │                                │
└─────────────────────────────────┼────────────────────────────────┘
                                  │ HTTPS GET
┌─────────────────────────────────┴────────────────────────────────┐
│                       XTeInk X4                                   │
│  Buttons ──▶ ESP32-C3 + WiFi ──▶ E-ink display (TODO list)       │
└──────────────────────────────────────────────────────────────────┘
```

## Setup

### 1. Prerequisites

```bash
pip3 install platformio
git submodule update --init --recursive
```

### 2. Configure secrets

**Firmware** — copy the example, then set WiFi + API URL:

```bash
cp firmware/include/secrets.h.example firmware/include/secrets.h
```

```cpp
#define WIFI_SSID "YOUR_SSID"
#define WIFI_PASSWORD "YOUR_PASSWORD"
#define API_TODOS "https://example.com/xteink/todos.php"
#define API_TOKEN ""   // optional, must match server/config.php
```

**Server** — same idea:

```bash
cp server/config.example.php server/config.php
```

```php
return [
    'base_url' => 'https://example.com/xteink',
    'api_token' => '', // e.g. 'change-me' to protect the API
];
```

> `secrets.h`, `config.php`, and `data/todos.json` are gitignored.

### 3. Deploy the server

Upload the `server/` folder to your PHP host:

- `index.php` — web UI (`?demo=1` = fake list)
- `todos.php` — API
- `bootstrap.php` + `config.php`
- writable `data/` folder

```bash
chmod 775 data
chmod 666 data/todos.json   # once created
```

API smoke test:
```bash
curl https://example.com/xteink/todos.php
# with token:
curl -H "X-Api-Token: change-me" https://example.com/xteink/todos.php
```

### 4. Flash the firmware

```bash
cd firmware
pio run -t upload
```

## Usage

### Web UI

1. Open `index.php` on your server
2. Add tasks
3. Check / edit (click the text) / delete
4. The XTeInk updates on the next fetch (or CONFIRM)

Demo mode (screenshots): `index.php?demo=1`

### Device buttons

| Button | Action |
|--------|--------|
| **LEFT / RIGHT** | Previous / next page |
| **BACK** | Jump back to page 1 |
| **CONFIRM** | Force refresh from the API |
| **POWER** | Sleep |

## Layout

```
xteink-todo/
├── firmware/
│   ├── include/secrets.h.example
│   ├── platformio.ini
│   └── src/main.cpp
├── server/
│   ├── bootstrap.php
│   ├── config.example.php
│   ├── index.php
│   ├── todos.php
│   └── data/.gitkeep
├── open-x4-sdk/            # submodule
└── README.md
```

## API (`todos.php`)

**GET** — list for the device / UI:
```json
{
  "todos": [{"id":"…","text":"…","done":false,"created_at":"…"}],
  "total": 2,
  "pending": 2,
  "updated_at": "…"
}
```

**POST** (JSON) — actions:
- `{ "action": "add", "text": "…" }`
- `{ "action": "toggle", "id": "…" }`
- `{ "action": "update", "id": "…", "text": "…" }`
- `{ "action": "delete", "id": "…" }`
- `{ "action": "clear_done" }`
- `{ "action": "reorder", "ids": ["…","…"] }`

Optional auth: `X-Api-Token` header or `?token=…` query param.

## License

MIT
