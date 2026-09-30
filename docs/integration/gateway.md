# TMC application gateway — interface specification

**Requirements:** R-4.4-1, R-4.4-2, R-4.4-3 (front ends for TMC applications, approved endpoints only,
no patient data on the website), R-4.12-1, R-4.12-2, R-4.12-3 (secure endpoints, authentication, rate
control, logging, payment gateway hand-off), R-4.8-1 (network segregation, integration part),
R-4.3-6 / R-4.3-7 / R-4.12-4 (location maps, social media).

**Code:** `src/mu-plugins/tmc-core/apps-gateway.php` (protection, the call, REST interface),
`apps-registry.php` (catalogue and registry), `apps-validation.php` (input), `apps-responses.php`
(response allow-lists), `rate-limit.php` (shared rate limiter), `apps-admin.php` (registry screen),
`src/themes/tmc/inc/apps-blocks.php` (front ends), `inc/location-map.php`, `inc/social.php`,
`mock/tmc-apps/router.php` (DEMO backend). **Tests:** `scripts/tests/apps-test.php`,
`scripts/smoke.d/apps.sh`, `mock/tmc-apps/contract-test.php`.

---

## 1. Principle

Tata Memorial Centre builds and runs the application backends (appointments, examination results,
online forms, payments, EMR). The website provides **only the presentation layer**. It talks to a
backend only through a registered, TMC-approved endpoint, only server-side, and it **stores nothing a
visitor submits**.

```
 Visitor's browser                 Website (WordPress)                         TMC backends
 ─────────────────                 ───────────────────                         ────────────
 form on a page  ── HTTPS ──►  /wp-json/tmc/v1/apps/<service>/<action>
                               1. service + action on the allow-list?
                               2. rate limit (per IP, per service)
                               3. writes: page token + same origin
                               4. validate + sanitise against the schema
                               5. add API key from the environment  ── integration network ──►  approved endpoint
                               6. rebuild the answer from allow-listed fields  ◄──────────────  JSON
                               7. audit entry (metadata only)
  result  ◄─────────────────  own wording; never upstream hosts, paths or errors
```

### Network zones (docker-compose.yml)

| Network | Members | Internet | Purpose |
|---|---|---|---|
| `tmc_internal` | db, redis, wordpress, wpcli, cron | no (`internal: true`) | database and cache — nothing else can reach them |
| `tmc_edge` | wordpress, wpcli | yes | updates, language packs, reverse proxy |
| `tmc_apps` | wordpress, wpcli, *tmc-apps-mock* | no (`internal: true`) | **integration zone**: WordPress ↔ TMC application backends only |

The browser can never reach a backend directly: backends are not published and are not on the edge
network. In production the `tmc_apps` network (or the equivalent firewall zone at TMC) carries only the
connections to the approved endpoints; the demo mock is removed.

---

## 2. Public interface (browser → website)

### 2.1 Route

```
GET|POST /wp-json/tmc/v1/apps/<service>/<action>
```

- `<service>` — name in the registry (`[a-z0-9_]{1,32}`), e.g. `appointments`.
- `<action>` — an action of that service's type (section 3), e.g. `slots`.
- Each action has exactly one method. Reads that carry personal data (results lookup) use `POST` so that
  values never appear in URLs or access logs.

### 2.2 Request

| Item | GET actions | POST actions |
|---|---|---|
| Input | query string | JSON body (`Content-Type: application/json`) or form-encoded |
| Page token | — | header `X-TMC-Token` (or field `_tmc_token`) — rendered into every form |
| Origin | — | a browser `Origin` header, when present, must be this website |
| `lang` (optional) | `hi…` switches messages to Hindi when translations exist | same |
| `page` (donation only) | — | ID of the page holding the Donate block (return address) |

Unknown input fields are ignored — they are never forwarded.

### 2.3 Response envelope

```json
{
  "ok": true,
  "code": "ok",
  "message": "Your appointment request has been sent.",
  "errors": {},
  "data": { "reference": "DEMO-APT-1A2B3C4D" },
  "request_id": "7f0c…-uuid",
  "html": "<div class=\"tmc-app-message is-success\">…</div>"
}
```

- `data` contains only the allow-listed fields of section 3.
- `errors` maps field names to messages (our wording) for `422`.
- `html` (successful calls only) is the result panel rendered and escaped by the theme.
- `request_id` is also sent to the backend (`X-Request-Id`) and written to the audit log, so a support
  query can be traced end to end without logging any submitted values.
