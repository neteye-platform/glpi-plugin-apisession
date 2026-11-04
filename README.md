# API Session

[![GLPI](https://img.shields.io/badge/GLPI-%3E%3D%2011.0.0-blue.svg)](https://glpi-project.org/)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](./LICENSE-MIT)
[![License: Apache 2.0](https://img.shields.io/badge/License-Apache%202.0-blue.svg)](./LICENSE-APACHE)

A [GLPI](https://glpi-project.org/) plugin that gives **read-only** access to the
GLPI API v2 (High-Level API) with the **GLPI web session** of the user, i.e. with
its session cookie, instead of an OAuth access token.

## Introduction

The GLPI API v2 authenticates its clients with OAuth 2.0. A web page that runs on
the same origin as GLPI, and whose user is already logged in to GLPI, would need
to register an OAuth client, ship its credentials to the browser and run the
authorization code flow just to read data that the user can already see in GLPI.

The API v2 does accept the session cookie, but only on a few routes of its own
(`/`, `/doc`, `/authorize`), and a plugin cannot add an authentication middleware
to it. This plugin exposes the `GET` routes of the API v2 under its own path,
authenticated by the GLPI web session only.

## How it works

The plugin declares two routes, served by the GLPI kernel like any other GLPI page:
GLPI starts the session from the cookie of the request.

| Route | Answer |
| --- | --- |
| `GET /plugins/apisession/v2{path}` | The answer of `GET /api.php/v2{path}` of the GLPI API v2, query string included, computed as the user of the session. `401` without a session. Assets (`/Assets/...`) and any other information of the API v2 can be read. |
| `GET /plugins/apisession/session` | An empty HTML page, `401` without a session. It is the landing page of a login started by another page, e.g. in a hidden iframe with `/?redirect=<url of this page>`. |

`/plugins/apisession/v2{path}` runs the router of the API v2 in the same request
and authenticates it with the GLPI internal authentication
(`Glpi\Api\HL\Middleware\InternalAuthMiddleware`), which accepts the user of the
current session.

Example, from a page of the same origin:

```js
const response = await fetch('/glpi/plugins/apisession/v2/Assets/Computer?filter='
    + encodeURIComponent('name=="my-host"'));
```

## Security

- **Read-only**: only `GET` is accepted. The other methods are refused: GLPI itself
  answers `403` to a `POST` without a CSRF token before the plugin runs, and the
  plugin answers `405` to anything else that is not `GET`. The endpoint never writes,
  so it is not exposed to CSRF.
- **Not every path**: `/authorize` and `/token` of the API v2 are not proxied (`403`).
  With a logged in user, `GET /authorize?...&accept=1` grants an OAuth authorization
  to a client, which a link on another site must not be able to trigger.
- **Same origin**: the plugin sends no CORS header, so the pages of other origins
  cannot read its answers.
- **Same rights as the GLPI UI**: the API v2 applies the profile and the entities
  that are active in the web session of the user, for assets as well as for any other
  information of the API v2. The `Authorization`, `GLPI-Profile`, `GLPI-Entity`,
  `GLPI-Entity-Recursive` and `GLPI-API-Version` headers are dropped, so a request
  cannot pick another identity, profile or entity.
- **Only complete logins**: a user who has not finished the two-factor authentication
  has no session user yet and gets `401`; a user who must change the password gets
  `403`.
- **Not cacheable**: every answer has `Cache-Control: private, no-store` and
  `Vary: Cookie`, because it depends on the user of the session.
- **Independent from the API v2 setting**: the internal authentication is the one
  that GLPI keeps when the API v2 is disabled (`enable_hlapi`), so the plugin works
  without enabling the API v2 for OAuth clients.

## Requirements

- **GLPI >= 11.0.0**

## Installation

1. Clone (or download and extract) this repository into the `plugins`
   directory of your GLPI installation, in a folder named `apisession`:

   ```sh
   cd /var/www/glpi/plugins
   git clone \
     https://github.com/neteye-platform/glpi-plugin-apisession \
     apisession
   ```

2. In GLPI, go to **Setup > Plugins**.
3. Locate **API Session** and click **Install**, then **Enable**.

The plugin requires no additional configuration or database tables.

## Tests

The tests run in the GLPI plugin CI (`.github/workflows/tests.yaml`). To run them
locally, inside the `plugins/apisession` folder of a GLPI 11 development
installation with the plugin installed and enabled in the test database:

```sh
../../vendor/bin/phpunit
```

## License

This plugin is distributed under the terms of both the MIT license and the
Apache License (Version 2.0). You may use it under either license, at your option.

- [Apache License, Version 2.0](./LICENSE-APACHE)
- [MIT License](./LICENSE-MIT)

## Contributing

- Open an issue for each bug or feature so it can be discussed first.
- Work on a dedicated branch on your own fork.
- Open a pull request that will be reviewed by a maintainer.
