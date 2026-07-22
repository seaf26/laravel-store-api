# API Contract Changes: Reliability Hardening

Unlisted success responses remain unchanged.

## Throttled authentication operations

Applies to:

- `POST /api/auth/login`
- `POST /api/auth/register`
- `POST /api/auth/verify-phone/request`
- `POST /api/auth/verify-phone`
- `POST /api/auth/password/forgot`
- `POST /api/auth/password/reset`

When the relevant source or phone bucket is exhausted:

```http
HTTP/1.1 429 Too Many Requests
Content-Type: application/json
Retry-After: <seconds>
X-RateLimit-Limit: <limit>
X-RateLimit-Remaining: 0

{"message":"Too many attempts. Please try again later."}
```

Known and unknown phone numbers use the same response body and headers for public issue
operations.

## Order idempotency

### Matching replay

`POST /api/orders` with an existing key and equivalent normalized items:

```http
HTTP/1.1 200 OK
Idempotency-Replayed: true
Content-Type: application/json

{"data": {"id": "<original order>", "status": "<current status>", "items": []}}
```

### Conflicting replay

`POST /api/orders` with an existing key and different normalized items:

```http
HTTP/1.1 409 Conflict
Content-Type: application/json

{"message":"The idempotency key was already used with a different request."}
```

No order, order item, stock, or idempotency data changes on the conflict.

### Invalid key length

An `Idempotency-Key` longer than 64 characters returns the existing validation envelope:

```http
HTTP/1.1 422 Unprocessable Content
Content-Type: application/json

{"message":"The idempotency key must not be greater than 64 characters.","errors":{"Idempotency-Key":["The idempotency key must not be greater than 64 characters."]}}
```

## Order status changes

The existing endpoint and response shapes remain unchanged. Status validation is applied
to the latest committed order state. A duplicate current status remains `200` with
`{"message":"Status unchanged.", ...}`; an invalid transition remains `422`.