- Headers: `Cache-Control: no-store, private`, `X-Robots-Tag: noindex`; `Retry-After: 60` on `429`;
  `Allow` on `405`.

### 2.4 Status codes

| HTTP | `code` | When |
|---|---|---|
| 200 | `ok` | success |
| 403 | `forbidden` | write without a valid page token, or cross-site `Origin` |
| 404 | `unknown` | service not registered / disabled, action not allowed, or server-only action — one identical message for all, so the allow-list cannot be probed |
| 404 | `not_found` | backend reports no matching record (e.g. results lookup) — message defined per action |
| 405 | `method` | wrong HTTP method for a known action |
| 409 | `conflict` | backend reports a conflict (e.g. appointment time already taken) — message per action |
| 422 | `invalid` | input failed validation; `errors` lists each field |
| 422 | `rejected` | backend rejected the input (400/422); fields it names are marked, backend text is never shown |
| 429 | `rate` | per-IP limit reached for this service |
| 503 | `unavailable` | backend unreachable, timed out, answered 5xx/401/403, sent an invalid response, or no API key is configured |
| 503 | `busy` | backend answered 429 |

---

## 3. Action catalogue

The catalogue (`tmc_apps_catalogue()`) fixes each action's method, upstream path and input schema. The
registry chooses which actions are enabled for each service. Field types: `text`, `name` (letters,
spaces, `.'-`), `textarea`, `email`, `mobile` (Indian 10-digit; `+91`/`0`/spaces removed), `tel`, `date`
(`YYYY-MM-DD`, with range rules), `code` (`[A-Za-z0-9_-]{1,64}`), `token` (`[A-Za-z0-9_-]{8,64}`), `enum`,
`consent` (must be ticked), `bool`, `amount` (whole rupees), `integer`, `url`.

### appointments

| Action | Method | Upstream | Input | Public response |
|---|---|---|---|---|
| `departments` | GET | `GET {base}/departments` | — | `departments[] {code, name}` (cached 10 min) |
| `slots` | GET | `GET {base}/slots?department=&date=` | `department` code*, `date`* (tomorrow … +90 days) | `slots[] {id, time, available}` |
| `request` | POST | `POST {base}/requests` | `department`*, `date`*, `slot`*, `patient_type`* (`new`/`registered`), `registration_no` (≤20, `[A-Z0-9/-]`), `patient_name`* (name ≤100), `mobile`*, `email`, `consent`* | `reference` |

### results

| Action | Method | Upstream | Input | Public response |
|---|---|---|---|---|
| `lookup` | POST | `POST {base}/lookup` | `roll_number`* (`[A-Z0-9-]{3,20}`), `date_of_birth`* (past) | `roll_number, candidate_name, examination, result, published_on` |

### forms (generic online forms)

| Action | Method | Upstream | Input | Public response |
|---|---|---|---|---|
| `schema` | GET | `GET {base}/{form}/schema` | `form`* | `id, title, description, fields[]` (cached 5 min) |
| `submit` | POST | `POST {base}/{form}/submissions` | `form`* + the fields of the backend's schema | `reference` |

The backend describes its form; the website renders and validates it. Schema format (anything else is
dropped): `fields[] {name [a-z][a-z0-9_]{0,31}, label, type: text|textarea|email|tel|number|date|select|radio|checkbox|url, required, max_length, help, autocomplete, options[] {value, label}, min, max}`; at most 40 fields,
names `form`, `page`, `lang`, `return_url` and `tmc_*` are reserved.

### payments (donations)

| Action | Method | Upstream | Input | Public response |
|---|---|---|---|---|
| `initiate` | POST | `POST {base}/orders` | `amount`* (1 – 10,00,000), `donor_name`*, `email`*, `mobile`*, `pan` (`ABCDE1234F`), `address` (≤300), `consent`*; **`return_url` is added by the website** | `order_id, redirect_url` |
| `status` | GET | `GET {base}/orders/{order_id}` | `order_id`* | `order_id, status (created|pending|paid|failed|cancelled), amount, currency, transaction_ref` |
| `checkout` | — | `GET {base}/orders/{order_id}/checkout` | server only, DEMO backends only | used by the demo gateway page |
| `simulate` | — | `POST {base}/orders/{order_id}/simulate` | server only, DEMO backends only | used by the demo gateway page |

