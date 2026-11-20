# Browser sign-in with Keycloak

Start with the [local API and Swagger UI](../README.md#quick-start). This walkthrough adds a Keycloak realm and signs a user into Swagger UI on `http://localhost:8088`. The browser carries the Symfony session cookie through subsequent API requests.

## Start the identity provider

In the project root, create a Git-ignored `.env.auth.local` file with two different randomly generated passwords. Use `openssl rand -hex 24` for each value. Keep the database password for subsequent starts of the same volume.

```dotenv
KEYCLOAK_DB_PASSWORD=<local database password>
KEYCLOAK_ADMIN_PASSWORD=<local bootstrap admin password>
```

```sh
docker compose --env-file .env.auth.local -f compose.yml -f compose.auth.yml -f compose.auth.bootstrap.yml up -d keycloak
```

Open the admin console and sign in as `admin` with `KEYCLOAK_ADMIN_PASSWORD`. The bootstrap overlay creates this account on first initialization. The Compose network already resolves `auth.eventflow.localhost`. If your browser cannot resolve it, add `127.0.0.1 auth.eventflow.localhost` to the host machine's hosts file. [Local Keycloak console](http://auth.eventflow.localhost:8081/admin/)

## Create a realm, client, and user

1. Create the **eventflow** realm and select it. Under **Clients**, create an OpenID Connect client named **eventflow-bff**. Turn on **Client authentication** and **Standard flow**. The API uses the authorization code flow with PKCE S256.
2. Set **Valid redirect URIs** to `http://localhost:8088/api/v1/auth/callback` and **Valid post logout redirect URIs** to `http://localhost:8088/api/v1/auth/logged-out`. Save, then copy the client secret from **Credentials**.
3. Under **Realm roles**, create `eventflow-admin` and `eventflow-viewer`. For this walkthrough, create a local user, set a password in **Credentials**, and assign `eventflow-admin` through **Role mapping**.
4. Include the realm roles in the **ID token**. For example, under the client's **Client scopes**, open its dedicated scope and add a **User Realm Role** mapper. Set **Token Claim Name** to `realm_access.roles`, **Claim JSON Type** to `String`, and enable **Multivalued** and **Add to ID token**. EventFlow reads this claim during the callback. [Token verification](../src/Security/OidcIdTokenVerifier.php) · [Keycloak administration guide](https://www.keycloak.org/docs/latest/server_admin/)

Add these settings to the API's `.env.local`, using the client secret from step 2. The browser uses the public issuer name. The API uses the Compose service name for the token exchange and signing keys because HTTP clients can resolve `*.localhost` to their own container's loopback.

```dotenv
OIDC_ISSUER=http://auth.eventflow.localhost:8081/realms/eventflow
OIDC_BACKCHANNEL_BASE_URL=http://keycloak:8081/realms/eventflow
OIDC_CLIENT_ID=eventflow-bff
OIDC_CLIENT_SECRET=<secret from Keycloak>
OIDC_REDIRECT_URI=http://localhost:8088/api/v1/auth/callback
OIDC_POST_LOGOUT_REDIRECT_URI=http://localhost:8088/api/v1/auth/logged-out
```

## Sign in and create an organization

1. In a browser, open the login URL and sign in as the realm user. The callback redirects to `/` on the Swagger origin. Open the current-user endpoint in the same browser and check for your username and `ROLE_EVENTFLOW_ADMIN`. [Start login](http://localhost:8088/api/v1/auth/login) · [Current user](http://localhost:8088/api/v1/auth/me)
2. Clear any saved `legacyBearer` value in Swagger UI's **Authorize** dialog. Fetch `GET /api/v1/auth/csrf` and copy the `management_csrf_token` value. [Session CSRF tokens](http://localhost:8088/api/v1/auth/csrf)
3. In Swagger UI, call `POST /api/v1/organizations` with header `X-CSRF-Token` set to that value and body `{"slug":"warehouse-demo","name":"Warehouse Demo"}`. Use a fresh slug for each new organization. A `201` response includes the organization ID. The creator receives an admin membership.
4. Use that ID as `X-Organization-Id` on `GET /api/v1/events`. For event or webhook writes, also send the CSRF header. You can find the ID again with `GET /api/v1/organizations`. [Route permissions and request bodies](../openapi/openapi.yaml)

To stop the optional identity provider and its database, run the following command from the project root.

```sh
docker compose --env-file .env.auth.local -f compose.yml -f compose.auth.yml -f compose.auth.bootstrap.yml stop keycloak keycloak-db
```
