# Authenticating requests

To authenticate requests, include an **`Authorization`** header with the value **`"Bearer your-token"`**.

All authenticated endpoints are marked with a `requires authentication` badge in the documentation below.

Az API Sanctum SPA **cookie-alapú** session hitelesítést használ (nem Bearer token). A "Try it out" funkcióhoz először hívd meg a `GET /sanctum/csrf-cookie` végpontot, majd `POST /login`-nal jelentkezz be.