Donation flow: the Donate block sends the amount and donor details → `initiate` → the website checks that
`redirect_url` points to a host on the service's **redirect allow-list** (HTTPS required; `self` = this
website, used only by the demo) and sends the visitor there → the payment gateway returns the visitor to
`/<donate page>/?tmc_donation=return&order=<id>` → the page calls `status` server-side and shows the
**verified** result. The website never sees card or bank details and never trusts the return URL alone.

---

## 4. Backend contract (website → TMC endpoint)

What a TMC backend must provide to be registered:

- **Transport:** HTTPS on the integration network (HTTP is accepted only for the demo mock).
- **Authentication:** header `X-API-Key: <key>`; the website also sends `X-Request-Id: <uuid>`,
  `Accept: application/json`, `User-Agent: TMC-Website-Gateway/1.0`. No cookies, no visitor IP.
- **Format:** JSON in, JSON out; UTF-8. The paths, fields and meanings in section 3.
- **Status codes:** `2xx` success; `404` no record; `409` conflict; `400`/`422` invalid input with an
  optional `fields` object naming the rejected fields; `429` busy; anything else = failure. Error bodies
  may contain diagnostics — the website discards them.
- **Redirects:** none (the website does not follow redirects from a backend).
- **Timeout:** the registry timeout (default 8 s, maximum 30 s).

---

## 5. Registry and secrets

**Network Admin → Settings → TMC applications** (Super Admins, `manage_network_options`). One entry per
service; stored network-wide in the site option `tmc_apps_registry`:

| Field | Meaning |
|---|---|
| name | public route segment, `[a-z][a-z0-9_]{1,31}` |
| type | `appointments`, `results`, `forms` or `payments` (selects the catalogue) |
| base URL | internal endpoint, `http(s)://host[:port]/path` — no credentials, query or fragment; never shown to visitors |
| allowed actions | subset of the type's actions |
| timeout | 1–30 s |
| rate limit | 1–600 requests per minute per visitor IP |
| API key variable | environment variable holding the key, `TMC_APP_<NAME>_KEY` (default `TMC_APP_<SERVICE>_KEY`) |
| redirect hosts | payments only: host names of the approved payment page |
| enabled / DEMO | switch the service off; DEMO labels the forms and allows demo-only actions |

**API keys are never stored in the database or shown in the admin screen** — only whether the variable
is present. Every registry change is written to the audit log (`app_registry_changed`, with the names of
the changed fields).

### How TMC registers a real endpoint

1. TMC approves the endpoint and issues an API key for the website.
2. Put the key in the server's `.env`, e.g. `TMC_APP_APPOINTMENTS_KEY=…` (chmod 600, never committed),
   and add the line to the `x-wp-env` block of `docker-compose.yml` if the service name is new
   (the four standard names are already there). Recreate the WordPress container.
3. Open the integration network/firewall from the WordPress container to the endpoint only.
4. In **Network Admin → Settings → TMC applications**: set the base URL, tick the allowed actions,
   set timeout/rate limit (payments: the gateway's host under redirect hosts), untick DEMO, save.
5. Check the overview shows "present in environment" for the key; submit a test through the page;
   check **Network Admin → Audit Log** for `app_gateway_call` entries with `outcome: ok`.
6. Remove the `tmc-apps-mock` service and `TMC_APPS_MOCK_KEY` from production, and set `TMC_DEMO=0`.

A new *type* of service (a new action set) is a code change to `tmc_apps_catalogue()` with its schema,
response allow-list and tests — it cannot be created from the admin screen.

---

## 6. Protection

| Control | Implementation |
|---|---|
| Allow-list | registry × catalogue; everything else → the same `404 unknown` |
| Input validation | per-field type, length, pattern, range; unknown fields dropped |
| Output allow-list | responses rebuilt field by field; upstream text never passed on |
| No upstream exposure | base URLs only in the network option; no host/path/error in any response or page (tested) |
| CSRF | page token (HMAC of site + 12-hour period with the WordPress nonce salt, valid 12–24 h; the same for every visitor so pages stay cacheable) + same-origin check on every write |
| Rate limit | per service, per visitor IP, per minute; unknown services share a separate limit (30/min) |
| Secrets | API keys from environment variables only |
| Redirects | payment redirects only to allow-listed HTTPS hosts; backends' own redirects are not followed |

---

## 7. Logging

Each gateway call adds one entry to the tamper-evident audit log (**Network Admin → Audit Log**, event
`app_gateway_call`) and to the container log:

`service, action, channel (rest | form | server), status, upstream_status, latency_ms, outcome, request_id`
— plus, for validation failures, the *names* of the invalid fields.

Outcomes: `ok`, `refused_unknown`, `refused_method`, `refused_token`, `rate_limited` (first refusal per
window only), `invalid_input`, `not_configured`, `schema_unavailable`, `missing_server_field`,
`upstream_not_found`, `upstream_conflict`, `upstream_rejected`, `upstream_busy`, `upstream_unreachable`,
`upstream_auth_failed`, `upstream_error`, `upstream_bad_response`.

**Never logged:** submitted values, names, phone numbers, e-mail addresses, dates of birth, roll numbers,
amounts, upstream bodies, API keys. The client IP is recorded by the audit log as for every other event.

## 8. What the website stores

Nothing that a visitor submits (tested: a unique marker submitted through every form is found in no
table). The only gateway data the website keeps:

- the registry (network option) — addresses and settings, no keys;
- short caches of non-personal reference data — department list (10 min), form schemas (5 min);
- rate-limit counters — keyed by an HMAC of the IP, never the IP itself, expiring within a minute.

---

## 9. Demo backend (`tmc-apps-mock`) — DEMO ONLY

A PHP built-in-server mock (`mock/tmc-apps/router.php`, image `php:8.3.35-cli-alpine`, read-only, user
`nobody`) on the `tmc_apps` network, answering only with `X-API-Key = $TMC_APPS_MOCK_KEY`. All data is
made up:

| Try | Result |
|---|---|
| Appointments: any listed department, a date Monday–Saturday | 8 times, some already booked |
| Results: roll number `DEMO1001`, date of birth `15/01/2000` (or `DEMO1002`, `30/07/1999`) | a result; anything else → "no result found" |
| Online form: `feedback` | website feedback form |
| Donate: any amount | demo gateway page `/?tmc_demo_checkout=<order>` (labelled "Demonstration only", no card details) → simulate success / failure / cancel → verified status on return |
| Appointment with patient name `Demo Server Error` | simulated backend fault (the page shows only a generic message) |

Environment switches (written by `scripts/make-env.sh` for local/CI/UAT, added to older `.env` files by
`scripts/setup.sh`):

- `TMC_DEMO=1` — demonstration environment: migration 040 registers the four mock services (once, only if
  the registry was never saved), forms show "Demo backend", and the footer shows demo social links.
- `TMC_APPS_MOCK_KEY` — key shared by the mock and the four `TMC_APP_*_KEY` variables in demo.

## 10. Location map

Block **Location map** (`tmc/location-map`), placed on every Contact Us page: address as text, **Get
directions** (openstreetmap.org, opens a new tab), and a map area that loads the OpenStreetMap embed
**only after the visitor presses "Show map"** — no request goes to a third party before that. Without
JavaScript, "View on OpenStreetMap" opens the map instead. The iframe has a descriptive title.

Coordinates per site: **Appearance → Customize → TMC contact details** (latitude, longitude, zoom,
"approximate" note), overridable per block. Seeded values are **city-level** (Mumbai, Visakhapatnam,
Varanasi, Muzaffarpur, Chandigarh tricity) and labelled "approximate location (city level)" until TMC
provides exact positions. If a Content-Security-Policy is added, allow `frame-src https://www.openstreetmap.org`.

## 11. Social media

- **Follow us** (footer): per-site URLs in **Appearance → Customize → TMC contact details** (Facebook, X,
  YouTube, Instagram, LinkedIn). **Empty by default — TMC provides the official accounts.** Only in demo
  environments (`TMC_DEMO=1`) and only while no URL is set, the footer shows the platforms' home pages with
  the visible note "Demo links: the official Tata Memorial Centre accounts will be added by TMC". Setting
  any URL, or `TMC_DEMO=0`, removes the demo links; nothing is stored for the demo.
  CLI: `wp --url=<site> theme mod set tmc_facebook https://www.facebook.com/<account>`.
- **Share this page** on news/notices, events and tenders: plain links to each platform's share page,
  e-mail, and "Copy link" — no third-party scripts, nothing loaded until the visitor clicks.

## 12. Notes for operations

- The REST namespace `tmc/v1/apps` must stay reachable anonymously if REST access is restricted later.
- Full-page caches must not keep pages longer than 12 hours (the page token rotates every 12 hours) and
  must bypass `POST` requests and URLs with `tmc_donation`, `order` or `tmc_demo_checkout`.
- The gateway's audit entries grow with traffic; retention follows the audit-log policy.
